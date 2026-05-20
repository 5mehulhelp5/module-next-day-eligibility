<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Test\Unit\Model;

use ETechFlow\NextDayEligibility\Model\BackorderChecker;
use ETechFlow\NextDayEligibility\Model\Config;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for BackorderChecker — the cart-scan logic folded in from the
 * deprecated BackorderShippingRestrictor module in NDE v1.1.0.
 */
class BackorderCheckerTest extends TestCase
{
    /** @var StockRegistryInterface|MockObject */
    private StockRegistryInterface|MockObject $stockRegistry;

    /** @var Config|MockObject */
    private Config|MockObject $config;

    /** @var ProductCollectionFactory|MockObject */
    private ProductCollectionFactory|MockObject $productCollectionFactory;

    /** @var LoggerInterface|MockObject */
    private LoggerInterface|MockObject $logger;

    /** @var BackorderChecker */
    private BackorderChecker $checker;

    protected function setUp(): void
    {
        $this->stockRegistry            = $this->createMock(StockRegistryInterface::class);
        $this->config                   = $this->createMock(Config::class);
        $this->productCollectionFactory = $this->createMock(ProductCollectionFactory::class);
        $this->logger                   = $this->createMock(LoggerInterface::class);

        // Default: skip-drop-ship-for-backorder is OFF in this baseline checker.
        // Individual tests construct a 2nd checker with it ON when needed.
        $this->config->method('isSkipDropShipForBackorder')->willReturn(false);

        $this->checker = new BackorderChecker(
            $this->stockRegistry,
            $this->config,
            $this->productCollectionFactory,
            $this->logger
        );
    }

    /**
     * @param string $productType
     * @param int    $productId
     * @param float  $qty
     * @param int    $websiteId
     * @return QuoteItem|MockObject
     */
    private function buildItem(
        string $productType,
        int $productId = 1,
        float $qty = 1.0,
        int $websiteId = 1
    ): QuoteItem|MockObject {
        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn($websiteId);

        $quote = $this->createMock(Quote::class);
        $quote->method('getStore')->willReturn($store);

        $item = $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->addMethods(['getProductId'])
            ->onlyMethods(['isDeleted', 'getProductType', 'getQty', 'getQuote'])
            ->getMock();
        $item->method('isDeleted')->willReturn(false);
        $item->method('getProductType')->willReturn($productType);
        $item->method('getProductId')->willReturn($productId);
        $item->method('getQty')->willReturn($qty);
        $item->method('getQuote')->willReturn($quote);

        return $item;
    }

    /**
     * @param bool  $isInStock
     * @param float $qty
     * @param float $minQty
     * @param int   $backorders
     * @return StockItemInterface|MockObject
     */
    private function buildStockItem(
        bool $isInStock,
        float $qty,
        float $minQty = 0.0,
        int $backorders = 0
    ): StockItemInterface|MockObject {
        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getItemId')->willReturn(1);
        $stockItem->method('getIsInStock')->willReturn($isInStock);
        $stockItem->method('getQty')->willReturn($qty);
        $stockItem->method('getMinQty')->willReturn($minQty);
        $stockItem->method('getBackorders')->willReturn($backorders);

        return $stockItem;
    }

    /**
     * @param array<int, bool> $dropShipMap product-id => drop-ship-eligible flag
     */
    private function stubDropShipCollection(array $dropShipMap): void
    {
        $products = [];
        foreach ($dropShipMap as $productId => $isDropShip) {
            $product = $this->createMock(Product::class);
            $product->method('getId')->willReturn($productId);
            $product->method('getData')
                ->with('drop_ship_eligible')
                ->willReturn($isDropShip ? 1 : 0);
            $products[] = $product;
        }

        $collection = $this->createMock(ProductCollection::class);
        $collection->method('addIdFilter')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($products));

