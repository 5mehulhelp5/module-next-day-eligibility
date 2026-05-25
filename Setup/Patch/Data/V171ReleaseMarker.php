<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * No-op release marker for v1.7.1.
 *
 * Discipline established after the v1.7.0 Keystation deploy incident:
 * every NDE release ships at least one data patch, even if it has no
 * actual data work to do. This guarantees `setup:upgrade` always has
 * SOMETHING to register in the `patch_list` table, surfacing FS /
 * permissions / DI errors during the patch phase (which retries
 * cleanly) instead of at the end of the upgrade (which doesn't).
 *
 * v1.7.0 bumped module.xml's `setup_version` to 1.7.0 but shipped no
 * new patches. When `setup:upgrade` hit a FilesystemIterator warning
 * on the production server, the run aborted before `setup_module.
 * data_version` was advanced. Magento's DbStatusValidator then saw
 * module.xml=1.7.0 vs DB=1.6.5 and blocked every request → site 500.
 *
 * Going forward, every NDE release ships at least one patch. If a
 * release genuinely has no data migration to do, this template gets
 * copied/renamed to e.g. `V172ReleaseMarker`, `V200ReleaseMarker`, etc.
 *
 * @see CHANGELOG.md v1.7.1 entry for the full incident write-up.
 */
class V171ReleaseMarker implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public function apply(): self
    {
        // Intentionally no-op. Existence in `patch_list` is the only
        // side effect — that's the point. See class docblock.
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
