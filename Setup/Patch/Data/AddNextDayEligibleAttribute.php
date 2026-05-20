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

class AddNextDayEligibleAttribute implements DataPatchInterface, PatchRevertableInterface
{
    private const ATTRIBUTE_CODE = 'next_day_eligible';

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
     * Create the next_day_eligible product attribute.
     *
     * Idempotent — re-running this patch on an install that already has the
     * attribute is a no-op, so we never silently mutate live attribute config
     * on uninstall/reinstall or migration replays.
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
                    'label'                   => 'Next Day Eligible',
                    'input'                   => 'boolean',
                    'source'                  => Boolean::class,
                    'required'                => false,
                    'sort_order'              => 200,
                    'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                    // Default to NOT eligible. The first stock evaluation flips this on
                    // for products that qualify; until then a newly-imported out-of-stock
                    // product shouldn't be advertised as next-day shippable.
                    'default'                 => 0,
                    'visible'                 => true,
                    'user_defined'            => true,
                    'searchable'              => false,
                    'filterable'              => false,
                    'comparable'              => false,
                    'visible_on_front'        => true,
                    'used_in_product_listing' => true,
                    'unique'                  => false,
                    'apply_to'                => 'simple,configurable,virtual,bundle,grouped',
                    'is_used_in_grid'         => true,
                    'is_visible_in_grid'      => true,
                    'is_filterable_in_grid'   => false,
                    'group'                   => 'eTechFlow Shipping',
                ]
            );
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * Remove the next_day_eligible product attribute.
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
        return [];
    }
}
