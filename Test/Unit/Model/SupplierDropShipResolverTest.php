<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Test\Unit\Model;

use ETechFlow\NextDayEligibility\Model\Config;
use ETechFlow\NextDayEligibility\Model\SupplierDropShipResolver;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SupplierDropShipResolver — the supplier-based drop-ship
 * detection wired in for NDE v1.5.0.
 *
 * The resolver is intentionally schema-agnostic: it reads whichever
 * attribute codes the admin configured. The tests assert that:
 *   - no config / empty config returns false (the supplier mode is off);
 *   - a configured pair with active=1 + matching name returns true;
 *   - a configured pair with active=0 returns false;
 *   - a configured pair with non-matching name returns false;
 *   - case-insensitive + whitespace-tolerant match;
 *   - multiple pairs — any matching one wins;
 *   - missing/invalid attribute doesn't throw and doesn't match.
 */
class SupplierDropShipResolverTest extends TestCase
{
    /** @var Config|MockObject */
    private Config|MockObject $config;

    /** @var ProductCollectionFactory|MockObject */
    private ProductCollectionFactory|MockObject $productCollectionFactory;

    /** @var LoggerInterface|MockObject */
    private LoggerInterface|MockObject $logger;

    /** @var SupplierDropShipResolver */
    private SupplierDropShipResolver $resolver;

    protected function setUp(): void
    {
        $this->config                   = $this->createMock(Config::class);
        $this->productCollectionFactory = $this->createMock(ProductCollectionFactory::class);
        $this->logger                   = $this->createMock(LoggerInterface::class);

        $this->resolver = new SupplierDropShipResolver(
            $this->config,
            $this->productCollectionFactory,
            $this->logger
        );
    }

    public function testReturnsFalseWhenNoPairsConfigured(): void
    {
        $this->config->method('getSupplierAttributePairs')->willReturn([]);
        $this->config->method('getQualifyingSupplierNames')->willReturn(['Auto remote man']);

        // No collection load expected when there's no work to do.
        $this->productCollectionFactory->expects($this->never())->method('create');

        $this->assertFalse($this->resolver->isDropShipEligible(42));
    }

    public function testReturnsFalseWhenNoQualifyingNamesConfigured(): void
    {
        $this->config->method('getSupplierAttributePairs')->willReturn([
            ['active' => 's1_active', 'name' => 's1'],
        ]);
        $this->config->method('getQualifyingSupplierNames')->willReturn([]);

        $this->productCollectionFactory->expects($this->never())->method('create');

        $this->assertFalse($this->resolver->isDropShipEligible(42));
    }

    public function testReturnsTrueWhenActivePairAndMatchingName(): void
    {
        $this->configureSupplierMode();
        $this->mockProductWithAttributes(42, [
            's1_active' => 1,
            's1'        => 'Auto remote man',
        ]);

        $this->assertTrue($this->resolver->isDropShipEligible(42));
    }

    public function testReturnsFalseWhenActiveIsZero(): void
    {
        $this->configureSupplierMode();
        $this->mockProductWithAttributes(42, [
            's1_active' => 0,
            's1'        => 'Auto remote man',
        ]);

        $this->assertFalse($this->resolver->isDropShipEligible(42));
    }

    public function testReturnsFalseWhenNameNotInQualifyingList(): void
    {
        $this->configureSupplierMode();
        $this->mockProductWithAttributes(42, [
            's1_active' => 1,
            's1'        => 'OnlyDa',
        ]);

        $this->assertFalse($this->resolver->isDropShipEligible(42));
    }

    public function testMatchIsCaseInsensitiveAndWhitespaceTolerant(): void
    {
        $this->configureSupplierMode();
        $this->mockProductWithAttributes(42, [
            's1_active' => 1,
            's1'        => '   AUTO REMOTE MAN   ',
        ]);

        $this->assertTrue($this->resolver->isDropShipEligible(42));
    }

