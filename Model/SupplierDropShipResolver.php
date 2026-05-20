<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Decides whether a product qualifies as drop-ship-eligible based on its
 * supplier attributes, configured per-store in admin.
 *
 * Reads the configured `<active_attr>:<name_attr>` pairs (see Config::
 * getSupplierAttributePairs) and the list of qualifying supplier names
 * (Config::getQualifyingSupplierNames). For a given product, walks every
 * pair and returns true if any pair has `active = 1` AND `name` (case-
 * insensitive, trimmed) is in the qualifying list.
 *
 * Module-agnostic by design — no Keystation-specific attribute names or
 * supplier values appear in this file. Everything is data-driven from
 * the admin config so any merchant can plug in their own attribute
 * structure.
 *
 * Failure modes are silent:
 *   - Missing attribute on the product → that pair contributes false
 *   - No pairs configured             → returns false (caller falls back)
 *   - No qualifying names configured  → returns false (supplier mode no-op)
 *
 * Per-request memoization on (productId × storeId) keeps the cost low
 * when called multiple times in a single checkout/admin save chain.
 */
class SupplierDropShipResolver
{
    /** @var array<string, bool> */
    private array $cache = [];

    /**
     * Constructor.
     *
     * @param Config                   $config
     * @param ProductCollectionFactory $productCollectionFactory
     * @param LoggerInterface          $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int      $productId
     * @param int|null $storeId   Used for per-store config scope.
     * @return bool
     */
    public function isDropShipEligible(int $productId, ?int $storeId = null): bool
    {
        $cacheKey = $productId . ':' . ($storeId ?? '_');
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $pairs = $this->config->getSupplierAttributePairs($storeId);
        $qualifying = $this->config->getQualifyingSupplierNames($storeId);

        if (empty($pairs) || empty($qualifying)) {
            return $this->cache[$cacheKey] = false;
        }

        // Build a case-insensitive set so the membership check is O(1) per pair.
        $qualifyingSet = [];
        foreach ($qualifying as $name) {
            $qualifyingSet[strtolower(trim($name))] = true;
        }

        // Collect every attribute code we need so we can load them in one query.
        $attrCodes = [];
        foreach ($pairs as $pair) {
            $attrCodes[$pair['active']] = true;
            $attrCodes[$pair['name']]   = true;
        }

        try {
            $product = $this->loadProduct($productId, array_keys($attrCodes));
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ETechFlow_NextDayEligibility: supplier resolver failed to load product attributes; treating as not eligible.',
                ['product_id' => $productId, 'exception' => $e->getMessage()]
            );
            return $this->cache[$cacheKey] = false;
        }

        if ($product === null) {
            return $this->cache[$cacheKey] = false;
        }

        foreach ($pairs as $pair) {
            $active = $product->getData($pair['active']);
            if (!$active) {
                continue;
            }
            $name = $product->getData($pair['name']);
            if (!is_string($name)) {
                // Dropdown/multiselect attributes may return ints (option ids).
                // We compare against names, so non-string values can't match the
                // qualifying list — log so the merchant notices misconfiguration.
                $this->logger->debug(
                    'ETechFlow_NextDayEligibility: supplier name attribute returned non-string value; skipping pair.',
                    [
                        'product_id'   => $productId,
                        'active_attr'  => $pair['active'],
                        'name_attr'    => $pair['name'],
                        'value_type'   => gettype($name),
                    ]
                );
                continue;
            }
            $normalised = strtolower(trim($name));
            if ($normalised !== '' && isset($qualifyingSet[$normalised])) {
                return $this->cache[$cacheKey] = true;
            }
        }

        return $this->cache[$cacheKey] = false;
    }

    /**
     * Reset the per-request cache. Useful for long-running CLI processes
     * (cron tasks, batch reindex) that touch many products and would
     * otherwise hold every result for the lifetime of the process.
     *
     * @return void
     */
    public function resetCache(): void
    {
        $this->cache = [];
    }

    /**
     * Load a single product with only the attributes we need. Returns null
     * when the product no longer exists.
     *
     * @param int      $productId
     * @param string[] $attrCodes
     * @return \Magento\Catalog\Api\Data\ProductInterface|null
     */
    private function loadProduct(int $productId, array $attrCodes)
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addIdFilter([$productId]);
        foreach ($attrCodes as $code) {
            $collection->addAttributeToSelect($code);
        }
        $collection->setPageSize(1);

        $item = $collection->getFirstItem();
        return $item->getId() ? $item : null;
    }
}
