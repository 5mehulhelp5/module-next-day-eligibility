<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_ENABLED = 'etechflow_nextdayeligibility/general/enabled';
    private const XML_PATH_SHIPPING_METHOD_CODES        = 'etechflow_nextdayeligibility/general/shipping_method_codes';
    private const XML_PATH_ADDITIONAL_METHOD_CODES      = 'etechflow_nextdayeligibility/general/additional_method_codes';
    private const XML_PATH_STANDARD_METHOD_CODES        = 'etechflow_nextdayeligibility/general/standard_method_codes';
    private const XML_PATH_ADDITIONAL_STANDARD_CODES    = 'etechflow_nextdayeligibility/general/additional_standard_codes';
    private const XML_PATH_CC_METHOD_CODES              = 'etechflow_nextdayeligibility/general/click_collect_method_codes';
    private const XML_PATH_CC_ADDITIONAL_CODES          = 'etechflow_nextdayeligibility/general/click_collect_additional_codes';
    private const XML_PATH_RESTRICT_INSUFFICIENT_SALABLE = 'etechflow_nextdayeligibility/general/restrict_insufficient_salable';
    private const XML_PATH_LABEL_YES = 'etechflow_nextdayeligibility/general/label_yes';
    private const XML_PATH_LABEL_NO = 'etechflow_nextdayeligibility/general/label_no';
    private const XML_PATH_AUTO_ENABLE_BACKORDERS = 'etechflow_nextdayeligibility/drop_ship/auto_enable_backorders';
    private const XML_PATH_DROP_SHIP_SOURCE       = 'etechflow_nextdayeligibility/drop_ship/source';
    private const XML_PATH_SUPPLIER_PAIRS         = 'etechflow_nextdayeligibility/drop_ship/supplier_pairs';
    private const XML_PATH_SUPPLIER_QUALIFYING    = 'etechflow_nextdayeligibility/drop_ship/qualifying_suppliers';
    private const XML_PATH_SUPPLIER_MATCH_MODE    = 'etechflow_nextdayeligibility/drop_ship/supplier_match_mode';
    private const XML_PATH_BADGE_VISIBILITY = 'etechflow_nextdayeligibility/general/badge_visibility';

    public const DROP_SHIP_SOURCE_FLAG     = 'flag';
    public const DROP_SHIP_SOURCE_SUPPLIER = 'supplier';

    /**
     * Supplier match modes (v1.6.3+).
     *
     * FIRST_ACTIVE_WINS  — iterate slots in priority order; STOP at the first
     *                      active slot; return whether that slot's name is in
     *                      the qualifying list. Models "the supplier we'll
     *                      actually ship from is the first-active one, and
     *                      eligibility must reflect THAT supplier's capability."
     *                      Recommended for new installs.
     *
     * ANY_ACTIVE_QUALIFYING — iterate ALL active slots; return true if any
     *                         match the qualifying list. Loose OR semantics.
     *                         Pre-v1.6.3 default; preserved on existing
     *                         installs via setup patch for backward compat.
     */
    public const MATCH_FIRST_ACTIVE_WINS     = 'first_active_wins';
    public const MATCH_ANY_ACTIVE_QUALIFYING = 'any_active_qualifying';
    private const XML_PATH_SHOW_NOTICE = 'etechflow_nextdayeligibility/notice/show_notice';
    private const XML_PATH_NOTICE_STYLE = 'etechflow_nextdayeligibility/notice/notice_style';
    private const XML_PATH_NOTICE_TITLE = 'etechflow_nextdayeligibility/notice/notice_title';
    private const XML_PATH_NOTICE_MESSAGE = 'etechflow_nextdayeligibility/notice/notice_message';
    private const XML_PATH_BACKORDER_RESTRICT_ENABLED = 'etechflow_nextdayeligibility/backorder_restriction/enabled';
    private const XML_PATH_BACKORDER_EXPRESS_METHODS    = 'etechflow_nextdayeligibility/backorder_restriction/express_method_codes';
    private const XML_PATH_BACKORDER_ADDITIONAL_CODES   = 'etechflow_nextdayeligibility/backorder_restriction/additional_express_codes';
    private const XML_PATH_BACKORDER_SKIP_DROP_SHIP   = 'etechflow_nextdayeligibility/backorder_restriction/skip_drop_ship';

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param LicenseValidator     $licenseValidator
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LicenseValidator $licenseValidator
    ) {
    }

    /**
     * Check if the module is enabled for a given store.
     *
     * Returns false when the license is invalid for the current Magento host,
     * regardless of the admin setting. This makes the module silently no-op
     * on unlicensed installs without breaking the storefront.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        if (!$this->licenseValidator->isValid()) {
            return false;
        }

        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Whether to restrict next-day + Click & Collect when a cart line requests
     * more units than are actually salable (stock − MSI reservations − OOS
     * threshold). New in v1.9.0; defaults to Yes.
     *
     * The master {@see self::isEnabled()} switch already gates callers, so this
     * flag is a plain read with no license check.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isRestrictOnInsufficientSalable(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_RESTRICT_INSUFFICIENT_SALABLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Return the union of two configured sources for next-day shipping codes:
     *
     *   1. Multi-select dropdown of Magento's active carriers (the primary
     *      source — populated by Magento\Shipping\Model\Config\Source\Allmethods)
     *   2. Free-text "additional method codes" input — escape hatch for
     *      custom shipping modules whose carriers don't register through
     *      the standard Allmethods source (v1.4.1+)
     *
     * De-duped, trimmed, filtered. Empty by default — merchant must
     * explicitly choose codes for the filter to do anything.
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getShippingMethodCodes(?int $storeId = null): array
    {
        return $this->mergeCodeSources(
            self::XML_PATH_SHIPPING_METHOD_CODES,
            self::XML_PATH_ADDITIONAL_METHOD_CODES,
            $storeId
        );
    }

    /**
     * Return the union of the Standard Methods multi-select + the Additional
     * Standard Codes free-text input (v1.4.3+).
     *
     * When this list is NON-EMPTY, the shipping-restriction plugin switches
     * from blacklist mode (remove configured next-day codes) to whitelist
     * mode (keep ONLY the standard codes). Empty list = original blacklist
     * behaviour preserved for backward compatibility.
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getStandardMethodCodes(?int $storeId = null): array
    {
        return $this->mergeCodeSources(
            self::XML_PATH_STANDARD_METHOD_CODES,
            self::XML_PATH_ADDITIONAL_STANDARD_CODES,
            $storeId
        );
    }

    /**
     * Click & Collect / in-store pickup method codes (v1.5.1+).
     *
     * The union of the multi-select dropdown and the free-text "additional"
     * input — same merge semantics as the next-day and standard lists.
     *
     * When non-empty, the shipping-restriction plugin hides these methods
     * from any cart whose items have zero local stock — regardless of
     * whether a supplier-based drop-ship rule would have otherwise made
     * the product next-day-eligible. A product fulfilled by a remote
     * supplier can't be picked up from your shop.
     *
     * Empty list (default) = the C&C filter is a no-op. Merchants without
     * physical shops see zero behaviour change.
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getClickCollectMethodCodes(?int $storeId = null): array
    {
        return $this->mergeCodeSources(
            self::XML_PATH_CC_METHOD_CODES,
            self::XML_PATH_CC_ADDITIONAL_CODES,
            $storeId
        );
    }

    /**
     * Merge a primary multi-select path + an additional free-text path
     * into a single de-duplicated, trimmed code list.
     *
     * @param string   $primaryPath
     * @param string   $additionalPath
     * @param int|null $storeId
     * @return string[]
     */
    private function mergeCodeSources(string $primaryPath, string $additionalPath, ?int $storeId): array
    {
        $codes = [];

        $primary = $this->scopeConfig->getValue($primaryPath, ScopeInterface::SCOPE_STORE, $storeId);
        if (!empty($primary)) {
            $codes = array_merge($codes, array_filter(array_map('trim', explode(',', (string) $primary))));
        }

        $additional = $this->scopeConfig->getValue($additionalPath, ScopeInterface::SCOPE_STORE, $storeId);
        if (!empty($additional)) {
            $codes = array_merge($codes, array_filter(array_map('trim', explode(',', (string) $additional))));
        }

        return array_values(array_unique($codes));
    }

    /**
     * Return the badge label for eligible products.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getLabelYes(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_LABEL_YES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Return the badge label for ineligible products.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getLabelNo(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_LABEL_NO,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Return PDP-badge visibility mode: 'both', 'eligible_only', or 'never'.
     *
     * Empty/null defaults to 'both' (preserve pre-v1.3.0 behaviour).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getBadgeVisibility(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_BADGE_VISIBILITY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return in_array($value, ['both', 'eligible_only', 'never'], true) ? $value : 'both';
    }

    /**
     * Whether to automatically enable Magento backorders for products flagged as Drop-Ship Eligible.
     *
     * Defaults to TRUE: when a merchant ticks Drop-Ship Eligible = Yes on a product,
     * our observer also sets its Advanced Inventory → Backorders to "Allow Qty Below 0".
     * This keeps the storefront UX consistent — products that show as "Next Day Eligible"
     * are also purchasable when local stock is zero.
     *
     * Merchants can disable this if they want manual control of backorder settings.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isAutoEnableBackorders(?int $storeId = null): bool
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_AUTO_ENABLE_BACKORDERS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        // Default: Yes — unset or empty config defaults to on (most user-friendly).
        if ($value === null || $value === '') {
            return true;
        }

        return (bool) $value;
    }

    /**
     * Which mechanism the module uses to decide drop-ship eligibility on a
     * product that has zero local stock.
     *
     * Returns one of the DROP_SHIP_SOURCE_* constants. Default = FLAG, which
     * preserves the pre-v1.5 behaviour (read drop_ship_eligible boolean).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getDropShipSource(?int $storeId = null): string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_DROP_SHIP_SOURCE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $value = is_string($value) ? trim($value) : '';
        return $value === self::DROP_SHIP_SOURCE_SUPPLIER
            ? self::DROP_SHIP_SOURCE_SUPPLIER
            : self::DROP_SHIP_SOURCE_FLAG;
    }

    /**
     * Pairs of product-attribute codes that hold supplier state, parsed from
     * the multi-line config field. Each line is `<active_attr>:<name_attr>`.
     *
     * Lines without a colon, with empty halves, or starting with `#` are
     * silently skipped so admins can leave comments / trailing whitespace.
     *
     * @param int|null $storeId
     * @return array<int, array{active: string, name: string}>
     */
    public function getSupplierAttributePairs(?int $storeId = null): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_PATH_SUPPLIER_PAIRS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($raw === '') {
            return [];
        }

        $pairs = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, ':')) {
                continue;
            }
            [$active, $name] = array_map('trim', explode(':', $line, 2));
            if ($active === '' || $name === '') {
                continue;
            }
            $pairs[] = ['active' => $active, 'name' => $name];
        }
        return $pairs;
    }

    /**
     * Which supplier-match mode is active. Returns one of the MATCH_* constants
     * on this class. Default for unset value = first_active_wins (the v1.6.3+
     * recommended semantics).
     *
     * Existing installs upgrading from < 1.6.3 are pinned to
     * `any_active_qualifying` by the SetSupplierMatchModeLegacyForUpgrades
     * data patch, so their behaviour doesn't silently change. They can flip
     * to `first_active_wins` from the admin when ready.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getSupplierMatchMode(?int $storeId = null): string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_SUPPLIER_MATCH_MODE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $value = is_string($value) ? trim($value) : '';
        return $value === self::MATCH_ANY_ACTIVE_QUALIFYING
            ? self::MATCH_ANY_ACTIVE_QUALIFYING
            : self::MATCH_FIRST_ACTIVE_WINS;
    }

    /**
     * Supplier names that count as same-day-shipping, parsed from the
     * multi-line config field. Match against product data is
     * case-insensitive; whitespace is trimmed.
     *
     * Empty list = no supplier qualifies → supplier mode is effectively off.
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getQualifyingSupplierNames(?int $storeId = null): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_PATH_SUPPLIER_QUALIFYING,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($raw === '') {
            return [];
        }

        $names = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $names[] = $line;
        }
        return $names;
    }

    /**
     * Whether to show the customer-facing notice when next-day shipping is removed.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isShowNotice(?int $storeId = null): bool
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_SHOW_NOTICE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        // Default: Yes — unset or empty config defaults to on.
        if ($value === null || $value === '') {
            return true;
        }

        return (bool) $value;
    }

    /**
     * Notice colour style: warning / info / error. Clamped to whitelist.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getNoticeStyle(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_NOTICE_STYLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return in_array($value, ['warning', 'info', 'error'], true) ? $value : 'warning';
    }

    /**
     * Notice title (bold heading shown to the customer).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getNoticeTitle(?int $storeId = null): string
    {
        $title = (string) $this->scopeConfig->getValue(
            self::XML_PATH_NOTICE_TITLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $title !== '' ? $title : 'Next day delivery unavailable';
    }

    /**
     * Notice message body.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getNoticeMessage(?int $storeId = null): string
    {
        $message = (string) $this->scopeConfig->getValue(
            self::XML_PATH_NOTICE_MESSAGE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $message !== ''
            ? $message
            : 'One or more items in your cart is not eligible for next day delivery. Standard shipping options are available.';
    }

    /**
     * Whether to also restrict configured express methods when the cart contains backorder items.
     *
     * Folded in from the deprecated BackorderShippingRestrictor module in v1.1.0.
     * Off by default — the merchant has to opt in.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isRestrictExpressOnBackorder(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_BACKORDER_RESTRICT_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Return configured express shipping method codes to remove when backorder items are present.
     *
     * Kept separate from getShippingMethodCodes() so merchants can target different
     * methods for next-day-eligibility rules vs backorder rules.
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getBackorderExpressMethodCodes(?int $storeId = null): array
    {
        return $this->mergeCodeSources(
            self::XML_PATH_BACKORDER_EXPRESS_METHODS,
            self::XML_PATH_BACKORDER_ADDITIONAL_CODES,
            $storeId
        );
    }

    /**
     * Whether drop-ship-eligible products should bypass the backorder express restriction.
     *
     * Default: Yes. Drop-ship products are fulfilled by the supplier directly, so they
     * aren't effectively on backorder from the customer's POV — restricting express
     * shipping on them would punish customers for a stock state that doesn't matter.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isSkipDropShipForBackorder(?int $storeId = null): bool
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_BACKORDER_SKIP_DROP_SHIP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($value === null || $value === '') {
            return true;
        }

        return (bool) $value;
    }
}
