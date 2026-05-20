<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Plugin;

use ETechFlow\NextDayEligibility\Model\BackorderChecker;
use ETechFlow\NextDayEligibility\Model\Config;
use ETechFlow\NextDayEligibility\Model\IneligibilityChecker;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use Psr\Log\LoggerInterface;

/**
 * Filters shipping rates based on two independent rules:
 *  1. Next-day eligibility (existing) — removes next-day methods when any cart
 *     item is not flagged `next_day_eligible = 1`.
 *  2. Backorder express restriction (new in v1.1.0, folded in from the
 *     deprecated BackorderShippingRestrictor module) — removes a separate
 *     configured set of express methods when any cart item is on backorder.
 *
 * Both rules are independently configurable; both can be active simultaneously
 * with different method-code lists.
 */
class ShippingRestriction
{
    /**
     * Constructor.
     *
     * @param Config               $config
     * @param IneligibilityChecker $ineligibilityChecker
     * @param BackorderChecker     $backorderChecker
     * @param LoggerInterface      $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly IneligibilityChecker $ineligibilityChecker,
        private readonly BackorderChecker $backorderChecker,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Filter the grouped shipping rates returned by Magento's quote address.
     *
     * Wrapped in a try/catch so a bad EAV query or DB hiccup can never crash
     * checkout — the original rates are returned unchanged in that case.
     *
     * @param Address $subject
     * @param array   $result  Rates grouped by carrier: ['carrier' => [Rate, ...]]
     * @return array
     */
    public function afterGetGroupedAllShippingRates(Address $subject, array $result): array
    {
        try {
            if (!$this->config->isEnabled() || empty($result)) {
                return $result;
            }

            $items = $subject->getAllItems();
            if (empty($items) && $subject->getQuote() !== null) {
                $items = $subject->getQuote()->getAllItems();
            }

            $hasIneligible = $this->ineligibilityChecker->hasIneligibleItems($items);

            // v1.5.1: Click & Collect filter — independent of next-day rules.
            // Only consulted when the C&C method list is populated (i.e. the
            // merchant has physical shops). Empty list = skip the local-stock
            // check entirely, saves the DB roundtrip for online-only stores.
            $ccCodes = $this->config->getClickCollectMethodCodes();
            $ccBlockedByLocalStock = false;
            if (!empty($ccCodes)) {
                $ccBlockedByLocalStock = $this->ineligibilityChecker->hasItemsWithoutLocalStock($items);
            }

            // Whitelist mode (v1.4.3+): if the merchant has populated the
            // Standard Methods list AND the cart has ineligible items, switch
            // from blacklist semantics ("remove next-day codes") to whitelist
            // semantics ("keep ONLY standard codes"). This is the clearer
            // mental model — "these are the methods I want shown on ineligible
            // carts" — and it future-proofs against new carriers being added
            // to the store: a new carrier doesn't accidentally become a
            // next-day option just because the merchant forgot to tick it.
            $standardCodes = $this->config->getStandardMethodCodes();
            if (!empty($standardCodes) && $hasIneligible) {
                $whitelisted = $this->keepOnly($result, $standardCodes);

                // Layer the backorder rule ON TOP of whitelist mode so an
                // express method that happens to be in the standard list
                // still gets removed when backorder items are present.
                if ($this->config->isRestrictExpressOnBackorder()) {
                    $expressCodes = $this->config->getBackorderExpressMethodCodes();
                    if (!empty($expressCodes)
                        && $this->backorderChecker->hasBackorderItems($items)
                    ) {
                        $whitelisted = $this->filterRates($whitelisted, array_unique($expressCodes));
                    }
                }

                // Layer the C&C rule on top too — pickup methods that
                // happen to be in the standard allow list must still drop
                // off when any cart item lacks local stock.
                if ($ccBlockedByLocalStock) {
                    $whitelisted = $this->filterRates($whitelisted, array_unique($ccCodes));
                }

                if (empty($whitelisted)) {
                    $this->logger->warning(
                        'ETechFlow_NextDayEligibility: Whitelist mode left no methods available — '
                        . 'Standard Methods list does not match any of the carrier rates returned. '
                        . 'Returning original rates to keep checkout usable.',
                        ['standard_codes' => $standardCodes]
                    );
                    return $result;
                }

                return $whitelisted;
            }

            // Blacklist mode (default, original v1.0 behaviour):
            $codesToRemove = [];

            // Rule 1: next-day eligibility
            $nextDayCodes = $this->config->getShippingMethodCodes();
            if (!empty($nextDayCodes) && $hasIneligible) {
                $codesToRemove = array_merge($codesToRemove, $nextDayCodes);
            }

            // Rule 2: backorder express restriction
            if ($this->config->isRestrictExpressOnBackorder()) {
                $expressCodes = $this->config->getBackorderExpressMethodCodes();
                if (!empty($expressCodes)
                    && $this->backorderChecker->hasBackorderItems($items)
                ) {
                    $codesToRemove = array_merge($codesToRemove, $expressCodes);
                }
            }

            // Rule 3 (v1.5.1+): Click & Collect — remove pickup methods
            // whenever any cart item has no local stock. Independent of the
            // next-day rules because a product can be next-day-eligible via
            // a drop-ship supplier yet still have no local stock for pickup.
            if ($ccBlockedByLocalStock) {
                $codesToRemove = array_merge($codesToRemove, $ccCodes);
            }

            if (empty($codesToRemove)) {
                return $result;
            }

            $filtered = $this->filterRates($result, array_unique($codesToRemove));

            // Safety net: if filtering left the customer with zero shipping
            // options, abort and return original rates. The merchant has
            // misconfigured method codes (every available method matches the
            // removal list — common trap when a UK store offers only "Free
            // Delivery" and lists that code in the next-day field).
            //
            // Better to ship at the wrong speed than have a stuck checkout
            // where the customer can't complete the order at all. We log a
            // warning so the misconfiguration shows up in var/log/system.log
            // and the merchant can spot + fix it.
            if (empty($filtered)) {
                $this->logger->warning(
                    'ETechFlow_NextDayEligibility: Shipping restriction would leave no methods available — '
                    . 'configured codes likely match every method offered. Returning original rates to keep checkout usable.',
                    ['codes_to_remove' => array_values(array_unique($codesToRemove))]
                );
                return $result;
            }

            return $filtered;
        } catch (\Exception $e) {
            $this->logger->error(
                'ETechFlow_NextDayEligibility: Shipping restriction plugin failed; returning original rates.',
                ['exception' => $e->getMessage()]
            );
            return $result;
        }
    }

