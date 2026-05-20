<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Test\Unit\Model;

use ETechFlow\NextDayEligibility\Model\Config;
use ETechFlow\NextDayEligibility\Model\EligibilityEvaluator;
use ETechFlow\NextDayEligibility\Model\SupplierDropShipResolver;
use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedType;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the 3-step eligibility precedence introduced in v1.4.0:
 *   1. force_standard_shipping_only = 1  =>  ALWAYS ineligible
 *   2. drop_ship_eligible           = 1  =>  ALWAYS eligible
 *   3. stock check                       =>  qty > 0 = eligible
 *
 * The evaluator persists the result via ProductAction::updateAttributes, so we
 * spy on that call to confirm the correct next_day_eligible value (0 or 1) was
 * written for each scenario.
 *
 * All parent-propagation paths are exercised against products with no parents,
 * so the configurable/grouped/bundle type stubs return empty arrays.
 */
class EligibilityEvaluatorTest extends TestCase
{
    private ProductAction|MockObject $productAction;
    private StockRegistryInterface|MockObject $stockRegistry;
    private ConfigurableType|MockObject $configurableType;
    private GroupedType|MockObject $groupedType;
    private BundleType|MockObject $bundleType;
    private ProductCollectionFactory|MockObject $productCollectionFactory;
    private ResourceConnection|MockObject $resourceConnection;
    private EavConfig|MockObject $eavConfig;
    private Config|MockObject $config;
    private SupplierDropShipResolver|MockObject $supplierResolver;
    private EligibilityEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->productAction            = $this->createMock(ProductAction::class);
        $this->stockRegistry            = $this->createMock(StockRegistryInterface::class);
        $this->configurableType         = $this->createMock(ConfigurableType::class);
        $this->groupedType              = $this->createMock(GroupedType::class);
        $this->bundleType               = $this->createMock(BundleType::class);
        $this->productCollectionFactory = $this->createMock(ProductCollectionFactory::class);
        $this->resourceConnection       = $this->createMock(ResourceConnection::class);
        $this->eavConfig                = $this->createMock(EavConfig::class);
        $this->config                   = $this->createMock(Config::class);
        $this->supplierResolver         = $this->createMock(SupplierDropShipResolver::class);

        // No parent products in any of the precedence tests
        $this->configurableType->method('getParentIdsByChild')->willReturn([]);
        $this->groupedType->method('getParentIdsByChild')->willReturn([]);
        $this->bundleType->method('getParentIdsByChild')->willReturn([]);

        // Default: supplier mode OFF. Individual v1.5 tests override.
        $this->config->method('getDropShipSource')->willReturn(Config::DROP_SHIP_SOURCE_FLAG);
        $this->supplierResolver->method('isDropShipEligible')->willReturn(false);

