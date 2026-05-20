<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;

/**
 * Feeds the checkout banner state to the storefront JS (Knockout component).
 *
 * The banner fires when EITHER restriction rule is currently affecting the cart:
 *  1. Next-day eligibility: a non-eligible item is in the cart AND next-day codes
 *     are configured.
 *  2. Backorder express restriction: a backorder item is in the cart AND the rule
 *     is enabled AND express codes are configured.
 *
 * One banner, two possible reasons. Merchant copy can be customised per store view.
 */
class ConfigProvider implements ConfigProviderInterface
{
    /**
     * Constructor.
     *
     * @param Config               $config
     * @param CheckoutSession      $checkoutSession
     * @param IneligibilityChecker $ineligibilityChecker
     * @param BackorderChecker     $backorderChecker
     * @param LoggerInterface      $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly CheckoutSession $checkoutSession,
        private readonly IneligibilityChecker $ineligibilityChecker,
        private readonly BackorderChecker $backorderChecker,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Provide notice configuration to the checkout JS component.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        if (!$this->config->isEnabled() || !$this->config->isShowNotice()) {
            return ['nextDayEligibility' => ['isRestricted' => false]];
        }

        $isRestricted = false;

        try {
            $quote = $this->checkoutSession->getQuote();
            $items = $quote->getAllItems();

            // Rule 1: next-day eligibility
            if (!empty($this->config->getShippingMethodCodes())
                && $this->ineligibilityChecker->hasIneligibleItems($items)
            ) {
                $isRestricted = true;
            }

            // Rule 2: backorder express restriction
            if (!$isRestricted
                && $this->config->isRestrictExpressOnBackorder()
                && !empty($this->config->getBackorderExpressMethodCodes())
                && $this->backorderChecker->hasBackorderItems($items)
            ) {
                $isRestricted = true;
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'ETechFlow_NextDayEligibility: ConfigProvider error.',
                ['exception' => $e->getMessage()]
            );
            $isRestricted = false;
        }

        return [
            'nextDayEligibility' => [
                'isRestricted'  => $isRestricted,
                'noticeStyle'   => $this->config->getNoticeStyle(),
                'noticeTitle'   => $this->config->getNoticeTitle(),
                'noticeMessage' => $this->config->getNoticeMessage(),
            ],
        ];
    }
}
