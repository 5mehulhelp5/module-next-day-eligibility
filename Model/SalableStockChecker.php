<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model;

use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedType;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\StockByWebsiteIdResolverInterface;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Psr\Log\LoggerInterface;

/**
 * Detects cart lines whose requested quantity exceeds the MSI *salable*
 * quantity — physical stock MINUS reservations MINUS the out-of-stock
 * threshold.
 *
 * Unlike {@see BackorderChecker} (which reads the legacy
 * `cataloginventory_stock_item.qty` via StockRegistry and is therefore
 * reservation-blind), this checker asks MSI for the real salable figure, so
 * units already promised to other unshipped orders are correctly excluded.
 *
 * Drives the v1.9.0 "restrict when salable stock is insufficient" rule: when
 * ANY line can't be fully satisfied from the shelf, the {@see \ETechFlow\NextDayEligibility\Plugin\ShippingRestriction}
 * plugin pulls both next-day and Click & Collect methods so checkout falls back
 * to a realistic speed (the backordered units ship when they arrive) — the
 * order still completes.
 *
 * Why `GetProductSalableQtyInterface` and not `IsProductSalableForRequestedQtyInterface`:
 * the latter honours backorder configuration and reports "salable" when
 * backorders are allowed — which would hide exactly the shelf-shortfall we need
 * to detect. The numeric salable qty (reservation-aware, backorder-agnostic) is
 * the correct signal.
 */
class SalableStockChecker
{
    /** Product types whose stock is managed by their child items. */
    private const CONTAINER_TYPES = [
        ConfigurableType::TYPE_CODE,
        BundleType::TYPE_CODE,
        GroupedType::TYPE_CODE,
    ];

    /** Product types that are never physical and do not need stock checks. */
    private const VIRTUAL_TYPES = ['virtual', 'downloadable'];

    private const DROP_SHIP_ATTR_CODE = 'drop_ship_eligible';

    /**
     * Constructor.
     *
     * @param GetProductSalableQtyInterface      $getProductSalableQty
     * @param StockByWebsiteIdResolverInterface  $stockByWebsiteIdResolver
     * @param Config                             $config
     * @param ProductCollectionFactory           $productCollectionFactory
     * @param LoggerInterface                    $logger
     */
    public function __construct(
        private readonly GetProductSalableQtyInterface $getProductSalableQty,
        private readonly StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver,
        private readonly Config $config,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Return true if any non-virtual, leaf cart line requests more units than
     * are currently salable from the shelf (stock − reservations − OOS threshold).
     *
     * Drop-ship items (drop_ship_eligible = 1) are exempted when
     * {@see Config::isSkipDropShipForBackorder()} is on — the supplier fulfils
     * them directly, so shelf stock is irrelevant. Mirrors BackorderChecker so
     * the two shelf rules stay consistent.
     *
     * @param QuoteItem[] $items
     * @return bool
     */
    public function hasShortfall(array $items): bool
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

            if ($this->isLineShort($item)) {
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
                'ETechFlow_NextDayEligibility: drop_ship_eligible map unavailable for salable check; '
                . 'treating as no exemptions.',
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }

    /**
     * Whether the requested qty for a single cart line exceeds its MSI salable qty.
     *
     * Fully defensive: any MSI failure (SKU not assigned to a stock, MSI disabled
     * at runtime, or a detached quote) is swallowed and treated as "satisfiable"
     * so the shipping-rate collection is never broken. Logged at debug for
     * diagnostics.
     *
     * @param QuoteItem $item
     * @return bool
     */
    private function isLineShort(QuoteItem $item): bool
    {
        $sku = (string) $item->getSku();
        if ($sku === '') {
            return false;
        }

        try {
            $quote = $item->getQuote();
            $store = $quote !== null ? $quote->getStore() : null;
            if ($store === null) {
                return false;
            }

            $stockId = (int) $this->stockByWebsiteIdResolver
                ->execute((int) $store->getWebsiteId())
                ->getStockId();

            $salableQty   = (float) $this->getProductSalableQty->execute($sku, $stockId);
            $requestedQty = (float) $item->getQty();

            return $requestedQty > $salableQty;
        } catch (\Exception $e) {
            $this->logger->debug(
                'ETechFlow_NextDayEligibility: salable-qty check skipped for SKU "' . $sku . '".',
                ['exception' => $e->getMessage()]
            );
            return false;
        }
    }
}
