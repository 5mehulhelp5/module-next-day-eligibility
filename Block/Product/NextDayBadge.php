<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Block\Product;

use ETechFlow\NextDayEligibility\Model\Config;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class NextDayBadge extends Template
{
    /**
     * Constructor.
     *
     * @param Context  $context
     * @param Config   $config
     * @param Registry $registry
     * @param array    $data
     */
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Check if the module is enabled.
     *
     * @return bool
     */
    public function isModuleEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    /**
     * Return the current product from registry.
     *
     * @return ProductInterface|null
     */
    public function getCurrentProduct(): ?ProductInterface
    {
        return $this->registry->registry('current_product');
    }

    /**
     * Check if the current product is next day eligible.
     *
     * @return bool
     */
    public function isNextDayEligible(): bool
    {
        $product = $this->getCurrentProduct();
        if ($product === null) {
            return false;
        }

        return (bool) $product->getData('next_day_eligible');
    }

    /**
     * Return the badge label for eligible products.
     *
     * @return string
     */
    public function getLabelYes(): string
    {
        return $this->config->getLabelYes();
    }

    /**
     * Return the badge label for ineligible products.
     *
     * @return string
     */
    public function getLabelNo(): string
    {
        return $this->config->getLabelNo();
    }

    /**
     * Whether the current product can actually be added to cart.
     *
     * Wraps Magento's `Product::isSalable()` — which combines is_in_stock +
     * qty + backorders + manage_stock into a single saleability answer.
     *
     * Used by shouldRenderBadge() to suppress the "Standard Delivery Only"
     * badge on products the customer can't even buy. A shipping promise
     * (or non-promise) has no informational value when there's no
     * Add to Cart button — the badge just adds visual noise.
     *
     * @return bool
     */
    public function isBuyable(): bool
    {
        $product = $this->getCurrentProduct();
        if ($product === null) {
            return false;
        }

        try {
            return (bool) $product->isSalable();
        } catch (\Throwable $e) {
            // Defensive — `isSalable()` can throw for products in odd states
            // (newly imported, missing stock item). Treat as "not buyable" so
            // we suppress the badge rather than crash the PDP.
            return false;
        }
    }

    /**
     * Whether the badge should render for the current product based on the
     * admin visibility setting (Both / Eligible only / Never) AND the
     * product's buyability state.
     *
     * v1.4.1 rule: even with visibility="both", suppress the GREY (ineligible)
     * badge when the product can't be added to cart. The customer is going
     * to see "Out of Stock" / Add-to-Cart hidden — adding a "Standard
     * Delivery Only" sticker on top is just clutter.
     *
     * @return bool
     */
    public function shouldRenderBadge(): bool
    {
        $visibility = $this->config->getBadgeVisibility();

        if ($visibility === 'never') {
            return false;
        }

        $isEligible = $this->isNextDayEligible();

        // Suppress the GREY (ineligible) badge for unbuyable products.
        // The GREEN (eligible) badge still renders even on unbuyable products
        // — that's the rare case where a product is drop-ship-eligible (so
        // marked eligible) but Magento happens to consider it unbuyable in
        // some intermediate state. The eligibility info is still valid info.
        if (!$isEligible && !$this->isBuyable()) {
            return false;
        }

        if ($visibility === 'eligible_only' && !$isEligible) {
            return false;
        }

        return true;
    }
}
