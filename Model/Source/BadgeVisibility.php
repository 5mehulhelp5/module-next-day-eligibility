<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class BadgeVisibility implements OptionSourceInterface
{
    public const SHOW_BOTH          = 'both';
    public const SHOW_ELIGIBLE_ONLY = 'eligible_only';
    public const SHOW_NEVER         = 'never';

    /**
     * Return options for the PDP-badge visibility dropdown.
     *
     * Labels are wrapped with __() and therefore typed as Phrase — Magento's
     * stringable wrapper that admin renderers handle natively. Same convention
     * as Magento's core source models.
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::SHOW_BOTH,          'label' => __('Both — show "Eligible" and "Standard Delivery Only" badges')],
            ['value' => self::SHOW_ELIGIBLE_ONLY, 'label' => __('Eligible only — show the green badge, hide the grey one')],
            ['value' => self::SHOW_NEVER,         'label' => __('Never — don\'t show any badge on the product page')],
        ];
    }
}