    /**
     * Remove the named rate entries from the grouped rates array.
     *
     * @param array    $rateGroups
     * @param string[] $methodCodes Fully qualified codes (carrier_method)
     * @return array
     */
    private function filterRates(array $rateGroups, array $methodCodes): array
    {
        foreach ($rateGroups as $carrierCode => $rates) {
            $filtered = array_values(
                array_filter(
                    $rates,
                    function (Rate $rate) use ($methodCodes): bool {
                        $fullCode = $rate->getCarrier() . '_' . $rate->getMethod();
                        return !in_array($fullCode, $methodCodes, true);
                    }
                )
            );

            if (empty($filtered)) {
                unset($rateGroups[$carrierCode]);
            } else {
                $rateGroups[$carrierCode] = $filtered;
            }
        }

        return $rateGroups;
    }

    /**
     * Whitelist counterpart of filterRates() — keep ONLY rates whose
     * `carrier_method` is in the supplied list, drop everything else.
     * Used by v1.4.3+ Standard Methods whitelist mode.
     *
     * @param array    $rateGroups
     * @param string[] $allowedCodes Fully qualified codes (carrier_method)
     * @return array
     */
    private function keepOnly(array $rateGroups, array $allowedCodes): array
    {
        foreach ($rateGroups as $carrierCode => $rates) {
            $filtered = array_values(
                array_filter(
                    $rates,
                    static function (Rate $rate) use ($allowedCodes): bool {
                        $fullCode = $rate->getCarrier() . '_' . $rate->getMethod();
                        return in_array($fullCode, $allowedCodes, true);
                    }
                )
            );

            if (empty($filtered)) {
                unset($rateGroups[$carrierCode]);
            } else {
                $rateGroups[$carrierCode] = $filtered;
            }
        }

        return $rateGroups;
    }
}
