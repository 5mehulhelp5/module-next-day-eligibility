<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * v1.4.1 migration: replace the generic attribute notes on
 * `drop_ship_eligible` and `force_standard_shipping_only` with the
 * detailed explanations that AddDropShipEligibleAttribute and
 * AddForceStandardShippingOnlyAttribute now declare for FRESH installs.
 *
 * Without this patch, existing installs would keep the old short notes
 * because the original create-attribute patches only run once.
 *
 * Idempotent — re-running it on an already-up-to-date install is a no-op
 * (the new note matches the column value).
 */
class UpdateAttributeNotesV141 implements DataPatchInterface
{
    private const NEW_DROP_SHIP_NOTE = 'Tick if this product ships direct from a supplier (drop-ship) rather than from your warehouse. Effect: the product stays "Next Day Eligible" even when local stock is 0, AND — if "Auto-Enable Backorders" is on in NDE config (default Yes) — Magento\'s Backorders flag will be auto-set to "Allow Qty Below 0" so customers can still add to cart with zero local stock.';

    private const NEW_FORCE_STANDARD_NOTE = 'Hard override: tick to BLOCK next-day shipping for this product regardless of stock or drop-ship state. Use for bulky / hazmat / fragile / made-to-order items. Precedence: this overrides Drop-Ship Eligible (force-standard wins). Does NOT affect saleability — customer can still add to cart, just can\'t pick express shipping.';

    /**
     * Constructor.
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EavConfig                $eavConfig
     * @param LoggerInterface          $logger
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavConfig $eavConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return self
     */
    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $this->updateNote('drop_ship_eligible', self::NEW_DROP_SHIP_NOTE);
        $this->updateNote('force_standard_shipping_only', self::NEW_FORCE_STANDARD_NOTE);

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * Update an attribute's `note` column if the attribute exists.
     *
     * @param string $attributeCode
     * @param string $newNote
     */
    private function updateNote(string $attributeCode, string $newNote): void
    {
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $attributeCode);
            if (!$attribute->getId()) {
                return;
            }
            if ((string) $attribute->getData('note') === $newNote) {
                return;  // already up to date — idempotent
            }
            $attribute->setData('note', $newNote);
            $attribute->save();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ETechFlow_NextDayEligibility: Failed to update note on attribute "' . $attributeCode . '".',
                ['exception' => $e->getMessage()]
            );
        }
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
        return [
            AddDropShipEligibleAttribute::class,
            AddForceStandardShippingOnlyAttribute::class,
        ];
    }
}
