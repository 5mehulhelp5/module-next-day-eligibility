<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Observer;

use ETechFlow\NextDayEligibility\Model\BackorderManager;
use ETechFlow\NextDayEligibility\Model\Config;
use ETechFlow\NextDayEligibility\Model\EligibilityEvaluator;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Psr\Log\LoggerInterface;

class UpdateOnProductSave implements ObserverInterface
{
    private const DROP_SHIP_ATTR_CODE      = 'drop_ship_eligible';
    private const FORCE_STANDARD_ATTR_CODE = 'force_standard_shipping_only';

    /**
     * Flag a product as "skip eligibility recompute" before saving — used by
     * bulk import / migration scripts that handle eligibility separately.
     *
     * Usage in a custom import:
     *   $product->setData(UpdateOnProductSave::SKIP_FLAG, true);
     *   $productRepository->save($product);
     */
    public const SKIP_FLAG = '_etechflow_skip_eligibility';

    /**
     * Constructor.
     *
     * @param Config                 $config
     * @param EligibilityEvaluator   $evaluator
     * @param BackorderManager       $backorderManager
     * @param LoggerInterface        $logger
     * @param MessageManager         $messageManager
     * @param AppState               $appState
     * @param StockRegistryInterface $stockRegistry
     */
    public function __construct(
        private readonly Config $config,
        private readonly EligibilityEvaluator $evaluator,
        private readonly BackorderManager $backorderManager,
        private readonly LoggerInterface $logger,
        private readonly MessageManager $messageManager,
        private readonly AppState $appState,
        private readonly StockRegistryInterface $stockRegistry
    ) {
    }

    /**
     * Recompute eligibility when a merchant toggles drop-ship on a saved product.
     *
     * Stock-driven eligibility is already covered by UpdateNextDayEligibility
     * via cataloginventory_stock_item_save_after. This observer covers the
     * case where stock is unchanged but the drop-ship flag flips, which
     * otherwise would leave eligibility stale on customer carts.
     *
     * Bulk imports can opt out by setting the SKIP_FLAG on the product before
     * save — they should call EligibilityEvaluator manually (or async) when
     * the import finishes.
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
            /** @var ProductInterface|null $product */
            $product = $observer->getEvent()->getProduct();

            if (!$product || !$product->getId()) {
                return;
            }

            // Bulk import opt-out: callers set this flag before save()
            if ($product->getData(self::SKIP_FLAG)) {
                return;
            }

            // Magento sets _indexer_processing during async bulk reindex paths
            if ($product->getData('_indexer_processing')) {
                return;
            }

            $dropShipChanged      = $this->hasDropShipChanged($product);
            $forceStandardChanged = $this->hasForceStandardChanged($product);
            $productId = (int) $product->getId();

            if ($dropShipChanged || $forceStandardChanged) {
                // Recompute eligibility flag — EligibilityEvaluator handles all three
                // precedences (force-standard override > drop-ship > stock).
                $this->evaluator->evaluateById($productId);

                // Only sync Magento's Backorders setting when DROP-SHIP changed (not force-standard).
                // Force-standard doesn't affect saleability — it just blocks express shipping —
                // so the merchant's existing backorder configuration should stay untouched.
                if ($dropShipChanged && $this->config->isAutoEnableBackorders()) {
                    $dropShipEligible = (bool) $product->getData(self::DROP_SHIP_ATTR_CODE);
                    $this->backorderManager->syncBackordersWithDropShip($productId, $dropShipEligible);
                }
            }

