<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Observer;

use ETechFlow\NextDayEligibility\Model\Config;
use ETechFlow\NextDayEligibility\Model\EligibilityEvaluator;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class UpdateNextDayEligibility implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param Config               $config
     * @param EligibilityEvaluator $evaluator
     * @param LoggerInterface      $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly EligibilityEvaluator $evaluator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Recompute eligibility whenever a stock item is saved.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            /** @var StockItemInterface|null $stockItem */
            $stockItem = $observer->getEvent()->getItem();

            if (!$stockItem || !$stockItem->getProductId()) {
                return;
            }

            $this->evaluator->evaluate((int) $stockItem->getProductId(), $stockItem);
        } catch (\Exception $e) {
            $this->logger->error(
                'ETechFlow_NextDayEligibility: Error updating next day eligibility on stock save.',
                ['exception' => $e->getMessage()]
            );
        }
    }
}
