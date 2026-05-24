<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * @deprecated since v1.6.5 — superseded by {@see PinLegacySupplierMatchModeForUpgrades}.
 *
 * v1.6.4 (this class) had a logic bug: the early-exit check used
 * `ScopeConfigInterface::getValue()` which returns the MERGED config
 * including `config.xml` defaults. Since v1.6.4's config.xml defaults
 * supplier_match_mode to `first_active_wins`, this patch always saw a
 * truthy value → bailed early thinking the merchant had explicitly set
 * it → never wrote the legacy pin. Upshot: existing installs upgrading
 * from pre-v1.6.0 silently flipped to first-active-wins semantics
 * instead of being pinned to legacy as intended.
 *
 * Caught by local Magento Docker smoke test post v1.6.4 deploy. Fix
 * shipped in v1.6.5 as a new class with a different name (so Magento
 * re-runs it on installs where this one's `patch_list` row is already
 * present).
 *
 * Kept on disk (NOT deleted) because installs that already ran this
 * patch have a row in `patch_list` referencing this exact class name.
 * Deleting the file would cause `setup:upgrade` to fail with
 * "patch not found" on those installs. The class still loads, the
 * apply() method is now a no-op, the patch_list row stays valid.
 *
 * Original purpose (preserved for context): pre-v1.6.3 the
 * SupplierDropShipResolver ran in `any_active_qualifying` mode
 * unconditionally — iterate every active slot, return true if any
 * matched the qualifying list. v1.6.3 introduced a config switch and
 * defaults NEW installs to `first_active_wins` (the more honest
 * semantics for real-world fulfillment).
 *
 * @see \ETechFlow\NextDayEligibility\Model\Config::MATCH_ANY_ACTIVE_QUALIFYING
 * @see \ETechFlow\NextDayEligibility\Model\Config::MATCH_FIRST_ACTIVE_WINS
 * @see PinLegacySupplierMatchModeForUpgrades  v1.6.5 replacement
 */
class SetSupplierMatchModeLegacyForUpgrades implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly WriterInterface $configWriter,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function apply(): self
    {
        // v1.6.5: NO-OP. Corrected logic lives in
        // PinLegacySupplierMatchModeForUpgrades. This class is kept on
        // disk only so installs that already executed it (patch_list
        // row present) don't error on next setup:upgrade with
        // "patch class not found".
        return $this;
    }

    public function getAliases(): array
    {
        return [];
    }

    public static function getDependencies(): array
    {
        return [];
    }
}