    public function testReturnsTrueWhenAnyOfManyPairsMatches(): void
    {
        $this->config->method('getSupplierAttributePairs')->willReturn([
            ['active' => 's1_active', 'name' => 's1'],
            ['active' => 's2_active', 'name' => 's2'],
            ['active' => 's3_active', 'name' => 's3'],
        ]);
        $this->config->method('getQualifyingSupplierNames')->willReturn(['Auto remote man']);

        // S1 active but wrong name, S2 inactive, S3 active + matching name.
        $this->mockProductWithAttributes(42, [
            's1_active' => 1,
            's1'        => 'OnlyDa',
            's2_active' => 0,
            's2'        => 'Auto remote man',
            's3_active' => 1,
            's3'        => 'Auto remote man',
        ]);

        $this->assertTrue($this->resolver->isDropShipEligible(42));
    }

    public function testMissingAttributeDoesNotMatchAndDoesNotThrow(): void
    {
        $this->configureSupplierMode();
        // s1_active not set on the product at all — getData returns null
        $this->mockProductWithAttributes(42, [
            's1' => 'Auto remote man',
        ]);

        $this->assertFalse($this->resolver->isDropShipEligible(42));
    }

    public function testNonStringNameAttributeIsSkippedQuietly(): void
    {
        $this->configureSupplierMode();
        // Some attribute setups return option ids (ints) instead of labels.
        // Resolver should skip rather than crash or false-match.
        $this->mockProductWithAttributes(42, [
            's1_active' => 1,
            's1'        => 12345,  // option id, not a label string
        ]);

        $this->logger->expects($this->atLeastOnce())->method('debug');

        $this->assertFalse($this->resolver->isDropShipEligible(42));
    }

    public function testPerRequestCacheReturnsSameAnswerWithoutReload(): void
    {
        $this->configureSupplierMode();
        // We expect the collection factory to be called exactly once even
        // though we evaluate the same product twice.
        $this->mockProductWithAttributes(
            42,
            ['s1_active' => 1, 's1' => 'Auto remote man'],
            $expectCalls = 1
        );

        $this->assertTrue($this->resolver->isDropShipEligible(42));
        $this->assertTrue($this->resolver->isDropShipEligible(42));
    }

    public function testProductNotFoundReturnsFalse(): void
    {
        $this->configureSupplierMode();

        $emptyProduct = $this->createMock(Product::class);
        $emptyProduct->method('getId')->willReturn(null);

        $collection = $this->createMock(ProductCollection::class);
        $collection->method('addIdFilter')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($emptyProduct);

        $this->productCollectionFactory->method('create')->willReturn($collection);

        $this->assertFalse($this->resolver->isDropShipEligible(99999));
    }

    /**
     * Standard supplier-mode config: one pair (s1_active:s1) + one qualifying
     * name (Auto remote man). The typical Keystation setup, but the resolver
     * doesn't know that — it's just data.
     */
    private function configureSupplierMode(): void
    {
        $this->config->method('getSupplierAttributePairs')->willReturn([
            ['active' => 's1_active', 'name' => 's1'],
        ]);
        $this->config->method('getQualifyingSupplierNames')->willReturn(['Auto remote man']);
    }

    /**
     * Wire the product collection mock to return a Product whose getData()
     * returns the given attribute map. Verifies the load is called
     * $expectCalls times (default 1).
     *
     * @param int                  $productId
     * @param array<string, mixed> $attributes
     * @param int                  $expectCalls
     */
    private function mockProductWithAttributes(int $productId, array $attributes, int $expectCalls = 1): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($productId);
        $product->method('getData')->willReturnCallback(
            static fn(string $key) => $attributes[$key] ?? null
        );

        $collection = $this->createMock(ProductCollection::class);
        $collection->method('addIdFilter')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($product);

        $this->productCollectionFactory
            ->expects($this->exactly($expectCalls))
            ->method('create')
            ->willReturn($collection);
    }
}
