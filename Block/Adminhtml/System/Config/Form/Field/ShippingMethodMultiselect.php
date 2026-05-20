<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Wraps Magento's native multiselect renderer with two UX fixes:
 *
 *  1. Plain mouse click toggles each option on/off — no Ctrl/Cmd required.
 *     A `mousedown` handler intercepts the native browser behaviour
 *     (which would clear other selections) and instead toggles the
 *     clicked option, preserving every other selected state.
 *
 *  2. A "Clear all" hyperlink under the field deselects everything in
 *     one click and dispatches a `change` event so Magento's form-save
 *     dirty-tracking picks it up.
 *
 * Both behaviours are pure vanilla JS — no jQuery, no Magento RequireJS
 * dance — and work on the stock admin theme and Hyvä admin theme alike.
 */
class ShippingMethodMultiselect extends Field
{
    /**
     * Render the element + the click-toggle + clear-all affordance.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $html       = parent::_getElementHtml($element);
        $htmlId     = (string) $element->getHtmlId();
        $clearLabel = (string) __('Clear all');
        $hintLabel  = (string) __('Tip: click any option to add or remove it. Click "Clear all" to start over.');

        /*
         * Click-toggle handler.
         *
         * Native `<select multiple>` semantics: clicking an option WITHOUT a
         * modifier key clears every other selection. That's hostile to
         * merchants who expect each click to act independently.
         *
         * Workaround: intercept the `mousedown` event on each <option>, kill
         * the default browser behaviour, and manually toggle just that one
         * option's `selected` state. The user can now pick any number of
         * options with plain mouse clicks. Ctrl/Cmd-click still works for
         * users who are used to it — those modifiers no-op because we've
         * already toggled the state.
         *
         * Why mousedown vs click: Firefox + Safari fire `mousedown` before
         * the browser's default selection-reset logic runs, so preventing
         * the default at mousedown stops the reset cleanly. `click` is too
         * late on those browsers.
         */
        $toggleJs = sprintf(
            "(function(){"
            . "var s=document.getElementById('%s');"
            . "if(!s||s.dataset.etfToggleBound)return;"
            . "s.dataset.etfToggleBound='1';"
            . "s.addEventListener('mousedown',function(e){"
            .   "if(e.target&&e.target.tagName==='OPTION'){"
            .     "e.preventDefault();"
            .     "e.target.selected=!e.target.selected;"
            .     "s.focus();"
            .     "s.dispatchEvent(new Event('change',{bubbles:true}));"
            .   "}"
            . "});"
            . "})();",
            $htmlId
        );

        $clearJs = sprintf(
            "(function(s){if(!s)return;Array.from(s.options).forEach(function(o){o.selected=false;});"
            . "s.dispatchEvent(new Event('change',{bubbles:true}));})(document.getElementById('%s'));"
            . "return false;",
            $htmlId
        );

        $affordance = '<script>' . $toggleJs . '</script>'
            . '<div class="etechflow-nde-multiselect-tools" style="margin-top:6px;font-size:0.9em;">'
            . '<a href="#" onclick="' . htmlspecialchars($clearJs, ENT_QUOTES) . '" '
            .   'style="color:#d10000;text-decoration:underline;cursor:pointer;">' . $clearLabel . '</a>'
            . '<span style="color:#666;margin-left:12px;font-style:italic;">' . $hintLabel . '</span>'
            . '</div>';

        return $html . $affordance;
    }
}
