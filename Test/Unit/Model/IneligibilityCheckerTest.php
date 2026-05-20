<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Test\Unit\Model;

use ETechFlow\NextDayEligibility\Model\IneligibilityChecker;
use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins the v1.4.4 NULL-handling fix.
 *
 * Before v1.4.4: `addAttributeToFilter` was called with
 * `[neq=1 OR null=true]`, which flagged every product that had never had
 * its eligibility explicitly saved as INELIGIBLE — contradicting the
 * admin form's `default_value=1` "Yes" pre-fill. The effect was that
 * brand-new installs silently triggered the next-day filter on every
 * cart, until a merchant explicitly clicked "Yes" on every product.
 *
 * v1.4.4: the filter is now `eq=0` — only explicitly-rejected products
 * count as ineligible. NULL rows fall through as eligible, matching
 * the admin default.
 *
 * Tests assert the SQL filter shape (because the collection is fully
 * mocked) AND the leaf-item-collection logic (container product types
 * skipped, deleted items skipped, configurable children harvested).
 */
class IneligibilityCheckerTest extends TestCase
{
    private ProductCollectionFactory|MockObject $collectionFactory;
    private ProductCollection|MockObject $collection;
    private IneligibilityChecker $checker;

    /** Records every addAttributeToFilter call on the mocked collection. */
    private array $recordedFilters = [];

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(ProductCollectionFactory::class);
        $this->collection        = $this->createMock(ProductCollection::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);

        // Record every filter call so test assertions can inspect the shape
        $this->collection->method('addAttributeToFilter')->willReturnCallback(
            function ($attribute, $condition = null) {
                $this->recordedFilters[] = compact('attribute', 'condition');
                return $this->collection;
            }
        );
        $this->collection->method('addIdFilter')->willReturnSelf();
        $this->collection->method('setPageSize')->willReturnSelf();

