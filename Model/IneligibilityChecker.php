<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model;

use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedType;

/**
 * Shared eligibility check used by the shipping-restriction plugin AND the
 * customer-notice ConfigProvider/ViewModel.
 *
 * Returns true when at least one cart item has next_day_eligible != 1 (or NULL).
 * That's the condition for both:
 *   1. Removing next-day shipping methods from the rates list (ShippingRestriction)
 *   2. Showing the customer notice ("not eligible for next day delivery")
 *
 * Extracted from ShippingRestriction so both code paths use identical logic —
 * the notice and the restriction can't drift out of sync.
 */
class IneligibilityChecker
{
    private const ATTRIBUTE_CODE = 'next_day_eligible';

    /** Product types whose stock/eligibility is managed by their child items. */
    private const CONTAINER_TYPES = [
        ConfigurableType::TYPE_CODE,
        BundleType::TYPE_CODE,
        GroupedType::TYPE_CODE,
    ];

    /**
     * Constructor.
     *
     * @param ProductCollectionFactory $productCollectionFactory
     */
    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory
    ) {
    }

    /**
     * Check whether any of the supplied cart items is NOT next-day eligible.
     *
     * Skips deleted items and container product types (parent items whose
     * children carry the actual eligibility).
     *
     * A product is considered ineligible when its `next_day_eligible` is not 1,
     * OR when the value is NULL (newly imported, never evaluated — defaults to
     * "not eligible" until proven otherwise).
     *
     * @param \Magento\Quote\Model\Quote\Item[] $items
     * @return bool true if AT LEAST ONE item is ineligible
     */
    public function hasIneligibleItems(array $items): bool
    {
        $productIds = $this->collectLeafProductIds($items);
        if (empty($productIds)) {
            return false;
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addIdFilter(array_unique($productIds));
        $collection->addAttributeToFilter(
            [
                ['attribute' => self::ATTRIBUTE_CODE, 'neq'  => 1],
                ['attribute' => self::ATTRIBUTE_CODE, 'null' => true],
            ]
        );
        $collection->setPageSize(1);

        return $collection->getSize() > 0;
    }

    /**
     * Check whether any of the supplied cart items has zero local stock.
     *
     * Independent of `next_day_eligible` — a product can be eligible via
     * a drop-ship supplier (NDE supplier mode) AND still have no local
     * stock. Used by the v1.5.1 Click & Collect filter, which must
     * remove pickup methods in that case because nothing is physically
     * on the shelf.
     *
     * Speed: single batched product-collection query joining
     * `cataloginventory_stock_item` for qty + is_in_stock. Same cost
     * shape as `hasIneligibleItems` — no N+1 regardless of cart size.
     *
     * A product is treated as out-of-local-stock when:
     *  - the stock-item row has `qty <= 0`, OR
     *  - `is_in_stock = 0` (admin flagged out of stock), OR
     *  - there's no stock row at all (newly imported product that
     *    never went through Magento's inventory bootstrap)
     *
     * @param \Magento\Quote\Model\Quote\Item[] $items
     * @return bool true if AT LEAST ONE item has no local stock
     */
    public function hasItemsWithoutLocalStock(array $items): bool
    {
        $productIds = $this->collectLeafProductIds($items);
        if (empty($productIds)) {
            return false;
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addIdFilter(array_unique($productIds));

        // Join `qty` + `is_in_stock` from cataloginventory_stock_item in
        // one go. Using joinField (left join) so products without a stock
        // row at all still come through with NULL values — we treat NULL
        // as out-of-stock below.
        $collection->joinField(
            'qty',
            'cataloginventory_stock_item',
            'qty',
            'product_id=entity_id',
            null,
            'left'
        );
        $collection->joinField(
            'is_in_stock',
            'cataloginventory_stock_item',
            'is_in_stock',
            'product_id=entity_id',
            null,
            'left'
        );

        // Filter to rows where the product is out of local stock by any
        // of the three criteria above.
        $collection->getSelect()->where(
            'qty <= 0 OR is_in_stock = 0 OR is_in_stock IS NULL'
        );
        $collection->setPageSize(1);

        return $collection->getSize() > 0;
    }

    /**
     * Collect product IDs from leaf (non-container) cart items.
     *
     * @param \Magento\Quote\Model\Quote\Item[] $items
     * @return int[]
     */
    private function collectLeafProductIds(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if ($item->isDeleted()) {
                continue;
            }

            if (in_array($item->getProductType(), self::CONTAINER_TYPES, true)) {
                continue;
            }

            $ids[] = (int) $item->getProductId();
        }

        return $ids;
    }
}
