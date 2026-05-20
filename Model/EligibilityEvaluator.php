<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model;

use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedType;
use Magento\Store\Model\Store;

class EligibilityEvaluator
{
    private const ATTRIBUTE_CODE              = 'next_day_eligible';
    private const DROP_SHIP_ATTR_CODE         = 'drop_ship_eligible';
    private const FORCE_STANDARD_ATTR_CODE    = 'force_standard_shipping_only';

    /**
     * Constructor.
     *
     * @param ProductAction             $productAction
     * @param StockRegistryInterface    $stockRegistry
     * @param ConfigurableType          $configurableType
     * @param GroupedType               $groupedType
     * @param BundleType                $bundleType
     * @param ProductCollectionFactory  $productCollectionFactory
     * @param ResourceConnection        $resourceConnection
     * @param EavConfig                 $eavConfig
     * @param Config                    $config
     * @param SupplierDropShipResolver  $supplierResolver
     */
    public function __construct(
        private readonly ProductAction $productAction,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly ConfigurableType $configurableType,
        private readonly GroupedType $groupedType,
        private readonly BundleType $bundleType,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig,
        private readonly Config $config,
        private readonly SupplierDropShipResolver $supplierResolver
    ) {
    }

    /**
     * Recompute eligibility for a product by ID. Loads its stock item internally.
     *
     * @param int $productId
     * @return void
     */
    public function evaluateById(int $productId): void
    {
        $stockItem = $this->stockRegistry->getStockItem($productId);
        if (!$stockItem || !$stockItem->getItemId()) {
            return;
        }

        $this->evaluate($productId, $stockItem);
    }

    /**
     * Recompute eligibility for a product when its stock item is already loaded.
     *
     * @param int                $productId
     * @param StockItemInterface $stockItem
     * @return void
     */
    public function evaluate(int $productId, StockItemInterface $stockItem): void
    {
        $isEligible = $this->isStockEligible($productId, $stockItem);
        $this->updateProductAttribute($productId, $isEligible);
        $this->updateParentProducts($productId, $isEligible);
    }

    /**
     * Compute eligibility with the following precedence:
     *
     *  1. force_standard_shipping_only = 1  =>  ALWAYS ineligible.
     *     Merchant override (v1.4.0+). Use for bulky / hazmat / fragile items
     *     or anything the merchant explicitly wants restricted to standard
     *     delivery regardless of warehouse stock state.
     *
     *  2. drop_ship_eligible = 1            =>  ALWAYS eligible.
     *     Manual flag — supplier fulfils direct so local stock is irrelevant.
     *
     *  3. supplier match (v1.5+)            =>  ALWAYS eligible.
     *     Only consulted when Drop-Ship Source = supplier in admin config.
     *     Checks each configured supplier-attribute pair: if any is active
     *     AND its supplier name is on the qualifying list, the product is
     *     drop-ship-eligible via that supplier.
     *
     *  4. stock check                       =>  qty > 0 AND in_stock = eligible.
     *
     * @param int                $productId
     * @param StockItemInterface $stockItem
     * @return bool
     */
    private function isStockEligible(int $productId, StockItemInterface $stockItem): bool
    {
        $flags = $this->loadAttributeFlags($productId);

        // Precedence 1: merchant override wins over everything.
        if ($flags['force_standard']) {
            return false;
        }

        // Precedence 2: manual drop-ship flag (always honoured, regardless of
        // which Drop-Ship Source mode is active — admin-set Yes is an override).
        if ($flags['drop_ship']) {
            return true;
        }

        // Precedence 3: supplier-based detection (v1.5+). Only runs when
        // admin has switched the Drop-Ship Source mode to supplier — the
        // resolver itself also returns false fast if no pairs or qualifying
        // names are configured, so this is cheap when off.
        if ($this->config->getDropShipSource() === Config::DROP_SHIP_SOURCE_SUPPLIER
            && $this->supplierResolver->isDropShipEligible($productId)
        ) {
            return true;
        }

        // Precedence 4: real stock state.
        return $stockItem->getIsInStock() && (float) $stockItem->getQty() > 0;
    }

