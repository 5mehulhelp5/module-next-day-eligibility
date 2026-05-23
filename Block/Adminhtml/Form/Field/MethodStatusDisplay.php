<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Block\Adminhtml\Form\Field;

use ETechFlow\NextDayEligibility\Model\ShippingMethodAvailability;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Inline mismatch-status block for NDE's shipping-method-code config fields (v1.6.2).
 *
 * Renders directly under each of the three method-code field groups
 * (Next Day / Standard / Click-and-Collect) in the admin Stores →
 * Configuration page. Shows the merchant in-place whether their
 * configured codes are wired to anything real on the store.
 *
 * Three render states:
 *
 *   No codes configured       → muted "Not configured" — neutral, no nag
 *   All codes match           → green "All N configured codes match" — looks correct
 *   Some/all codes unmatched  → red list of unmatched codes with one-line fix hint
 *
 * Pairs with the persistent admin-header notice
 * (ShippingMethodMismatchNotice). The notice tells the merchant a problem
 * exists from any admin page; this inline block shows the diagnosis on
 * the actual config page so they can fix it without leaving the form.
 *
 * Configured per-field by pointing system.xml's `<frontend_model>` at one of
 * the three thin subclasses in `MethodStatusDisplay/`:
 *
 *   <frontend_model>ETechFlow\NextDayEligibility\Block\Adminhtml\Form\Field\MethodStatusDisplay\NextDay</frontend_model>
 *   <frontend_model>ETechFlow\NextDayEligibility\Block\Adminhtml\Form\Field\MethodStatusDisplay\Standard</frontend_model>
 *   <frontend_model>ETechFlow\NextDayEligibility\Block\Adminhtml\Form\Field\MethodStatusDisplay\ClickCollect</frontend_model>
 *
 * Each subclass returns its TYPE_* constant from `getType()`. The base class
 * does the rest.
 */
abstract class MethodStatusDisplay extends Field
{
    public function __construct(
        Context $context,
        private readonly ShippingMethodAvailability $availability,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Subclass hook — one of the ShippingMethodAvailability::TYPE_* constants.
     */
    abstract protected function getType(): string;

    /**
     * Skip the field row chrome — render as an inline status panel.
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $analysis  = $this->availability->analyze($this->getType());
        $matched   = $analysis['matched'];
        $unmatched = $analysis['unmatched'];

        if (empty($matched) && empty($unmatched)) {
            return $this->renderNeutral();
        }

        if (empty($unmatched)) {
            return $this->renderOk(count($matched));
        }

        return $this->renderMismatch($matched, $unmatched);
    }

    /** Suppress the default `<td class="label">` cell so the panel spans full width. */
    public function render(AbstractElement $element): string
    {
        $html  = '<tr id="row_' . $element->getHtmlId() . '">';
        $html .= '<td class="value" colspan="2" style="padding-top: 0;">';
        $html .= $this->_getElementHtml($element);
        $html .= '</td></tr>';
        return $html;
    }

    private function renderNeutral(): string
    {
        return '<div style="padding: 8px 12px; background: #f3f4f6; border-radius: 4px; '
            . 'color: #6b7280; font-size: 12px; line-height: 1.4;">'
            . 'Not configured — the rule that uses this list is currently a no-op.'
            . '</div>';
    }

    private function renderOk(int $matchedCount): string
    {
        return '<div style="padding: 8px 12px; background: #ecfdf5; border-left: 3px solid #10b981; '
            . 'border-radius: 4px; color: #065f46; font-size: 12px; line-height: 1.4;">'
            . '<strong>&#10003; Status:</strong> '
            . $this->escapeHtml(sprintf(
                'All %d configured code%s match enabled shipping methods on this store.',
                $matchedCount,
                $matchedCount === 1 ? '' : 's'
            ))
            . '</div>';
    }

    /**
     * @param string[] $matched
     * @param string[] $unmatched
     */
    private function renderMismatch(array $matched, array $unmatched): string
    {
        $unmatchedHtml = implode(
            ', ',
            array_map(fn(string $code) => '<code>' . $this->escapeHtml($code) . '</code>', $unmatched)
        );

        $line1 = sprintf(
            '%d of %d configured code%s NOT found among enabled shipping methods on this store.',
            count($unmatched),
            count($matched) + count($unmatched),
            count($matched) + count($unmatched) === 1 ? '' : 's'
        );

        $line2 = 'Unmatched: ' . $unmatchedHtml . '.';
        $line3 = 'These codes won\'t be restricted because they aren\'t enabled. '
            . 'Run <code>bin/magento etechflow:nde:list-methods</code> to see every code available on this store.';

        return '<div style="padding: 10px 12px; background: #fef2f2; border-left: 3px solid #ef4444; '
            . 'border-radius: 4px; color: #991b1b; font-size: 12px; line-height: 1.5;">'
            . '<strong>&#9888; Mismatch:</strong> ' . $this->escapeHtml($line1) . '<br/>'
            . $line2 . '<br/>'
            . '<span style="color: #7f1d1d;">' . $line3 . '</span>'
            . '</div>';
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
