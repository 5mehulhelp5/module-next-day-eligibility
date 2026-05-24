<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * v1.6.5 patch — supersedes SetSupplierMatchModeLegacyForUpgrades.
 *
 * The v1.6.4 patch had a logic bug: it used ScopeConfigInterface::getValue()
 * to detect "has the merchant explicitly set this config?" — but that method
 * returns the MERGED config including config.xml defaults. Since v1.6.4's
 * config.xml defaults supplier_match_mode to `first_active_wins`, the patch
 * always saw a truthy value → bailed early thinking the merchant had set it
 * → never wrote the legacy pin. Upshot: existing installs upgrading from
 * pre-v1.6.0 silently flipped to first-active-wins semantics instead of
 * being pinned to legacy as intended.
 *
 * Fix: query the `core_config_data` table DIRECTLY. The row only exists
 * when the merchant has explicitly saved a value via admin or stored-config.
 * config.xml defaults are NEVER persisted to that table.
 *
 * Why a new patch class instead of fixing the old one in-place:
 *
 *   Magento tracks patch execution by class name in the `patch_list` table.
 *   On installs that already ran the buggy v1.6.4 patch (patch_list row
 *   present), updating the v1.6.4 class's logic wouldn't cause it to re-run
 *   — Magento sees the patch_list row and skips it. A new class name
 *   guarantees re-execution.
 *
 *   The old class is kept on disk (marked @deprecated) so its patch_list
 *   row stays valid. Removing the file would cause "patch not found"
 *   errors on next setup:upgrade.
 *
 * @see SetSupplierMatchModeLegacyForUpgrades (deprecated; do not delete)
 */
class PinLegacySupplierMatchModeForUpgrades implements DataPatchInterface
{
    private const CONFIG_PATH = 'etechflow_nextdayeligibility/drop_ship/supplier_match_mode';
    private const MODULE_NAME = 'ETechFlow_NextDayEligibility';
    private const LEGACY_MODE = 'any_active_qualifying';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly WriterInterface $configWriter
    ) {
    }

    public function apply(): self
    {
        $connection  = $this->moduleDataSetup->getConnection();
        $setupTable  = $this->moduleDataSetup->getTable('setup_module');
        $configTable = $this->moduleDataSetup->getTable('core_config_data');

        // Step 1 — fresh install vs upgrade?
        $existingVersion = $connection->fetchOne(
            $connection->select()
                ->from($setupTable, 'data_version')
                ->where('module = ?', self::MODULE_NAME)
        );
        if (!$existingVersion) {
            // Fresh install — leave config.xml default in place (first_active_wins)
            return $this;
        }

        // Step 2 — does an explicit row exist in core_config_data?
        // (NOT scopeConfig — that returns the merged config.xml default too,
        // which is the bug the v1.6.4 patch had.)
        $explicitRow = $connection->fetchOne(
            $connection->select()
                ->from($configTable, 'value')
                ->where('path = ?', self::CONFIG_PATH)
                ->where('scope = ?', 'default')
                ->where('scope_id = ?', 0)
        );
        if ($explicitRow !== false && $explicitRow !== '' && $explicitRow !== null) {
            // Merchant explicitly set this — respect their choice
            return $this;
        }

        // Step 3 — pin to legacy to preserve pre-v1.6.0 behaviour
        $this->configWriter->save(self::CONFIG_PATH, self::LEGACY_MODE);

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
