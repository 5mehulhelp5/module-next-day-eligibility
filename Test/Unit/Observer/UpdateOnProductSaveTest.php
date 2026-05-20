<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Test\Unit\Observer;

use ETechFlow\NextDayEligibility\Model\BackorderManager;
use ETechFlow\NextDayEligibility\Model\Config;
use ETechFlow\NextDayEligibility\Model\EligibilityEvaluator;
use ETechFlow\NextDayEligibility\Observer\UpdateOnProductSave;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UpdateOnProductSaveTest extends TestCase
{
    /** @var Config|MockObject */
    private Config|MockObject $config;

    /** @var EligibilityEvaluator|MockObject */
    private EligibilityEvaluator|MockObject $evaluator;

    /** @var BackorderManager|MockObject */
    private BackorderManager|MockObject $backorderManager;

    /** @var LoggerInterface|MockObject */
    private LoggerInterface|MockObject $logger;

    /** @var MessageManager|MockObject */
    private MessageManager|MockObject $messageManager;

    /** @var AppState|MockObject */
    private AppState|MockObject $appState;

    /** @var StockRegistryInterface|MockObject */
    private StockRegistryInterface|MockObject $stockRegistry;

    /** @var UpdateOnProductSave */
    private UpdateOnProductSave $observer;

    protected function setUp(): void
    {
        $this->config           = $this->createMock(Config::class);
        $this->evaluator        = $this->createMock(EligibilityEvaluator::class);
        $this->backorderManager = $this->createMock(BackorderManager::class);
        $this->logger           = $this->createMock(LoggerInterface::class);
        $this->messageManager   = $this->createMock(MessageManager::class);
        $this->appState         = $this->createMock(AppState::class);
        $this->stockRegistry    = $this->createMock(StockRegistryInterface::class);

        // Default: tests do NOT run in adminhtml area, so the v1.4.1
        // contradictory-stock diagnostic short-circuits before touching
        // messageManager or stockRegistry. Individual diagnostic tests
        // override this expectation.
        $this->appState->method('getAreaCode')->willReturn('frontend');

        $this->observer = new UpdateOnProductSave(
            $this->config,
            $this->evaluator,
            $this->backorderManager,
            $this->logger,
            $this->messageManager,
            $this->appState,
            $this->stockRegistry
        );
    }

    /**
     * Build an Observer mock with an attached product.
     *
     * @param mixed $product
     * @return Observer|MockObject
     */
    private function buildObserver(mixed $product): Observer|MockObject
    {
        $event = $this->getMockBuilder(Event::class)
            ->disableOriginalConstructor()
            ->addMethods(['getProduct'])
            ->getMock();
        $event->method('getProduct')->willReturn($product);

        $mockObserver = $this->createMock(Observer::class);
        $mockObserver->method('getEvent')->willReturn($event);

        return $mockObserver;
    }

    /**
     * Build a product mock with given current and original drop-ship values.
     *
     * The observer's `execute()` calls `getData()` with several different keys
     * (drop_ship_eligible, _etechflow_skip_eligibility, _indexer_processing) so
     * we use willReturnCallback to handle all of them. Unknown keys return null.
     *
     * @param int|null $id
     * @param int      $newValue
     * @param int      $oldValue
     * @return Product|MockObject
     */
    private function buildProduct(?int $id, int $newValue, int $oldValue): Product|MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getData')
            ->willReturnCallback(static function ($key) use ($newValue) {
                return $key === 'drop_ship_eligible' ? $newValue : null;
            });
        $product->method('getOrigData')
            ->willReturnCallback(static function ($key) use ($oldValue) {
                return $key === 'drop_ship_eligible' ? $oldValue : null;
            });

        return $product;
    }

    public function testExecuteDoesNothingWhenModuleDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        $this->evaluator->expects($this->never())->method('evaluateById');

        $mockObserver = $this->createMock(Observer::class);
        $mockObserver->expects($this->never())->method('getEvent');

        $this->observer->execute($mockObserver);
    }

    public function testExecuteSkipsWhenProductHasNoId(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $product = $this->buildProduct(null, 1, 0);

        $this->evaluator->expects($this->never())->method('evaluateById');

        $this->observer->execute($this->buildObserver($product));
    }

    public function testExecuteSkipsWhenDropShipUnchanged(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        // Same value before and after — no change
        $product = $this->buildProduct(42, 1, 1);

        $this->evaluator->expects($this->never())->method('evaluateById');

        $this->observer->execute($this->buildObserver($product));
    }

    public function testExecuteRecomputesWhenDropShipFlippedOn(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        // Was 0, now 1 — drop-ship just enabled
        $product = $this->buildProduct(42, 1, 0);

        $this->evaluator->expects($this->once())
            ->method('evaluateById')
            ->with(42);

        $this->observer->execute($this->buildObserver($product));
    }

    public function testExecuteRecomputesWhenDropShipFlippedOff(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        // Was 1, now 0 — drop-ship just disabled
        $product = $this->buildProduct(42, 0, 1);

        $this->evaluator->expects($this->once())
            ->method('evaluateById')
            ->with(42);

        $this->observer->execute($this->buildObserver($product));
    }

    public function testExecuteHandlesNullProduct(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $this->evaluator->expects($this->never())->method('evaluateById');

        $this->observer->execute($this->buildObserver(null));
    }

    public function testExecuteLogsExceptionWithoutThrowing(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $product = $this->buildProduct(42, 1, 0);

        $this->evaluator->method('evaluateById')
            ->willThrowException(new \RuntimeException('DB error'));

        $this->logger->expects($this->once())->method('error');

        // Must not throw
        $this->observer->execute($this->buildObserver($product));
    }

    public function testExecuteSkipsWhenBulkImportFlagSet(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(42);
        $product->method('getData')->willReturnCallback(static function ($key) {
            // Drop-ship has flipped (would normally trigger evaluation)…
            if ($key === 'drop_ship_eligible')         return 1;
            // …but the bulk import skip flag is set, so we must NOT evaluate.
            if ($key === UpdateOnProductSave::SKIP_FLAG) return true;
            return null;
        });
        $product->method('getOrigData')->willReturnCallback(static function ($key) {
            return $key === 'drop_ship_eligible' ? 0 : null;
        });

        $this->evaluator->expects($this->never())->method('evaluateById');

        $this->observer->execute($this->buildObserver($product));
    }

    public function testExecuteSkipsWhenIndexerProcessing(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(42);
        $product->method('getData')->willReturnCallback(static function ($key) {
            if ($key === 'drop_ship_eligible')   return 1;
            if ($key === '_indexer_processing') return true;
            return null;
        });
        $product->method('getOrigData')->willReturnCallback(static function ($key) {
            return $key === 'drop_ship_eligible' ? 0 : null;
        });

        $this->evaluator->expects($this->never())->method('evaluateById');

        $this->observer->execute($this->buildObserver($product));
    }

    // --- Auto-enable backorders for drop-ship tests ---

    public function testAutoEnableBackordersWhenDropShipFlippedOn(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isAutoEnableBackorders')->willReturn(true);

        // Drop-ship turned ON: was 0, now 1
        $product = $this->buildProduct(42, 1, 0);

        // Eligibility recomputed
        $this->evaluator->expects($this->once())->method('evaluateById')->with(42);

        // Backorders should be synced to TRUE (allow qty below zero)
        $this->backorderManager->expects($this->once())
            ->method('syncBackordersWithDropShip')
            ->with(42, true);

        $this->observer->execute($this->buildObserver($product));
    }

    public function testAutoRevertBackordersWhenDropShipFlippedOff(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isAutoEnableBackorders')->willReturn(true);

        // Drop-ship turned OFF: was 1, now 0
        $product = $this->buildProduct(42, 0, 1);

        $this->evaluator->expects($this->once())->method('evaluateById')->with(42);

        // Backorders should be synced to FALSE (revert to use-config)
        $this->backorderManager->expects($this->once())
            ->method('syncBackordersWithDropShip')
            ->with(42, false);

        $this->observer->execute($this->buildObserver($product));
    }

    public function testAutoEnableSkippedWhenConfigDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isAutoEnableBackorders')->willReturn(false);  // <-- merchant opted out

        $product = $this->buildProduct(42, 1, 0);

        // Eligibility still recomputed (drop-ship changed)
        $this->evaluator->expects($this->once())->method('evaluateById')->with(42);

        // But backorders NOT touched
        $this->backorderManager->expects($this->never())->method('syncBackordersWithDropShip');

        $this->observer->execute($this->buildObserver($product));
    }

    // --- v1.4.0: Force Standard Shipping Only flag tests ---

    /**
     * Build a product mock that reports BOTH drop_ship_eligible and
     * force_standard_shipping_only old/new values. Used by the v1.4.0 tests.
     */
    private function buildProductWithBothFlags(
        int $id,
        int $dropShipNew,
        int $dropShipOld,
        int $forceStandardNew,
        int $forceStandardOld
    ): Product|MockObject {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getData')
            ->willReturnCallback(static function ($key) use ($dropShipNew, $forceStandardNew) {
                return match ($key) {
                    'drop_ship_eligible'           => $dropShipNew,
                    'force_standard_shipping_only' => $forceStandardNew,
                    default                        => null,
                };
            });
        $product->method('getOrigData')
            ->willReturnCallback(static function ($key) use ($dropShipOld, $forceStandardOld) {
                return match ($key) {
                    'drop_ship_eligible'           => $dropShipOld,
                    'force_standard_shipping_only' => $forceStandardOld,
                    default                        => null,
                };
            });
        return $product;
    }

    public function testExecuteRecomputesWhenForceStandardFlippedOn(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        // drop-ship unchanged (0 → 0), force-standard flipped on (0 → 1)
        $product = $this->buildProductWithBothFlags(42, 0, 0, 1, 0);

        // Eligibility must recompute
        $this->evaluator->expects($this->once())->method('evaluateById')->with(42);

        // Force-standard doesn't affect backorder state — sync must NOT run
        $this->backorderManager->expects($this->never())->method('syncBackordersWithDropShip');

        $this->observer->execute($this->buildObserver($product));
    }

    public function testExecuteRecomputesWhenForceStandardFlippedOff(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        // drop-ship unchanged, force-standard flipped off (1 → 0)
        $product = $this->buildProductWithBothFlags(42, 1, 1, 0, 1);

        $this->evaluator->expects($this->once())->method('evaluateById')->with(42);
        $this->backorderManager->expects($this->never())->method('syncBackordersWithDropShip');

        $this->observer->execute($this->buildObserver($product));
    }

    public function testExecuteSkipsWhenNeitherFlagChanged(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        // Both flags unchanged → no work
        $product = $this->buildProductWithBothFlags(42, 1, 1, 1, 1);

        $this->evaluator->expects($this->never())->method('evaluateById');
        $this->backorderManager->expects($this->never())->method('syncBackordersWithDropShip');

        $this->observer->execute($this->buildObserver($product));
    }

    public function testBothFlagsChangedRunsEvaluatorOnceAndSyncsBackorders(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isAutoEnableBackorders')->willReturn(true);

        // Both flip simultaneously: drop-ship 0 → 1, force-standard 1 → 0
        $product = $this->buildProductWithBothFlags(42, 1, 0, 0, 1);

        // Evaluator runs exactly once even when both flags moved
        $this->evaluator->expects($this->once())->method('evaluateById')->with(42);

        // Backorder sync fires because drop-ship changed (not because force-standard did)
        $this->backorderManager->expects($this->once())
            ->method('syncBackordersWithDropShip')
            ->with(42, true);

        $this->observer->execute($this->buildObserver($product));
    }
}
