<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Test\Unit\Observer;

use ETechFlow\NextDayEligibility\Model\Config;
use ETechFlow\NextDayEligibility\Model\EligibilityEvaluator;
use ETechFlow\NextDayEligibility\Observer\UpdateNextDayEligibility;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UpdateNextDayEligibilityTest extends TestCase
{
    /** @var Config|MockObject */
    private Config|MockObject $config;

    /** @var EligibilityEvaluator|MockObject */
    private EligibilityEvaluator|MockObject $evaluator;

    /** @var LoggerInterface|MockObject */
    private LoggerInterface|MockObject $logger;

    /** @var UpdateNextDayEligibility */
    private UpdateNextDayEligibility $observer;

    protected function setUp(): void
    {
        $this->config    = $this->createMock(Config::class);
        $this->evaluator = $this->createMock(EligibilityEvaluator::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->observer = new UpdateNextDayEligibility(
            $this->config,
            $this->evaluator,
            $this->logger
        );
    }

    /**
     * Build an Observer with an Event whose getItem() returns the given stock item.
     *
     * @param mixed $stockItem
     * @return Observer|MockObject
     */
    private function buildObserver(mixed $stockItem): Observer|MockObject
    {
        $event = $this->getMockBuilder(Event::class)
            ->disableOriginalConstructor()
            ->addMethods(['getItem'])
            ->getMock();
        $event->method('getItem')->willReturn($stockItem);

        $mockObserver = $this->createMock(Observer::class);
        $mockObserver->method('getEvent')->willReturn($event);

        return $mockObserver;
    }

    public function testExecuteDoesNothingWhenModuleDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        $this->evaluator->expects($this->never())->method('evaluate');

        $mockObserver = $this->createMock(Observer::class);
        $mockObserver->expects($this->never())->method('getEvent');

        $this->observer->execute($mockObserver);
    }

    public function testExecuteDelegatesToEvaluator(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getProductId')->willReturn(42);

        $this->evaluator->expects($this->once())
            ->method('evaluate')
            ->with(42, $stockItem);

        $this->observer->execute($this->buildObserver($stockItem));
    }

    public function testExecuteHandlesNullStockItem(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $this->evaluator->expects($this->never())->method('evaluate');

        $this->observer->execute($this->buildObserver(null));
    }

    public function testExecuteHandlesStockItemWithoutProductId(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getProductId')->willReturn(null);

        $this->evaluator->expects($this->never())->method('evaluate');

        $this->observer->execute($this->buildObserver($stockItem));
    }

    public function testExecuteLogsExceptionWithoutThrowing(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getProductId')->willReturn(42);

        $this->evaluator->method('evaluate')
            ->willThrowException(new \RuntimeException('DB error'));

        $this->logger->expects($this->once())->method('error');

        // Must not throw
        $this->observer->execute($this->buildObserver($stockItem));
    }
}
