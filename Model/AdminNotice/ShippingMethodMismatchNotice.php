<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model\AdminNotice;

use ETechFlow\NextDayEligibility\Model\Config;
use ETechFlow\NextDayEligibility\Model\ShippingMethodAvailability;
use Magento\Framework\Notification\MessageInterface;

/**
 * Admin header banner: warns when NDE's configured method codes don't match
 * shipping methods actually enabled on the store (v1.6.2).
 *
 * Renders persistently on every admin page until the merchant fixes the
 * config. Solves the silent-no-op class of misconfig:
 *
 *   Before: merchant configures NDE to restrict tablerate_bestway / ups_01,
 *           neither is enabled on the store, NDE silently restricts nothing,
 *           customer keeps seeing express options on ineligible carts. No
 *           error visible anywhere unless the merchant looks closely.
 *
 *   After:  red banner across every admin page — "NDE has 3 configured
 *           shipping codes that aren't enabled. Open Stores → Configuration
 *           → eTechFlow → Next Day Eligibility to fix."
 *
 * Hidden when the module is disabled OR when all configured codes match
 * (the happy path — no nag for correctly-configured stores).
 */
class ShippingMethodMismatchNotice implements MessageInterface
{
    private const IDENTITY = 'etechflow_nde_method_mismatch_v1';

    public function __construct(
        private readonly Config $config,
        private readonly ShippingMethodAvailability $availability
    ) {
    }

    public function getIdentity(): string
    {
        return self::IDENTITY;
    }

    public function isDisplayed(): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        $any = $this->availability->analyzeAll();
        foreach ($any as $analysis) {
            if (!empty($analysis['unmatched'])) {
                return true;
            }
        }
        return false;
    }

    public function getText(): string
    {
        $any        = $this->availability->analyzeAll();
        $offenders  = [];
        $unmatchAll = [];

        foreach ($any as $type => $analysis) {
            if (empty($analysis['unmatched'])) {
                continue;
            }
            $label = match ($type) {
                ShippingMethodAvailability::TYPE_NEXTDAY       => 'Next Day Methods',
                ShippingMethodAvailability::TYPE_STANDARD      => 'Standard Methods',
                ShippingMethodAvailability::TYPE_CLICK_COLLECT => 'Click & Collect Methods',
                default                                        => $type,
            };
            $offenders[] = sprintf(
                '%s: %s',
                $label,
                implode(', ', $analysis['unmatched'])
            );
            $unmatchAll = array_merge($unmatchAll, $analysis['unmatched']);
        }

        $total = count($unmatchAll);
        $body  = sprintf(
            'ETechFlow Next Day Eligibility — %d configured shipping method code(s) do not match any enabled carrier on this store, so the rule that references them is silently doing nothing. Affected: %s. Fix at Stores → Configuration → eTechFlow → Next Day Eligibility → General Settings, or run <strong>bin/magento etechflow:nde:list-methods</strong> to see all available method codes.',
            $total,
            implode(' | ', $offenders)
        );

        return $body;
    }

    /**
     * MAJOR severity = red banner. Persistent until merchant fixes the config.
     * (Magento's NOTICE/WARNING/MAJOR/CRITICAL ladder — MAJOR is the right
     * call here because it's a silent feature failure, not a system fault.)
     */
    public function getSeverity(): int
    {
        return self::SEVERITY_MAJOR;
    }
}
