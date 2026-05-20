<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * v1.3.0 migration: enable the "Filter in Grid" flag on drop_ship_eligible
 * for installs that already had the attribute from a pre-1.3.0 install
 * (created with is_filterable_in_grid = false).
 *
 * Fresh installs get the flag set directly via AddDropShipEligibleAttribute;
 * this patch is only needed for upgrades.
 */
class EnableDropShipGridFilter implements DataPatchInterface
{
    private const ATTRIBUTE_CODE = 'drop_ship_eligible';

    /**
     * Constructor.
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EavConfig                $eavConfig
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * Flip is_filterable_in_grid to 1 on the existing drop_ship_eligible attribute.
     *
     * Idempotent — re-running has no effect once the flag is already 1.
     *
     * @return self
     */
    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, self::ATTRIBUTE_CODE);
            if ($attribute && $attribute->getId() && (int) $attribute->getData('is_filterable_in_grid') !== 1) {
                $attribute->setData('is_filterable_in_grid', 1);
                $attribute->save();
            }
        } catch (\Exception $e) {
            // Attribute doesn't exist yet — AddDropShipEligibleAttribute will
            // run with the correct flag set. Nothing to do here.
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [AddDropShipEligibleAttribute::class];
    }
}