        $this->productCollectionFactory->method('create')->willReturn($collection);
    }

    public function testContainerTypesAreSkipped(): void
    {
        $configurableItem = $this->buildItem('configurable');
        $bundleItem       = $this->buildItem('bundle');

        $this->stockRegistry->expects($this->never())->method('getStockItem');

        $this->assertFalse($this->checker->hasBackorderItems([$configurableItem, $bundleItem]));
    }

    public function testVirtualTypesAreSkipped(): void
    {
        $virtualItem      = $this->buildItem('virtual');
        $downloadableItem = $this->buildItem('downloadable');

        $this->stockRegistry->expects($this->never())->method('getStockItem');

        $this->assertFalse($this->checker->hasBackorderItems([$virtualItem, $downloadableItem]));
    }

    public function testDeletedItemsAreSkipped(): void
    {
        $item = $this->createMock(QuoteItem::class);
        $item->method('isDeleted')->willReturn(true);

        $this->stockRegistry->expects($this->never())->method('getStockItem');

        $this->assertFalse($this->checker->hasBackorderItems([$item]));
    }

    public function testReturnsTrueWhenItemIsOutOfStock(): void
    {
        $item      = $this->buildItem('simple', 10);
        $stockItem = $this->buildStockItem(false, 0.0);

        $this->stockRegistry->method('getStockItem')->willReturn($stockItem);

        $this->assertTrue($this->checker->hasBackorderItems([$item]));
    }

    public function testReturnsTrueWhenBackordersEnabledAndQtyDepletedBelowMinQty(): void
    {
        $item      = $this->buildItem('simple', 10);
        $stockItem = $this->buildStockItem(true, 0.0, 0.0, 1);

        $this->stockRegistry->method('getStockItem')->willReturn($stockItem);

        $this->assertTrue($this->checker->hasBackorderItems([$item]));
    }

    public function testReturnsTrueWhenOrderedQtyExceedsSaleableQty(): void
    {
        // stock=3, min=0, ordered=5 → partial backorder
        $item      = $this->buildItem('simple', 10, 5.0);
        $stockItem = $this->buildStockItem(true, 3.0, 0.0, 0);

        $this->stockRegistry->method('getStockItem')->willReturn($stockItem);

        $this->assertTrue($this->checker->hasBackorderItems([$item]));
    }

    public function testReturnsFalseWhenInStockAndQtySufficient(): void
    {
        $item      = $this->buildItem('simple', 10, 2.0);
        $stockItem = $this->buildStockItem(true, 10.0, 0.0, 0);

        $this->stockRegistry->method('getStockItem')->willReturn($stockItem);

        $this->assertFalse($this->checker->hasBackorderItems([$item]));
    }

    public function testReturnsFalseWhenStockItemHasNoItemId(): void
    {
        $item = $this->buildItem('simple', 10);

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getItemId')->willReturn(null);

        $this->stockRegistry->method('getStockItem')->willReturn($stockItem);

        $this->assertFalse($this->checker->hasBackorderItems([$item]));
    }

    public function testEmptyItemListReturnsFalse(): void
    {
        $this->assertFalse($this->checker->hasBackorderItems([]));
    }

    public function testSkipDropShipExemptsDropShipProductFromBackorderCheck(): void
    {
        // Skip-drop-ship turned ON for this scenario
        $config = $this->createMock(Config::class);
        $config->method('isSkipDropShipForBackorder')->willReturn(true);

        $checker = new BackorderChecker(
            $this->stockRegistry,
            $config,
            $this->productCollectionFactory,
            $this->logger
        );

        $item = $this->buildItem('simple', 10);
        $this->stubDropShipCollection([10 => true]);

        // Stock check must NOT be reached — drop-ship exemption short-circuits
        $this->stockRegistry->expects($this->never())->method('getStockItem');

        $this->assertFalse($checker->hasBackorderItems([$item]));
    }

    public function testSkipDropShipStillFlagsNonDropShipBackorder(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isSkipDropShipForBackorder')->willReturn(true);

        $checker = new BackorderChecker(
            $this->stockRegistry,
            $config,
            $this->productCollectionFactory,
            $this->logger
        );

        // drop_ship_eligible = false, OOS → still flagged
        $item      = $this->buildItem('simple', 10);
        $stockItem = $this->buildStockItem(false, 0.0);

        $this->stubDropShipCollection([10 => false]);
        $this->stockRegistry->method('getStockItem')->willReturn($stockItem);

        $this->assertTrue($checker->hasBackorderItems([$item]));
    }
}