            // v1.4.1 diagnostic — fires on EVERY admin save (not just attribute flips)
            // so the merchant gets the warning even when they only touched stock fields.
            $this->runStockStateDiagnostic($productId, $product);
        } catch (\Exception $e) {
            $this->logger->error(
                'ETechFlow_NextDayEligibility: Error updating eligibility on product save.',
                ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Detect the contradictory-stock-state pattern (qty<=0 + Stock Status=In Stock
     * + Backorders=No + Manage Stock=Yes) and flash an admin-only notice
     * explaining the consequences + how to fix.
     *
     * Skipped silently outside the adminhtml area — we don't want this firing
     * during cron / API / import flows where there's no merchant to see the
     * message and the flash collector might leak the entry to the next admin
     * page load.
     *
     * @param int              $productId
     * @param ProductInterface $product
     * @return void
     */
    private function runStockStateDiagnostic(int $productId, ProductInterface $product): void
    {
        try {
            // Restrict to admin saves — cron / API / import shouldn't flash.
            // getAreaCode() throws if no area was set yet (rare); treat that as
            // "not admin" and skip.
            try {
                if ($this->appState->getAreaCode() !== Area::AREA_ADMINHTML) {
                    return;
                }
            } catch (\Throwable $areaErr) {
                return;
            }

            $stockItem = $this->stockRegistry->getStockItem($productId);
            if (!$stockItem->getItemId()) {
                return;
            }

            $qty         = (float) $stockItem->getQty();
            $isInStock   = (bool)  $stockItem->getIsInStock();
            $backorders  = (int)   $stockItem->getBackorders();
            $manageStock = (int)   $stockItem->getManageStock();

            // Contradictory state: saleable on paper, no inventory, no backorders.
            // Customer sees "Out of Stock" label (theme reads qty) but Add to Cart
            // button (Magento reads is_in_stock + manage_stock + backorders).
            $contradictory = $qty <= 0
                && $isInStock
                && $backorders === 0
                && $manageStock === 1;

            if (!$contradictory) {
                return;
            }

            // If the product is also drop-ship eligible, the more specific
            // warning is: NDE's auto-enable-backorders feature appears off
            // (or has not run yet). Suggest fixes.
            if ((bool) $product->getData(self::DROP_SHIP_ATTR_CODE)) {
                $this->messageManager->addWarningMessage(__(
                    'Drop-ship-eligible product "%1" has qty 0 + Stock Status "In Stock" + Backorders "No". '
                    . 'Customers will see a contradictory "Out of Stock" label alongside the Add to Cart button. '
                    . 'Enable Stores → Configuration → eTechFlow → Next Day Eligibility → Drop-Ship Exception → '
                    . 'Auto-Enable Backorders, OR set Backorders manually to "Allow Qty Below 0" on this product.',
                    $product->getName()
                ));
                return;
            }

            // Generic case — merchant deliberately or accidentally created the state
            $this->messageManager->addNoticeMessage(__(
                'Product "%1" is in a contradictory stock state: qty 0, Stock Status "In Stock", Backorders "No". '
                . 'The frontend will show "Out of Stock" but the Add to Cart button will still be visible. '
                . 'To fix, pick one: set Stock Status to "Out of Stock", OR enable Backorders, OR mark Drop-Ship Eligible.',
                $product->getName()
            ));
        } catch (\Throwable $e) {
            // Diagnostic is best-effort — never block the save
            $this->logger->warning(
                'ETechFlow_NextDayEligibility: Stock state diagnostic failed.',
                ['product_id' => $productId, 'exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Detect whether drop_ship_eligible was just changed on this save.
     *
     * @param ProductInterface $product
     * @return bool
     */
    private function hasDropShipChanged(ProductInterface $product): bool
    {
        $newValue = (int) $product->getData(self::DROP_SHIP_ATTR_CODE);
        $oldValue = (int) $product->getOrigData(self::DROP_SHIP_ATTR_CODE);

        return $newValue !== $oldValue;
    }

    /**
     * Detect whether force_standard_shipping_only was just changed on this save (v1.4.0+).
     *
     * @param ProductInterface $product
     * @return bool
     */
    private function hasForceStandardChanged(ProductInterface $product): bool
    {
        $newValue = (int) $product->getData(self::FORCE_STANDARD_ATTR_CODE);
        $oldValue = (int) $product->getOrigData(self::FORCE_STANDARD_ATTR_CODE);

        return $newValue !== $oldValue;
    }
}
