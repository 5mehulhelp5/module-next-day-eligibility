<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model\Source;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Shipping\Model\Config\Source\Allmethods;

/**
 * Unified shipping-method source for every NDE multi-select.
 *
 * Magento's stock `Allmethods` source only lists carriers that register
 * through the standard `Magento\Shipping\Model\Carrier\AbstractCarrier`
 * + `carriers/<code>/` config path. Many real-world stores ship via
 * custom modules — Hyvä Shipping Page, marketplace shipping engines,
 * third-party rate aggregators — that register methods at runtime
 * inside `collectRates()`. Those don't appear in Allmethods, which leaves
 * the merchant unable to TICK them in NDE's pickers even though they're
 * real methods that fire at checkout.
 *
 * This source fixes that by merging Allmethods' output with an additional
 * optgroup of "Custom carriers" sourced from every NDE additional-code
 * config field (`additional_method_codes`, `additional_express_codes`,
 * `additional_standard_codes`). Paste a code into ANY of those text inputs
 * and it appears in EVERY multi-select picker. The merchant can then
 * classify each method per-picker (next-day / express / standard) using
 * the same UI as the built-in carriers.
 */
class AllShippingMethods implements OptionSourceInterface
{
    /**
     * NDE config paths to scan for additional / custom method codes.
     */
    private const ADDITIONAL_CODE_PATHS = [
        'etechflow_nextdayeligibility/general/additional_method_codes',
        'etechflow_nextdayeligibility/general/additional_standard_codes',
        'etechflow_nextdayeligibility/backorder_restriction/additional_express_codes',
    ];

    /**
     * @param Allmethods           $allmethods
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly Allmethods $allmethods,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Return Allmethods output + an optgroup of custom codes harvested
     * from NDE's additional-code config fields.
     *
     * @return array<int, array{label: string, value: array<int, array{value: string, label: string}>}>
     */
    public function toOptionArray(): array
    {
        $groups = $this->allmethods->toOptionArray();
        $custom = $this->collectCustomCodes($groups);

        if (!empty($custom)) {
            $groups[] = [
                'label' => (string) __('── Custom / Additional Codes ──'),
                'value' => array_map(
                    static fn(string $code): array => [
                        'value' => $code,
                        'label' => '[' . __('custom') . '] ' . $code,
                    ],
                    $custom
                ),
            ];
        }

        return $groups;
    }

    /**
     * Read every additional-codes config field, dedupe, exclude any code
     * that's already in the Allmethods optgroups (avoid double-listing
     * if a merchant types in a code Magento also exposes natively).
     *
     * @param array $existingGroups Allmethods optgroup output to dedupe against
     * @return string[]
     */
    private function collectCustomCodes(array $existingGroups): array
    {
        $existing = [];
        foreach ($existingGroups as $group) {
            // `Allmethods::toOptionArray()` normally returns an optgroup-shaped array
            // where `value` is itself an array of `[value=>..., label=>...]` entries.
            // In rare edge cases (carriers that expose a single flat method without
            // nested grouping) it returns a flat shape where `value` is a string —
            // skip those gracefully rather than tripping a PHP 8.4 foreach warning.
            $children = $group['value'] ?? [];
            if (!is_array($children)) {
                if (is_string($children) && $children !== '') {
                    $existing[$children] = true;
                }
                continue;
            }
            foreach ($children as $opt) {
                if (isset($opt['value']) && is_string($opt['value'])) {
                    $existing[$opt['value']] = true;
                }
            }
        }

        $codes = [];
        foreach (self::ADDITIONAL_CODE_PATHS as $path) {
            $raw = $this->scopeConfig->getValue($path);
            if (empty($raw)) {
                continue;
            }
            foreach (explode(',', (string) $raw) as $code) {
                $code = trim($code);
                if ($code === '' || isset($existing[$code])) {
                    continue;
                }
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }
}
