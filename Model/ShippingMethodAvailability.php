<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model;

use Magento\Shipping\Model\Config\Source\Allmethods;

/**
 * Detects misconfigurations between NDE's configured method codes and the
 * shipping methods that are actually enabled on the store (v1.6.2).
 *
 * Solves the silent-no-op class of bug:
 *   - Merchant configures NDE to hide `tablerate_bestway` on ineligible carts
 *   - But `tablerate_bestway` was never enabled on this store
 *   - NDE happily runs on every cart, hides nothing, customer sees the
 *     restricted method anyway
 *   - No error, no log, nothing visible — merchant never finds out until
 *     a customer complains
 *
 * This service answers two questions:
 *
 *   1. What method codes are CURRENTLY active on this store?
 *      (`getActiveMethodCodes`)
 *   2. Of the codes the merchant configured for a given NDE rule
 *      (nextday / standard / click-collect), which match an active
 *      method and which are stale references?
 *      (`analyze`)
 *
 * Used by three surfaces:
 *   - `Console\Command\ListMethodsCommand` — shows mismatches in CLI output
 *   - `Model\AdminNotice\ShippingMethodMismatchNotice` — admin header banner
 *   - `Block\Adminhtml\Form\Field\MethodStatusDisplay` — inline status in
 *     the admin config form
 */
class ShippingMethodAvailability
{
    public const TYPE_NEXTDAY       = 'nextday';
    public const TYPE_STANDARD      = 'standard';
    public const TYPE_CLICK_COLLECT = 'click_collect';

    /** Cache the active set for the duration of one PHP request. */
    private ?array $activeCodes = null;

    public function __construct(
        private readonly Allmethods $allmethods,
        private readonly Config $config
    ) {
    }

    /**
     * Set of method codes registered through Magento's Allmethods source.
     *
     * Note: custom shipping modules that register methods at runtime
     * (in collectRates()) don't appear here. The Additional Codes
     * free-text field is the escape hatch for those — and the merchant
     * is responsible for typing those codes correctly. Our mismatch
     * detection is best-effort: we flag codes that aren't in Allmethods,
     * which catches the most common misconfig (carrier disabled, code
     * typo'd from old install) without false-positiving on custom
     * runtime carriers.
     *
     * @return array<string, true>  code => true map for O(1) membership
     */
    public function getActiveMethodCodes(): array
    {
        if ($this->activeCodes !== null) {
            return $this->activeCodes;
        }

        $codes = [];
        $optgroups = $this->allmethods->toOptionArray();
        foreach ($optgroups as $group) {
            $methods = $group['value'] ?? [];
            if (!is_array($methods)) {
                continue;
            }
            foreach ($methods as $method) {
                $code = (string) ($method['value'] ?? '');
                if ($code !== '') {
                    $codes[$code] = true;
                }
            }
        }

        return $this->activeCodes = $codes;
    }

    /**
     * Analyse one NDE method-codes config field against the active set.
     *
     * @param string $type One of the TYPE_* constants on this class
     * @return array{
     *     configured: string[],
     *     matched:    string[],
     *     unmatched:  string[]
     * }
     */
    public function analyze(string $type): array
    {
        $configured = $this->loadConfigured($type);
        $active     = $this->getActiveMethodCodes();

        $matched   = [];
        $unmatched = [];
        foreach ($configured as $code) {
            if (isset($active[$code])) {
                $matched[] = $code;
            } else {
                $unmatched[] = $code;
            }
        }

        return [
            'configured' => $configured,
            'matched'    => $matched,
            'unmatched'  => $unmatched,
        ];
    }

    /**
     * Convenience — does this NDE config field have any active codes at
     * all? If false, the restriction it controls is silently a no-op and
     * the admin should be warned.
     */
    public function hasAnyMatchedCodes(string $type): bool
    {
        return $this->analyze($type)['matched'] !== [];
    }

    /**
     * Convenience — does the merchant have configured codes that don't
     * exist? These are the silent-no-op smoking gun.
     */
    public function hasUnmatchedCodes(string $type): bool
    {
        return $this->analyze($type)['unmatched'] !== [];
    }

    /**
     * Run analysis on every NDE method-codes config field at once.
     * Used by the admin notice to detect "any" mismatch across all rules.
     *
     * @return array<string, array{configured: string[], matched: string[], unmatched: string[]}>
     */
    public function analyzeAll(): array
    {
        return [
            self::TYPE_NEXTDAY       => $this->analyze(self::TYPE_NEXTDAY),
            self::TYPE_STANDARD      => $this->analyze(self::TYPE_STANDARD),
            self::TYPE_CLICK_COLLECT => $this->analyze(self::TYPE_CLICK_COLLECT),
        ];
    }

    /**
     * Pull the merchant-configured codes for the given type out of Config.
     *
     * @return string[]
     */
    private function loadConfigured(string $type): array
    {
        return match ($type) {
            self::TYPE_NEXTDAY       => $this->config->getShippingMethodCodes(),
            self::TYPE_STANDARD      => $this->config->getStandardMethodCodes(),
            self::TYPE_CLICK_COLLECT => $this->config->getClickCollectMethodCodes(),
            default                  => [],
        };
    }
}
