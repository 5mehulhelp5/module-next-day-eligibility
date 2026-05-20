<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

/**
 * Adds the `force_standard_shipping_only` per-product boolean attribute (v1.4.0).
 *
 * Merchant-controlled override that hard-disables next-day eligibility for a
 * specific product regardless of stock state. Use cases:
 *  - Bulky / oversized items couriers won't take next-day
 *  - Hazardous goods (batteries, aerosols, chemicals) — air freight restricted
 *  - Fragile items the merchant only ships via specific slower carriers
 *  - Made-to-order or promotional items the merchant won't subsidise express on
 *
 * Lives under the same "eTechFlow Shipping" attribute group as
 * drop_ship_eligible so merchants find both controls in one place.
 *
 * Precedence in EligibilityEvaluator:
 *   force_standard_shipping_only = 1  =>  ALWAYS ineligible (merchant override wins)
 *   drop_ship_eligible           = 1  =>  ALWAYS eligible   (supplier ships direct)
 *   stock check                       =>  qty > 0 = eligible
 */
class AddForceStandardShippingOnlyAttribute implements DataPatchInterface, PatchRevertableInterface
{
    private const ATTRIBUTE_CODE = 'force_standard_shipping_only';

    /**
     * Constructor.
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EavSetupFactory          $eavSetupFactory
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    /**
     * Create the attribute. Idempotent.
     *
     * @return self
     */
    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE)) {
            $eavSetup->addAttribute(
                Product::ENTITY,
                self::ATTRIBUTE_CODE,
                [
                    'type'                    => 'int',
                    'label'                   => 'Force Standard Shipping Only',
                    'note'                    => 'Hard override: tick to BLOCK next-day shipping for this product regardless of stock or drop-ship state. Use for bulky / hazmat / fragile / made-to-order items. Precedence: this overrides Drop-Ship Eligible (force-standard wins). Does NOT affect saleability — customer can still add to cart, just can\'t pick express shipping.',
                    'input'                   => 'boolean',
                    'source'                  => Boolean::class,
                    'required'                => false,
                    'sort_order'              => 220,
                    'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                    'default'                 => 0,
                    'visible'                 => true,
                    'user_defined'            => true,
                    'searchable'              => false,
                    'filterable'              => false,
                    'comparable'              => false,
                    'visible_on_front'        => false,
                    'used_in_product_listing' => true,
                    'unique'                  => false,
                    'apply_to'                => 'simple,configurable,virtual,bundle,grouped',
                    'is_used_in_grid'         => true,
                    'is_visible_in_grid'      => true,
                    'is_filterable_in_grid'   => true,
                    'group'                   => 'eTechFlow Shipping',
                ]
            );
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * Remove the attribute.
     *
     * @return void
     */
    public function revert(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $eavSetup->removeAttribute(Product::ENTITY, self::ATTRIBUTE_CODE);

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * Depends on the main next_day_eligible attribute existing first.
     *
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [AddDropShipEligibleAttribute::class];
    }
}