    /**
     * Load drop_ship_eligible + force_standard_shipping_only flags in a single
     * collection query. Doing them together saves one DB round-trip on every
     * eligibility recalculation (stock saves, drop-ship saves, force-flag saves).
     *
     * @param int $productId
     * @return array{drop_ship: bool, force_standard: bool}
     */
    private function loadAttributeFlags(int $productId): array
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addIdFilter([$productId]);
        $collection->addAttributeToSelect(self::DROP_SHIP_ATTR_CODE);
        $collection->addAttributeToSelect(self::FORCE_STANDARD_ATTR_CODE);
        $collection->setPageSize(1);

        $product = $collection->getFirstItem();

        return [
            'drop_ship'      => (bool) $product->getData(self::DROP_SHIP_ATTR_CODE),
            'force_standard' => (bool) $product->getData(self::FORCE_STANDARD_ATTR_CODE),
        ];
    }

    /**
     * Persist next_day_eligible for a single product.
     *
     * @param int  $productId
     * @param bool $isEligible
     * @return void
     */
    private function updateProductAttribute(int $productId, bool $isEligible): void
    {
        $this->productAction->updateAttributes(
            [$productId],
            [self::ATTRIBUTE_CODE => (int) $isEligible],
            Store::DEFAULT_STORE_ID
        );
    }

    /**
     * Propagate eligibility up to parent products of all relevant types.
     *
     * @param int  $childProductId
     * @param bool $childIsEligible
     * @return void
     */
    private function updateParentProducts(int $childProductId, bool $childIsEligible): void
    {
        $this->processParentIds(
            $this->configurableType->getParentIdsByChild($childProductId),
            $childIsEligible,
            'configurable'
        );

        $this->processParentIds(
            $this->groupedType->getParentIdsByChild($childProductId),
            $childIsEligible,
            'grouped'
        );

        $this->processParentIds(
            $this->bundleType->getParentIdsByChild($childProductId),
            $childIsEligible,
            'bundle'
        );
    }

    /**
     * Update parent eligibility based on their children's combined state.
     *
     * @param array  $parentIds
     * @param bool   $childIsEligible
     * @param string $type
     * @return void
     */
    private function processParentIds(array $parentIds, bool $childIsEligible, string $type): void
    {
        foreach ($parentIds as $parentId) {
            $parentId = (int) $parentId;

            if ($childIsEligible) {
                $this->updateProductAttribute($parentId, true);
            } else {
                $isEligible = $this->isAnyChildEligible($parentId, $type);
                $this->updateProductAttribute($parentId, $isEligible);
            }
        }
    }

    /**
     * Check whether any child of a parent still has next_day_eligible = 1.
     *
     * Reads directly from the EAV `catalog_product_entity_int` table rather
     * than via a product collection, because:
     *
     *  - We're called from inside the same product-save chain that just wrote
     *    children's `next_day_eligible` via `updateAttributes`.
     *  - The catalog flat index isn't refreshed until a separate indexer run,
     *    so a collection-backed read may return STALE values mid-transaction
     *    and propagate the wrong eligibility up to the parent.
     *
     * Direct EAV read sees writes immediately, regardless of the flat index state.
     *
     * @param int    $parentProductId
     * @param string $type
     * @return bool
     */
    private function isAnyChildEligible(int $parentProductId, string $type): bool
    {
        $childIds = $this->getChildIds($parentProductId, $type);

        if (empty($childIds)) {
            return false;
        }

        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, self::ATTRIBUTE_CODE);
        if (!$attribute || !$attribute->getAttributeId()) {
            return false;
        }

        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from($attribute->getBackendTable(), 'value')
            ->where('attribute_id = ?', (int) $attribute->getAttributeId())
            ->where('entity_id IN (?)', $childIds)
            ->where('store_id = ?', Store::DEFAULT_STORE_ID)
            ->where('value = ?', 1)
            ->limit(1);

        return (bool) $connection->fetchOne($select);
    }

    /**
     * Flatten child id groups for a parent product.
     *
     * @param int    $parentProductId
     * @param string $type
     * @return array
     */
    private function getChildIds(int $parentProductId, string $type): array
    {
        $childGroups = match ($type) {
            'configurable' => $this->configurableType->getChildrenIds($parentProductId),
            'grouped'      => $this->groupedType->getChildrenIds($parentProductId),
            'bundle'       => $this->bundleType->getChildrenIds($parentProductId),
            default        => [],
        };

        $flatIds = [];
        foreach ($childGroups as $group) {
            foreach ((array) $group as $id) {
                $flatIds[] = (int) $id;
            }
        }

        return $flatIds;
    }
}
