<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Block\Adminhtml\Form\Field\MethodStatusDisplay;

use ETechFlow\NextDayEligibility\Block\Adminhtml\Form\Field\MethodStatusDisplay;
use ETechFlow\NextDayEligibility\Model\ShippingMethodAvailability;

class Standard extends MethodStatusDisplay
{
    protected function getType(): string
    {
        return ShippingMethodAvailability::TYPE_STANDARD;
    }
}
