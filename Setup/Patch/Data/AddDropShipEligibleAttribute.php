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

class AddDropShipEligibleAttribute implements DataPatchInterface, PatchRevertableInterface
{
    private const ATTRIBUTE_CODE = 'drop_ship_eligible';

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
     * Create the drop_ship_eligible product attribute.
     *
     * Idempotent — re-running this patch on an install that already has the
     * attribute is a no-op.
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
                'label'                   => 'Drop-Ship Eligible',
                'note'                    => 'Tick if this product ships direct from a supplier (drop-ship) rather than from your warehouse. Effect: the product stays "Next Day Eligible" even when local stock is 0, AND — if "Auto-Enable Backorders" is on in NDE config (default Yes) — Magento\'s Backorders flag will be auto-set to "Allow Qty Below 0" so customers can still add to cart with zero local stock.',
                'input'                   => 'boolean',
                'source'                  => Boolean::class,
                'required'                => false,
                'sort_order'              => 210,
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
                'apply_to'               => 'simple,configurable,virtual,bundle,grouped',
                'is_used_in_grid'        => true,
                'is_visible_in_grid'     => true,
                'is_filterable_in_grid'  => true,
                'group'                  => 'eTechFlow Shipping',
            ]
        );
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * Remove the drop_ship_eligible product attribute.
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
     * Return patch aliases.
     *
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * Return patch dependencies.
     *
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [AddNextDayEligibleAttribute::class];
    }
}
