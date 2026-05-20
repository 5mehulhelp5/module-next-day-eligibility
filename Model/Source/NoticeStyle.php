<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class NoticeStyle implements OptionSourceInterface
{
    /**
     * Return available notice style options.
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'warning', 'label' => __('Warning (yellow)')],
            ['value' => 'info',    'label' => __('Info (blue)')],
            ['value' => 'error',   'label' => __('Error (red)')],
        ];
    }
}