        $this->evaluator = new EligibilityEvaluator(
            $this->productAction,
            $this->stockRegistry,
            $this->configurableType,
            $this->groupedType,
            $this->bundleType,
            $this->productCollectionFactory,
            $this->resourceConnection,
            $this->eavConfig,
            $this->config,
            $this->supplierResolver
        );
    }

    /**
     * Build a stock item mock with the given in-stock + qty state.
     */
    private function buildStockItem(bool $isInStock, float $qty): StockItemInterface|MockObject
    {
        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getItemId')->willReturn(1);
        $stockItem->method('getIsInStock')->willReturn($isInStock);
        $stockItem->method('getQty')->willReturn($qty);
        return $stockItem;
    }

    /**
     * Stub the product-collection factory to return a single product carrying
     * the given drop_ship_eligible + force_standard_shipping_only attribute values.
     */
    private function stubAttributeCollection(int $dropShip, int $forceStandard): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')
            ->willReturnCallback(static function ($key) use ($dropShip, $forceStandard) {
                return match ($key) {
                    'drop_ship_eligible'           => $dropShip,
                    'force_standard_shipping_only' => $forceStandard,
                    default                        => null,
                };
            });

        $collection = $this->createMock(ProductCollection::class);
        $collection->method('addIdFilter')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($product);

        $this->productCollectionFactory->method('create')->willReturn($collection);
    }

    /**
     * Assert that updateAttributes was called with the expected next_day_eligible value.
     *
     * @param int $expectedValue 1 (eligible) or 0 (ineligible)
     */
    private function expectAttributeWrittenAs(int $expectedValue, int $productId = 42): void
    {
        $this->productAction->expects($this->once())
            ->method('updateAttributes')
            ->with([$productId], ['next_day_eligible' => $expectedValue], Store::DEFAULT_STORE_ID);
    }

    // -----------------------------------------------------------------
    // Precedence 1: force-standard-only override
    // -----------------------------------------------------------------

    public function testForceStandardOnlyOverridesEverythingElse(): void
    {
        // In stock + drop-ship eligible — would normally be eligible by either rule,
        // but force-standard wins and forces ineligible
        $this->stubAttributeCollection(dropShip: 1, forceStandard: 1);
        $this->expectAttributeWrittenAs(0);

        $this->evaluator->evaluate(42, $this->buildStockItem(isInStock: true, qty: 50.0));
    }

    public function testForceStandardOnlyForcesIneligibleEvenWhenInStock(): void
    {
        // In stock, plenty of qty, no drop-ship — but force-standard set
        $this->stubAttributeCollection(dropShip: 0, forceStandard: 1);
        $this->expectAttributeWrittenAs(0);

        $this->evaluator->evaluate(42, $this->buildStockItem(isInStock: true, qty: 100.0));
    }

    // -----------------------------------------------------------------
    // Precedence 2: drop-ship eligible
    // -----------------------------------------------------------------

    public function testDropShipEligibleMakesProductEligibleEvenWhenOutOfStock(): void
    {
        // Out of stock, but drop-ship — supplier ships direct
        $this->stubAttributeCollection(dropShip: 1, forceStandard: 0);
        $this->expectAttributeWrittenAs(1);

        $this->evaluator->evaluate(42, $this->buildStockItem(isInStock: false, qty: 0.0));
    }

    public function testDropShipEligibleMakesProductEligibleWhenInStock(): void
    {
        $this->stubAttributeCollection(dropShip: 1, forceStandard: 0);
        $this->expectAttributeWrittenAs(1);

        $this->evaluator->evaluate(42, $this->buildStockItem(isInStock: true, qty: 10.0));
    }

    // -----------------------------------------------------------------
    // Precedence 3: stock check
    // -----------------------------------------------------------------

    public function testInStockWithQtyMakesProductEligible(): void
    {
        $this->stubAttributeCollection(dropShip: 0, forceStandard: 0);
        $this->expectAttributeWrittenAs(1);

        $this->evaluator->evaluate(42, $this->buildStockItem(isInStock: true, qty: 10.0));
    }

    public function testOutOfStockMakesProductIneligible(): void
    {
        $this->stubAttributeCollection(dropShip: 0, forceStandard: 0);
        $this->expectAttributeWrittenAs(0);

        $this->evaluator->evaluate(42, $this->buildStockItem(isInStock: false, qty: 0.0));
    }

    public function testInStockButZeroQtyMakesProductIneligible(): void
    {
        // Edge case: is_in_stock = true but qty actually 0 (Magento's odd intermediate state)
        $this->stubAttributeCollection(dropShip: 0, forceStandard: 0);
        $this->expectAttributeWrittenAs(0);

        $this->evaluator->evaluate(42, $this->buildStockItem(isInStock: true, qty: 0.0));
    }

    public function testStockFlaggedOutOfStockOverridesPositiveQty(): void
    {
        // Edge case: qty > 0 but is_in_stock = false (manually flagged OOS by merchant)
        $this->stubAttributeCollection(dropShip: 0, forceStandard: 0);
        $this->expectAttributeWrittenAs(0);

        $this->evaluator->evaluate(42, $this->buildStockItem(isInStock: false, qty: 50.0));
    }

    // -----------------------------------------------------------------
    // Precedence 3 (v1.5+): supplier-based drop-ship detection
    // -----------------------------------------------------------------

    public function testSupplierMatchMakesOutOfStockProductEligibleWhenSupplierModeOn(): void
    {
        // Reset the default supplier-resolver stub to return TRUE for product 42.
        $this->supplierResolver = $this->createMock(SupplierDropShipResolver::class);
        $this->supplierResolver->method('isDropShipEligible')->with(42)->willReturn(true);

        $this->config = $this->createMock(Config::class);
        $this->config->method('getDropShipSource')->willReturn(Config::DROP_SHIP_SOURCE_SUPPLIER);

        $this->evaluator = new EligibilityEvaluator(
            $this->productAction,
            $this->stockRegistry,
            $this->configurableType,
            $this->groupedType,
            $this->bundleType,
            $this->productCollectionFactory,
            $this->resourceConnection,
            $this->eavConfig,
            $this->config,
            $this->supplierResolver
        );

        // Out of stock, no manual flag — supplier match should win
        $this->stubAttributeCollection(dropShip: 0, forceStandard: 0);
        $this->expectAttributeWrittenAs(1);

        $this->evaluator->evaluate(42, $this->buildStockItem(isInStock: false, qty: 0.0));
    }

    public function testSupplierResolverIgnoredWhenSourceModeIsFlag(): void
    {
        // Default setUp() has source=flag, resolver returns false. Even if the
        // resolver would have matched, the evaluator must not consult it.
        $this->supplierResolver = $this->createMock(SupplierDropShipResolver::class);
        $this->supplierResolver->expects($this->never())->method('isDropShipEligible');

        $this->config = $this->createMock(Config::class);
        $this->config->method('getDropShipSource')->willReturn(Config::DROP_SHIP_SOURCE_FLAG);

        $this->evaluator = new EligibilityEvaluator(
            $this->productAction,
            $this->stockRegistry,
            $this->configurableType,
            $this->groupedType,
            $this->bundleType,
            $this->productCollectionFactory,
            $this->resourceConnection,
            $this->eavConfig,
            $this->config,
            $this->supplierResolver
        );

        // Out of stock, no manual flag, source=flag → ineligible
        $this->stubAttributeCollection(dropShip: 0, forceStandard: 0);
        $this->expectAttributeWrittenAs(0);

        $this->evaluator->evaluate(42, $this->buildStockItem(isInStock: false, qty: 0.0));
    }

    public function testManualFlagStillWinsWhenSupplierModeOnButProductNotASupplierMatch(): void
    {
        // Source=supplier but the resolver says no — manual flag should still
        // grant eligibility. Confirms the flag is an override regardless of mode.
        $this->supplierResolver = $this->createMock(SupplierDropShipResolver::class);
        $this->supplierResolver->method('isDropShipEligible')->willReturn(false);

        $this->config = $this->createMock(Config::class);
        $this->config->method('getDropShipSource')->willReturn(Config::DROP_SHIP_SOURCE_SUPPLIER);

        $this->evaluator = new EligibilityEvaluator(
            $this->productAction,
            $this->stockRegistry,
            $this->configurableType,
            $this->groupedType,
            $this->bundleType,
            $this->productCollectionFactory,
            $this->resourceConnection,
            $this->eavConfig,
            $this->config,
            $this->supplierResolver
        );

        // Out of stock, manual drop-ship flag = 1, supplier doesn't match → eligible via flag
        $this->stubAttributeCollection(dropShip: 1, forceStandard: 0);
        $this->expectAttributeWrittenAs(1);

        $this->evaluator->evaluate(42, $this->buildStockItem(isInStock: false, qty: 0.0));
    }

    public function testForceStandardStillBeatsSupplierMatch(): void
    {
        // Even when supplier mode is on AND the resolver matches, force_standard
        // remains the top precedence.
        $this->supplierResolver = $this->createMock(SupplierDropShipResolver::class);
        $this->supplierResolver->method('isDropShipEligible')->willReturn(true);

        $this->config = $this->createMock(Config::class);
        $this->config->method('getDropShipSource')->willReturn(Config::DROP_SHIP_SOURCE_SUPPLIER);

        $this->evaluator = new EligibilityEvaluator(
            $this->productAction,
            $this->stockRegistry,
            $this->configurableType,
            $this->groupedType,
            $this->bundleType,
            $this->productCollectionFactory,
            $this->resourceConnection,
            $this->eavConfig,
            $this->config,
            $this->supplierResolver
        );

        $this->stubAttributeCollection(dropShip: 0, forceStandard: 1);
        $this->expectAttributeWrittenAs(0);

        $this->evaluator->evaluate(42, $this->buildStockItem(isInStock: false, qty: 0.0));
    }

    // -----------------------------------------------------------------
    // Conflict resolution
    // -----------------------------------------------------------------

    public function testForceStandardBeatsDropShipWhenBothSet(): void
    {
        // Both flags set — merchant override beats drop-ship by design
        $this->stubAttributeCollection(dropShip: 1, forceStandard: 1);
        $this->expectAttributeWrittenAs(0);

        $this->evaluator->evaluate(42, $this->buildStockItem(isInStock: false, qty: 0.0));
    }

    // -----------------------------------------------------------------
    // evaluateById entry point
    // -----------------------------------------------------------------

    public function testEvaluateByIdLoadsStockAndDelegates(): void
    {
        $stockItem = $this->buildStockItem(isInStock: true, qty: 5.0);
        $this->stockRegistry->method('getStockItem')->with(42)->willReturn($stockItem);

        $this->stubAttributeCollection(dropShip: 0, forceStandard: 0);
        $this->expectAttributeWrittenAs(1);

        $this->evaluator->evaluateById(42);
    }

    public function testEvaluateByIdBailsWhenStockItemMissing(): void
    {
        // A product without a stock item (shouldn't happen in practice but defensive)
        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getItemId')->willReturn(null);
        $this->stockRegistry->method('getStockItem')->willReturn($stockItem);

        $this->productAction->expects($this->never())->method('updateAttributes');

        $this->evaluator->evaluateById(42);
    }
}
