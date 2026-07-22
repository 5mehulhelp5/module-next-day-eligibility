<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\ViewModel;

use ETechFlow\NextDayEligibility\Model\BackorderChecker;
use ETechFlow\NextDayEligibility\Model\Config;
use ETechFlow\NextDayEligibility\Model\IneligibilityChecker;
use ETechFlow\NextDayEligibility\Model\SalableStockChecker;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;

/**
 * View model for the Hyvä Checkout shipping-restriction notice.
 *
 * Renders the notice when EITHER restriction rule is firing:
 *  1. Next-day eligibility (mixed-eligibility cart)
 *  2. Backorder express restriction (backorder items in cart, v1.1.0+)
 *
 * Used only when Hyvä Checkout is installed; the Knockout-based notice
 * continues to handle the standard Magento checkout.
 */
class HyvaCheckoutNotice implements ArgumentInterface
{
    /**
     * Constructor.
     *
     * @param Config               $config
     * @param IneligibilityChecker $ineligibilityChecker
     * @param BackorderChecker     $backorderChecker
     * @param SalableStockChecker  $salableStockChecker
     * @param CheckoutSession      $checkoutSession
     * @param LoggerInterface      $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly IneligibilityChecker $ineligibilityChecker,
        private readonly BackorderChecker $backorderChecker,
        private readonly SalableStockChecker $salableStockChecker,
        private readonly CheckoutSession $checkoutSession,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Whether the notice should be rendered for the current cart.
     *
     * @return bool
     */
    public function isVisible(): bool
    {
        if (!$this->config->isEnabled() || !$this->config->isShowNotice()) {
            return false;
        }

        try {
            $quote = $this->checkoutSession->getQuote();
            $items = $quote->getAllItems();

            // Rule 1: next-day eligibility
            if (!empty($this->config->getShippingMethodCodes())
                && $this->ineligibilityChecker->hasIneligibleItems($items)
            ) {
                return true;
            }

            // Rule 2: backorder express restriction
            if ($this->config->isRestrictExpressOnBackorder()
                && !empty($this->config->getBackorderExpressMethodCodes())
                && $this->backorderChecker->hasBackorderItems($items)
            ) {
                return true;
            }

            // Rule 3 (v1.9.0): salable-stock shortfall — a line requests more
            // than is salable from the shelf (reservation-aware).
            if ($this->config->isRestrictOnInsufficientSalable()
                && $this->salableStockChecker->hasShortfall($items)
            ) {
                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->logger->error(
                'ETechFlow_NextDayEligibility: Hyva notice visibility check failed.',
                ['exception' => $e->getMessage()]
            );
            return false;
        }
    }

    /**
     * Notice title (bold heading).
     *
     * @return string
     */
    public function getTitle(): string
    {
        return $this->config->getNoticeTitle();
    }

    /**
     * Notice message body.
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->config->getNoticeMessage();
    }

    /**
     * Notice style type ('warning' | 'info' | 'error').
     *
     * Used to attach the semantic modifier class (etechflow-nextday-notice--*)
     * so the inline critical-CSS fallback can colour the notice even when the
     * store's compiled Tailwind build omits this module's utility classes —
     * e.g. a dev store serving static borrowed from a production build that
     * predates the module.
     *
     * @return string
     */
    public function getStyleType(): string
    {
        $style = $this->config->getNoticeStyle();

        return in_array($style, ['info', 'error'], true) ? $style : 'warning';
    }

    /**
     * Notice style classes (Tailwind utilities with dark-mode variants).
     *
     * @return array{container:string, icon:string, ring:string}
     */
    public function getStyleClasses(): array
    {
        $style = $this->config->getNoticeStyle();

        return match ($style) {
            'info' => [
                'container' => 'bg-blue-50 text-blue-900 border-blue-500 '
                    . 'dark:bg-blue-900/20 dark:text-blue-200 dark:border-blue-400',
                'icon'      => 'text-blue-600 dark:text-blue-300',
                'ring'      => 'focus:ring-blue-500 dark:focus:ring-blue-400',
            ],
            'error' => [
                'container' => 'bg-red-50 text-red-900 border-red-500 '
                    . 'dark:bg-red-900/20 dark:text-red-200 dark:border-red-400',
                'icon'      => 'text-red-600 dark:text-red-300',
                'ring'      => 'focus:ring-red-500 dark:focus:ring-red-400',
            ],
            default => [
                'container' => 'bg-amber-50 text-amber-900 border-amber-500 '
                    . 'dark:bg-amber-900/20 dark:text-amber-200 dark:border-amber-400',
                'icon'      => 'text-amber-600 dark:text-amber-300',
                'ring'      => 'focus:ring-amber-500 dark:focus:ring-amber-400',
            ],
        };
    }
}
