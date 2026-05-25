<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Service;

use ETechFlow\NextDayEligibility\Model\Config;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Eav\Model\Config as EavConfig;

/**
 * Plain-English explanation of why a product is/isn't next-day eligible.
 * Powers the live status panel on the product edit page.
 */
class EligibilityExplainer
{
    public function __construct(
        private readonly Config $config,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @return array{eligible:bool, headline:string, reasons:string[], notes:string[]}
     */
    public function explain(ProductInterface $product, ?int $storeId = null): array
    {
        if (!$this->config->isEnabled($storeId)) {
            return [
                'eligible'   => false,
                'headline'   => 'Module is currently disabled.',
                'reasons'    => [],
                'notes'      => ['Turn it on under Stores → Configuration → eTechFlow → Next Day Eligibility.'],
            ];
        }

        $productId = (int) $product->getId();
        if ($productId === 0) {
            return [
                'eligible' => false,
                'headline' => 'Save the product first — eligibility is calculated after save.',
                'reasons'  => [],
                'notes'    => [],
            ];
        }

        $source = $this->config->getDropShipSource($storeId);
        $manualFlag = (bool) $product->getData('drop_ship_eligible');
        $stockItem = $this->stockRegistry->getStockItem($productId);
        $qty = (float) $stockItem->getQty();
        $isInStock = (bool) $stockItem->getIsInStock();
        $backordersAllowed = (int) $stockItem->getBackorders() > 0;

        $reasons = [];
        $notes = [];

        $reasons[] = sprintf(
            'Stock: %s (qty: %s%s)',
            $isInStock ? 'in stock' : 'out of stock',
            $qty,
            $backordersAllowed ? ', backorders allowed' : ''
        );

        if ($manualFlag) {
            $reasons[] = 'Drop-Ship Eligible (manual flag): Yes → always counts as next-day-able';
            return [
                'eligible' => true,
                'headline' => 'This product IS next-day eligible (manual drop-ship override).',
                'reasons'  => $reasons,
                'notes'    => $notes,
            ];
        }

        $reasons[] = 'Drop-Ship Eligible (manual flag): No';

        if ($source === 'supplier') {
            return $this->explainSupplierMode($product, $storeId, $reasons, $notes, $isInStock);
        }

        // Flag-only mode → eligibility = is the product in stock?
        $eligible = $isInStock || $backordersAllowed;
        $headline = $eligible
            ? 'This product IS next-day eligible (stock is available).'
            : 'This product is NOT next-day eligible (out of stock and no drop-ship override).';

        if (!$eligible) {
            $notes[] = 'Fix options: (1) restock the product, (2) enable backorders, or (3) tick "Drop-Ship Eligible" if the supplier ships it directly.';
        }

        return [
            'eligible' => $eligible,
            'headline' => $headline,
            'reasons'  => $reasons,
            'notes'    => $notes,
        ];
    }

    private function explainSupplierMode(
        ProductInterface $product,
        ?int $storeId,
        array $reasons,
        array $notes,
        bool $isInStock
    ): array {
        $pairs = $this->config->getSupplierAttributePairs($storeId);
        $qualifying = $this->config->getQualifyingSupplierNames($storeId);
        $mode = $this->config->getSupplierMatchMode($storeId);
        $modeLabel = $mode === Config::MATCH_FIRST_ACTIVE_WINS
            ? 'First active wins'
            : 'Any active qualifying';

        $reasons[] = sprintf('Drop-Ship Source: Supplier mode (match: %s)', $modeLabel);

        if (empty($pairs)) {
            return [
                'eligible' => false,
                'headline' => 'Supplier mode is on but no supplier slots are configured.',
                'reasons'  => $reasons,
                'notes'    => ['Configure "Supplier Attribute Pairs" under Drop-Ship Exception in the module settings.'],
            ];
        }

        if (empty($qualifying)) {
            return [
                'eligible' => false,
                'headline' => 'Supplier mode is on but no qualifying suppliers are listed.',
                'reasons'  => $reasons,
                'notes'    => ['Add the supplier names that ship next-day in "Qualifying Supplier Names".'],
            ];
        }

        $qualifyingSet = [];
        foreach ($qualifying as $name) {
            $qualifyingSet[strtolower(trim($name))] = $name;
        }

        $slotLines = [];
        $firstActiveSlot = null;
        $firstActiveQualifies = null;
        $anyActiveQualifying = false;
        $anyActive = false;
        $orphanedActiveSlots = [];   // active=1 but no supplier name set

        foreach ($pairs as $idx => $pair) {
            $active = (bool) $product->getData($pair['active']);
            $names = $this->resolveSupplierNames($product, $pair['name']);
            $hasName = !empty($names);
            $nameDisplay = $hasName ? implode(', ', $names) : '(no name set)';

            $qualifies = false;
            foreach ($names as $candidate) {
                if (isset($qualifyingSet[strtolower(trim($candidate))])) {
                    $qualifies = true;
                    break;
                }
            }

            $marker = $active ? '✓ active' : '○ inactive';
            $qualifierTag = '';
            if ($active) {
                if (!$hasName) {
                    $qualifierTag = ' — ⚠️ active but no supplier name set';
                    $orphanedActiveSlots[] = $idx + 1;
                } else {
                    $qualifierTag = $qualifies ? ' — qualifies for next-day' : ' — NOT in qualifying list';
                }
            }

            $slotLines[] = sprintf(
                'Slot %d (%s / %s): %s [%s]%s',
                $idx + 1,
                $pair['active'],
                $pair['name'],
                $nameDisplay,
                $marker,
                $qualifierTag
            );

            if ($active) {
                $anyActive = true;
                if ($firstActiveSlot === null) {
                    $firstActiveSlot = $idx + 1;
                    $firstActiveQualifies = $qualifies;
                }
                if ($qualifies) {
                    $anyActiveQualifying = true;
                }
            }
        }

        $reasons = array_merge($reasons, $slotLines);

        if (!empty($orphanedActiveSlots)) {
            $slotsList = implode(', ', array_map(static fn($i) => "slot {$i}", $orphanedActiveSlots));
            $notes[] = sprintf(
                'Data inconsistency: %s ticked active but no supplier name is set. Either pick a supplier name for %s, or untick the active flag.',
                $slotsList,
                count($orphanedActiveSlots) === 1 ? 'it' : 'them'
            );
        }

        $eligible = false;
        $verdict = '';

        if (!$anyActive) {
            $verdict = 'No supplier slot is active on this product → not eligible via supplier mode.';
            $notes[] = 'Tick the active flag on at least one supplier slot that\'s in your qualifying list.';
        } elseif ($mode === Config::MATCH_FIRST_ACTIVE_WINS) {
            if ($firstActiveQualifies) {
                $eligible = true;
                $verdict = sprintf('Slot %d is the first active supplier and it\'s in the qualifying list → ELIGIBLE.', $firstActiveSlot);
            } else {
                $verdict = sprintf('Slot %d is the first active supplier but it\'s NOT in the qualifying list → not eligible.', $firstActiveSlot);
                $notes[] = sprintf('Either change which supplier is at slot %d, or add this supplier name to the qualifying list.', $firstActiveSlot);
            }
        } else {
            if ($anyActiveQualifying) {
                $eligible = true;
                $verdict = 'At least one active supplier is in the qualifying list → ELIGIBLE.';
            } else {
                $verdict = 'No active supplier is in the qualifying list → not eligible.';
                $notes[] = 'Tick a slot whose supplier appears in your qualifying list, OR add one of the active suppliers to the qualifying list.';
            }
        }

        $reasons[] = $verdict;

        $headline = $eligible
            ? 'This product IS next-day eligible.'
            : 'This product is NOT next-day eligible.';

        return [
            'eligible' => $eligible,
            'headline' => $headline,
            'reasons'  => $reasons,
            'notes'    => $notes,
        ];
    }

    /**
     * Mirror SupplierDropShipResolver::resolveSupplierNames for explainer.
     * Returns 1+ name candidates from a name-attribute that could be text /
     * dropdown / multiselect.
     *
     * @return string[]
     */
    private function resolveSupplierNames(ProductInterface $product, string $attrCode): array
    {
        $raw = $product->getData($attrCode);
        if ($raw === null || $raw === '' || $raw === false) {
            return [];
        }

        // Path 1: scalar text or numeric option id
        if (is_string($raw) && !ctype_digit($raw) && !preg_match('/^\d+(,\d+)+$/', $raw)) {
            return [trim($raw)];
        }

        // Path 2 / 3: dropdown (id) or multiselect (csv ids)
        try {
            $attr = $this->eavConfig->getAttribute('catalog_product', $attrCode);
            if (!$attr || !$attr->getId()) {
                return is_string($raw) ? [trim($raw)] : [];
            }
            $source = $attr->getSource();
            $ids = is_array($raw) ? $raw : explode(',', (string) $raw);
            $labels = [];
            foreach ($ids as $id) {
                $id = trim((string) $id);
                if ($id === '') {
                    continue;
                }
                $label = $source ? (string) $source->getOptionText($id) : '';
                if ($label !== '') {
                    $labels[] = $label;
                }
            }
            return $labels;
        } catch (\Throwable $e) {
            return is_string($raw) ? [trim($raw)] : [];
        }
    }
}
