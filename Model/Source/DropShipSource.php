<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model\Source;

use ETechFlow\NextDayEligibility\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for the Drop-Ship Source admin dropdown.
 *
 * Backs the `etechflow_nextdayeligibility/drop_ship/source` system config.
 * Values come from Config's DROP_SHIP_SOURCE_* constants so the strings
 * stay in sync with the consuming code.
 */
class DropShipSource implements OptionSourceInterface
{
    /**
     * Return options for the Drop-Ship Source dropdown.
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => Config::DROP_SHIP_SOURCE_FLAG,
                'label' => __('Manual flag only (default) — uses Drop-Ship Eligible attribute'),
            ],
            [
                'value' => Config::DROP_SHIP_SOURCE_SUPPLIER,
                'label' => __('Supplier-based — reads supplier attributes (with manual flag as override)'),
            ],
        ];
    }
}
