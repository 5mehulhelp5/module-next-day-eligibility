<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model;

use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Psr\Log\LoggerInterface;

/**
 * Detects backorder / partial-stock items in a cart.
 *
 * Used by the express-on-backorder shipping restriction (folded in from the
 * deprecated BackorderShippingRestrictor module in v1.1.0).
 *
 * Three cases count as "backordered":
 *  1. Product is out of stock entirely (getIsInStock = false).
 *  2. Backorders are allowed AND stock has been depleted at/below min-qty.
 *  3. Ordered qty exceeds available saleable stock (partial-stock backorder).
 *
 * When Config::isSkipDropShipForBackorder() is enabled, products with
 * drop_ship_eligible = 1 are exempted (the supplier fulfils them directly,
 * so they're never effectively on backorder from the merchant's POV).
 */
class BackorderChecker
{
    /** Product types whose stock is managed by their child items. */
    private const CONTAINER_TYPES = [
        ConfigurableType::TYPE_CODE,
        BundleType::TYPE_CODE,
    ];

    /** Product types that are never physical and do not need stock checks. */
    private const VIRTUAL_TYPES = ['virtual', 'downloadable'];

    private const DROP_SHIP_ATTR_CODE = 'drop_ship_eligible';

    /**
     * Constructor.
     *
     * @param StockRegistryInterface   $stockRegistry
     * @param Config                   $config
     * @param ProductCollectionFactory $productCollectionFactory
     * @param LoggerInterface          $logger
     */
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly Config $config,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Return true if any non-virtual, leaf cart item is on backorder
     * or has insufficient stock to fulfil the ordered quantity.
     *
     * @param QuoteItem[] $items
     * @return bool
     */
    public function hasBackorderItems(array $items): bool
    {
        $candidates = $this->filterCandidateItems($items);
        if (empty($candidates)) {
            return false;
        }

        $dropShipMap = $this->config->isSkipDropShipForBackorder()
            ? $this->loadDropShipMap($this->collectProductIds($candidates))
            : [];

        foreach ($candidates as $item) {
            $productId = (int) $item->getProductId();

            if (!empty($dropShipMap[$productId])) {
                continue;
            }

            if ($this->isItemBackordered($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip out deleted, container, and virtual items.
     *
     * @param QuoteItem[] $items
     * @return QuoteItem[]
     */
    private function filterCandidateItems(array $items): array
    {
        $candidates = [];

        foreach ($items as $item) {
            if ($item->isDeleted()) {
                continue;
            }
            if (in_array($item->getProductType(), self::CONTAINER_TYPES, true)) {
                continue;
            }
            if (in_array($item->getProductType(), self::VIRTUAL_TYPES, true)) {
                continue;
            }

            $candidates[] = $item;
        }

        return $candidates;
    }

    /**
     * @param QuoteItem[] $items
     * @return int[]
     */
    private function collectProductIds(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[(int) $item->getProductId()] = true;
        }

        return array_keys($ids);
    }

    /**
     * @param int[] $productIds
     * @return array<int, bool>
     */
    private function loadDropShipMap(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        try {
            $collection = $this->productCollectionFactory->create();
            $collection->addIdFilter($productIds);
            $collection->addAttributeToSelect(self::DROP_SHIP_ATTR_CODE);

            $map = [];
            foreach ($collection as $product) {
                $map[(int) $product->getId()] = (bool) $product->getData(self::DROP_SHIP_ATTR_CODE);
            }

            return $map;
        } catch (\Exception $e) {
            $this->logger->debug(
                'ETechFlow_NextDayEligibility: drop_ship_eligible map unavailable for backorder check; treating as no exemptions.',
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }

    /**
     * @param QuoteItem $item
     * @return bool
     */
    private function isItemBackordered(QuoteItem $item): bool
    {
        $productId = (int) $item->getProductId();

        // Defensive: items in webhook/cron contexts can be detached from their quote;
        // Magento's declared return type for getQuote() is Quote (non-null) but the
        // runtime can return null. Same applies to getStore(). If either is null,
        // fall back to the default-website stock item.
        $quote = $item->getQuote();
        $store = $quote !== null ? $quote->getStore() : null;

        if ($store !== null) {
            $stockItem = $this->stockRegistry->getStockItem($productId, (int) $store->getWebsiteId());
        } else {
            $stockItem = $this->stockRegistry->getStockItem($productId);
        }

        if (!$stockItem || !$stockItem->getItemId()) {
            return false;
        }

        if (!$stockItem->getIsInStock()) {
            return true;
        }

        $stockQty = (float) $stockItem->getQty();
        $minQty   = (float) $stockItem->getMinQty();

        if ((int) $stockItem->getBackorders() > 0 && $stockQty <= $minQty) {
            return true;
        }

        $orderedQty  = (float) $item->getQty();
        $saleableQty = $stockQty - $minQty;

        if ($stockQty > 0 && $orderedQty > $saleableQty) {
            return true;
        }

        return false;
    }
}
