<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

/**
 * Cleans up the Qualifying Supplier Names admin field on save.
 *
 * - Trims whitespace from each line
 * - Drops blank lines (and comment lines starting with #)
 * - Preserves merchant casing — the resolver matches case-insensitively anyway
 *
 * Does NOT attempt fuzzy-match against EAV attribute options (e.g.
 * "Onlyda" → "OnlyDa"). Lossy normalisation that mutates merchant input
 * silently is worse than the case-insensitive match the resolver already
 * does. If a merchant types a name that doesn't exist as a supplier
 * option, the worst case is "this rule never fires" — already explained
 * by the new live "Why?" panel on the product edit page.
 */
class QualifyingSuppliers extends Value
{
    public function beforeSave(): self
    {
        $value = (string) $this->getValue();

        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
        $cleaned = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $cleaned[] = $trimmed;
        }

        $this->setValue(implode("\n", $cleaned));

        return parent::beforeSave();
    }
}
