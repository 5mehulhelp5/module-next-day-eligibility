<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Test\Unit\Model;

use ETechFlow\NextDayEligibility\Model\Config;
use ETechFlow\NextDayEligibility\Model\SalableStockChecker;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\StockByWebsiteIdResolverInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SalableStockChecker — the reservation-aware (MSI) cart-line
 * shortfall check introduced in v1.9.0.
 */
class SalableStockCheckerTest extends TestCase
{
    /** @var GetProductSalableQtyInterface|MockObject */
    private GetProductSalableQtyInterface|MockObject $getProductSalableQty;

    /** @var StockByWebsiteIdResolverInterface|MockObject */
    private StockByWebsiteIdResolverInterface|MockObject $stockByWebsiteIdResolver;

    /** @var Config|MockObject */
    private Config|MockObject $config;

    /** @var ProductCollectionFactory|MockObject */
    private ProductCollectionFactory|MockObject $productCollectionFactory;

    /** @var LoggerInterface|MockObject */
    private LoggerInterface|MockObject $logger;

    /** @var SalableStockChecker */
    private SalableStockChecker $checker;

    protected function setUp(): void
    {
        $this->getProductSalableQty     = $this->createMock(GetProductSalableQtyInterface::class);
        $this->stockByWebsiteIdResolver = $this->createMock(StockByWebsiteIdResolverInterface::class);
        $this->config                   = $this->createMock(Config::class);
        $this->productCollectionFactory = $this->createMock(ProductCollectionFactory::class);
        $this->logger                   = $this->createMock(LoggerInterface::class);

        // Default: drop-ship exemption OFF (no collection lookups).
        $this->config->method('isSkipDropShipForBackorder')->willReturn(false);

        // Default stock resolution: website 1 → stock id 1.
        $stock = $this->createMock(StockInterface::class);
        $stock->method('getStockId')->willReturn(1);
        $this->stockByWebsiteIdResolver->method('execute')->willReturn($stock);

        $this->checker = new SalableStockChecker(
            $this->getProductSalableQty,
            $this->stockByWebsiteIdResolver,
            $this->config,
            $this->productCollectionFactory,
            $this->logger
        );
    }

    /**
     * @param string $productType
     * @param string $sku
     * @param int    $productId
     * @param float  $qty
     * @param int    $websiteId
     * @return QuoteItem|MockObject
     */
    private function buildItem(
        string $productType = 'simple',
        string $sku = 'SKU-1',
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
            ->addMethods(['getProductId', 'getSku'])
            ->onlyMethods(['isDeleted', 'getProductType', 'getQty', 'getQuote'])
            ->getMock();
        $item->method('isDeleted')->willReturn(false);
        $item->method('getProductType')->willReturn($productType);
        $item->method('getProductId')->willReturn($productId);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQty')->willReturn($qty);
        $item->method('getQuote')->willReturn($quote);

        return $item;
    }

    public function testShortfallWhenRequestedExceedsSalable(): void
    {
        // requested 5, salable 2 → shortfall
        $item = $this->buildItem('simple', 'SKU-1', 1, 5.0);
        $this->getProductSalableQty->method('execute')->with('SKU-1', 1)->willReturn(2.0);

        $this->assertTrue($this->checker->hasShortfall([$item]));
    }

    public function testNoShortfallWhenRequestedWithinSalable(): void
    {
        $item = $this->buildItem('simple', 'SKU-1', 1, 2.0);
        $this->getProductSalableQty->method('execute')->willReturn(5.0);

        $this->assertFalse($this->checker->hasShortfall([$item]));
    }

    public function testNoShortfallWhenRequestedEqualsSalable(): void
    {
        $item = $this->buildItem('simple', 'SKU-1', 1, 3.0);
        $this->getProductSalableQty->method('execute')->willReturn(3.0);

        $this->assertFalse($this->checker->hasShortfall([$item]));
    }

    public function testContainerAndVirtualItemsSkipped(): void
    {
        $items = [
            $this->buildItem('configurable'),
            $this->buildItem('bundle'),
            $this->buildItem('grouped'),
            $this->buildItem('virtual'),
            $this->buildItem('downloadable'),
        ];

        // No salable lookups happen for skipped types.
        $this->getProductSalableQty->expects($this->never())->method('execute');

        $this->assertFalse($this->checker->hasShortfall($items));
    }

    public function testMultiLineOneShortReturnsTrue(): void
    {
        $ok    = $this->buildItem('simple', 'SKU-OK', 1, 1.0);
        $short = $this->buildItem('simple', 'SKU-SHORT', 2, 9.0);

        $this->getProductSalableQty->method('execute')->willReturnMap([
            ['SKU-OK', 1, 10.0],
            ['SKU-SHORT', 1, 4.0],
        ]);

        $this->assertTrue($this->checker->hasShortfall([$ok, $short]));
    }

    public function testDropShipItemExemptWhenConfigured(): void
    {
        // With skip-drop-ship ON, a drop_ship_eligible product is exempt even
        // though its requested qty would otherwise exceed salable.
        $config = $this->createMock(Config::class);
        $config->method('isSkipDropShipForBackorder')->willReturn(true);

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(1);
        $product->method('getData')->with('drop_ship_eligible')->willReturn(1);

        $collection = $this->createMock(ProductCollection::class);
        $collection->method('addIdFilter')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$product]));

        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $checker = new SalableStockChecker(
            $this->getProductSalableQty,
            $this->stockByWebsiteIdResolver,
            $config,
            $factory,
            $this->logger
        );

        // Salable lookup must never run for the exempt line.
        $this->getProductSalableQty->expects($this->never())->method('execute');

        $item = $this->buildItem('simple', 'SKU-1', 1, 9.0);
        $this->assertFalse($checker->hasShortfall([$item]));
    }

    public function testMsiExceptionTreatedAsSatisfiable(): void
    {
        $item = $this->buildItem('simple', 'SKU-1', 1, 5.0);
        $this->getProductSalableQty->method('execute')
            ->willThrowException(new \RuntimeException('SKU not assigned to stock'));

        $this->logger->expects($this->once())->method('debug');

        // Never break checkout — treat the line as satisfiable.
        $this->assertFalse($this->checker->hasShortfall([$item]));
    }

    public function testEmptyCartReturnsFalse(): void
    {
        $this->getProductSalableQty->expects($this->never())->method('execute');
        $this->assertFalse($this->checker->hasShortfall([]));
    }
}