        $this->checker = new IneligibilityChecker($this->collectionFactory);
    }

    /**
     * Stub a quote item with the given product id + type + deleted flag.
     */
    private function buildItem(int $productId, string $type = 'simple', bool $deleted = false): Item
    {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isDeleted', 'getProductType'])
            ->addMethods(['getProductId'])
            ->getMock();
        $item->method('isDeleted')->willReturn($deleted);
        $item->method('getProductType')->willReturn($type);
        $item->method('getProductId')->willReturn($productId);
        return $item;
    }

    // -----------------------------------------------------------------
    // v1.4.4 — SQL filter shape (the NULL-handling fix)
    // -----------------------------------------------------------------

    public function testFilterMatchesNeqOneOrNull(): void
    {
        // Mock the collection to report zero ineligible matches (so the
        // method short-circuits AFTER the filter is applied).
        $this->collection->method('getSize')->willReturn(0);

        $this->checker->hasIneligibleItems([$this->buildItem(42)]);

        // v1.4.3 filter shape: a product is ineligible when next_day_eligible
        // is not 1 OR is NULL (newly imported, never evaluated). Magento's
        // collection accepts an OR by passing an array of [attribute, ...cond]
        // dicts as the FIRST argument to addAttributeToFilter (condition arg
        // is unused in that form).
        $this->assertCount(1, $this->recordedFilters,
            'Expected exactly one addAttributeToFilter call');
        $call = $this->recordedFilters[0];
        $this->assertSame(
            [
                ['attribute' => 'next_day_eligible', 'neq' => 1],
                ['attribute' => 'next_day_eligible', 'null' => true],
            ],
            $call['attribute'],
            'v1.4.3 filter shape: NULL counts as ineligible — products that have '
            . 'never been evaluated default to "not eligible" until the evaluator '
            . 'has run on them.'
        );
        $this->assertNull($call['condition'],
            'When using the OR-array form, the second condition argument is unused.');
    }

    public function testFilterShapeStableRegardlessOfItemCount(): void
    {
        // Same SQL filter regardless of how many cart items are passed in.
        $this->collection->method('getSize')->willReturn(0);

        $this->checker->hasIneligibleItems([
            $this->buildItem(1),
            $this->buildItem(2),
            $this->buildItem(3),
        ]);

        $this->assertCount(1, $this->recordedFilters);
        $this->assertSame(
            [
                ['attribute' => 'next_day_eligible', 'neq' => 1],
                ['attribute' => 'next_day_eligible', 'null' => true],
            ],
            $this->recordedFilters[0]['attribute']
        );
    }

    // -----------------------------------------------------------------
    // Eligibility decision based on collection's reported size
    // -----------------------------------------------------------------

    public function testReturnsFalseWhenCollectionReportsZeroMatches(): void
    {
        // Cart has 1 product. After the eq=0 filter, no rows come back.
        // Result: no ineligible items.
        $this->collection->method('getSize')->willReturn(0);
        $this->assertFalse(
            $this->checker->hasIneligibleItems([$this->buildItem(42)])
        );
    }

    public function testReturnsTrueWhenCollectionReportsAtLeastOneMatch(): void
    {
        // Cart has 1 product. After the eq=0 filter, 1 row comes back.
        // Result: HAS ineligible items.
        $this->collection->method('getSize')->willReturn(1);
        $this->assertTrue(
            $this->checker->hasIneligibleItems([$this->buildItem(42)])
        );
    }

    // -----------------------------------------------------------------
    // Empty / edge cases
    // -----------------------------------------------------------------

    public function testEmptyItemArrayReturnsFalseWithoutQuerying(): void
    {
        // No items → no query, no filter, false return.
        $this->collectionFactory->expects($this->never())->method('create');
        $this->assertFalse($this->checker->hasIneligibleItems([]));
        $this->assertEmpty($this->recordedFilters);
    }

    public function testDeletedItemsAreSkipped(): void
    {
        // A cart with one deleted item only — no live product ids to query.
        $this->collectionFactory->expects($this->never())->method('create');
        $this->assertFalse($this->checker->hasIneligibleItems([
            $this->buildItem(42, 'simple', deleted: true),
        ]));
    }

    public function testContainerProductTypesAreSkipped(): void
    {
        // Configurable + bundle + grouped parents are skipped. Their child
        // items appear separately in the cart with their real product type.
        $this->collectionFactory->expects($this->never())->method('create');
        $this->assertFalse($this->checker->hasIneligibleItems([
            $this->buildItem(1, ConfigurableType::TYPE_CODE),
            $this->buildItem(2, BundleType::TYPE_CODE),
            $this->buildItem(3, 'grouped'),
        ]));
    }

    public function testMixedCartCollectsOnlyLeafItems(): void
    {
        // 1 configurable parent (skipped) + 1 simple leaf (kept) + 1 simple
        // deleted (skipped) = 1 product id queried.
        $this->collection->method('getSize')->willReturn(0);

        $this->checker->hasIneligibleItems([
            $this->buildItem(100, ConfigurableType::TYPE_CODE),
            $this->buildItem(200, 'simple'),
            $this->buildItem(300, 'simple', deleted: true),
        ]);

        $this->assertCount(1, $this->recordedFilters,
            'Collection should be queried once for the one surviving simple item');
    }

    // -----------------------------------------------------------------
    // v1.5.1 — local-stock check for Click & Collect filtering
    // -----------------------------------------------------------------

    public function testHasItemsWithoutLocalStockReturnsFalseWhenNoItems(): void
    {
        // Empty cart short-circuits — no DB call at all. Saves the
        // joinField roundtrip for empty checkouts.
        $this->collectionFactory->expects($this->never())->method('create');
        $this->assertFalse($this->checker->hasItemsWithoutLocalStock([]));
    }

    public function testHasItemsWithoutLocalStockSkipsContainerTypes(): void
    {
        // Containers (configurable, bundle, grouped) carry no stock of
        // their own — their children do. Same skip logic as the next-day
        // check, so the C&C filter doesn't accidentally flag every cart
        // containing a configurable product.
        $this->collectionFactory->expects($this->never())->method('create');
        $this->assertFalse($this->checker->hasItemsWithoutLocalStock([
            $this->buildItem(1, ConfigurableType::TYPE_CODE),
            $this->buildItem(2, BundleType::TYPE_CODE),
            $this->buildItem(3, 'grouped'),
        ]));
    }

    public function testHasItemsWithoutLocalStockReturnsTrueWhenSizeGreaterThanZero(): void
    {
        $select = $this->createMock(\Magento\Framework\DB\Select::class);
        $select->method('where')->willReturnSelf();
        $this->collection->method('joinField')->willReturnSelf();
        $this->collection->method('getSelect')->willReturn($select);
        $this->collection->method('getSize')->willReturn(1);

        $this->assertTrue(
            $this->checker->hasItemsWithoutLocalStock([$this->buildItem(42, 'simple')])
        );
    }

    public function testHasItemsWithoutLocalStockReturnsFalseWhenSizeZero(): void
    {
        $select = $this->createMock(\Magento\Framework\DB\Select::class);
        $select->method('where')->willReturnSelf();
        $this->collection->method('joinField')->willReturnSelf();
        $this->collection->method('getSelect')->willReturn($select);
        $this->collection->method('getSize')->willReturn(0);

        $this->assertFalse(
            $this->checker->hasItemsWithoutLocalStock([$this->buildItem(42, 'simple')])
        );
    }

    public function testHasItemsWithoutLocalStockMakesOneBatchedQuery(): void
    {
        // Performance pin: regardless of cart size, the collection is
        // created exactly once. No N+1 — every cart item is in the single
        // IN-list query.
        $select = $this->createMock(\Magento\Framework\DB\Select::class);
        $select->method('where')->willReturnSelf();
        $this->collection->method('joinField')->willReturnSelf();
        $this->collection->method('getSelect')->willReturn($select);
        $this->collection->method('getSize')->willReturn(0);

        $this->collectionFactory->expects($this->once())->method('create');

        $this->checker->hasItemsWithoutLocalStock([
            $this->buildItem(1, 'simple'),
            $this->buildItem(2, 'simple'),
            $this->buildItem(3, 'simple'),
            $this->buildItem(4, 'simple'),
            $this->buildItem(5, 'simple'),
        ]);
    }

    public function testHasItemsWithoutLocalStockJoinsStockItemTable(): void
    {
        // Verifies the query joins cataloginventory_stock_item twice (once
        // for qty, once for is_in_stock) — same shape as the production
        // method. Catches a regression that would silently use product
        // table only and miss out-of-stock products.
        $select = $this->createMock(\Magento\Framework\DB\Select::class);
        $select->method('where')->willReturnSelf();

        $joinCalls = [];
        $this->collection->method('joinField')->willReturnCallback(
            function ($alias, $table, $field) use (&$joinCalls) {
                $joinCalls[] = compact('alias', 'table', 'field');
                return $this->collection;
            }
        );
        $this->collection->method('getSelect')->willReturn($select);
        $this->collection->method('getSize')->willReturn(0);

        $this->checker->hasItemsWithoutLocalStock([$this->buildItem(42, 'simple')]);

        $this->assertCount(2, $joinCalls, 'Should join the stock-item table twice (qty + is_in_stock)');
        $aliases = array_column($joinCalls, 'alias');
        $this->assertContains('qty', $aliases);
        $this->assertContains('is_in_stock', $aliases);
        foreach ($joinCalls as $call) {
            $this->assertSame('cataloginventory_stock_item', $call['table']);
        }
    }
}
