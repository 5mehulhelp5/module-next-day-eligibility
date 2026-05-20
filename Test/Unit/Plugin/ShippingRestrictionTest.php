<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Test\Unit\Plugin;

use ETechFlow\NextDayEligibility\Model\BackorderChecker;
use ETechFlow\NextDayEligibility\Model\Config;
use ETechFlow\NextDayEligibility\Model\IneligibilityChecker;
use ETechFlow\NextDayEligibility\Plugin\ShippingRestriction;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the merged shipping-restriction plugin filters rates correctly when:
 *  - Only the next-day rule applies
 *  - Only the backorder rule applies
 *  - Both rules apply (union of method codes removed)
 *  - Neither rule applies (rates pass through untouched)
 *  - An exception in either checker doesn't crash checkout
 */
class ShippingRestrictionTest extends TestCase
{
    /** @var Config|MockObject */
    private Config|MockObject $config;

    /** @var IneligibilityChecker|MockObject */
    private IneligibilityChecker|MockObject $ineligibilityChecker;

    /** @var BackorderChecker|MockObject */
    private BackorderChecker|MockObject $backorderChecker;

    /** @var LoggerInterface|MockObject */
    private LoggerInterface|MockObject $logger;

    /** @var ShippingRestriction */
    private ShippingRestriction $plugin;

    protected function setUp(): void
    {
        $this->config               = $this->createMock(Config::class);
        $this->ineligibilityChecker = $this->createMock(IneligibilityChecker::class);
        $this->backorderChecker     = $this->createMock(BackorderChecker::class);
        $this->logger               = $this->createMock(LoggerInterface::class);

        $this->plugin = new ShippingRestriction(
            $this->config,
            $this->ineligibilityChecker,
            $this->backorderChecker,
            $this->logger
        );
    }

    /**
     * Build a Rate mock with the given carrier + method codes.
     *
     * Rate uses Magento DataObject magic getters; getCarrier/getMethod aren't
     * declared on the class, so we have to register them via addMethods (same
     * pattern UpdateOnProductSaveTest uses for QuoteItem::getProductId).
     */
    private function buildRate(string $carrier, string $method): Rate|MockObject
    {
        $rate = $this->getMockBuilder(Rate::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCarrier', 'getMethod'])
            ->getMock();
        $rate->method('getCarrier')->willReturn($carrier);
        $rate->method('getMethod')->willReturn($method);
        return $rate;
    }

    /**
     * Build an Address mock returning the given rates as cart items (unused here)
     * — items are passed to the checkers as mocks so the plugin's array dereference
     * works without further setup.
     */
    private function buildAddress(): Address|MockObject
    {
        $address = $this->createMock(Address::class);
        $address->method('getAllItems')->willReturn([]);
        return $address;
    }

    public function testModuleDisabledReturnsRatesUnchanged(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        $rates = [['flatrate' => [$this->buildRate('flatrate', 'flatrate')]]];
        $result = $this->plugin->afterGetGroupedAllShippingRates($this->buildAddress(), $rates);

        $this->ineligibilityChecker->expects($this->never())->method('hasIneligibleItems');
        $this->backorderChecker->expects($this->never())->method('hasBackorderItems');
        $this->assertSame($rates, $result);
    }

    public function testEmptyRatesShortCircuit(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $result = $this->plugin->afterGetGroupedAllShippingRates($this->buildAddress(), []);

        $this->ineligibilityChecker->expects($this->never())->method('hasIneligibleItems');
        $this->assertSame([], $result);
    }

    public function testNextDayRuleAloneRemovesConfiguredCodes(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getShippingMethodCodes')->willReturn(['flatrate_flatrate']);
        $this->config->method('isRestrictExpressOnBackorder')->willReturn(false);
        $this->ineligibilityChecker->method('hasIneligibleItems')->willReturn(true);

        $rates = [
            'flatrate' => [$this->buildRate('flatrate', 'flatrate')],
            'tablerate' => [$this->buildRate('tablerate', 'bestway')],
        ];

        $result = $this->plugin->afterGetGroupedAllShippingRates($this->buildAddress(), $rates);

        $this->assertArrayNotHasKey('flatrate', $result, 'flatrate group should be dropped when its only rate was removed');
        $this->assertArrayHasKey('tablerate', $result);
        $this->assertCount(1, $result['tablerate']);
    }

    public function testBackorderRuleAloneRemovesConfiguredExpressCodes(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getShippingMethodCodes')->willReturn([]);  // no next-day codes
        $this->config->method('isRestrictExpressOnBackorder')->willReturn(true);
        $this->config->method('getBackorderExpressMethodCodes')->willReturn(['ups_NextDayAir']);
        $this->backorderChecker->method('hasBackorderItems')->willReturn(true);

        $rates = [
            'ups' => [
                $this->buildRate('ups', 'NextDayAir'),
                $this->buildRate('ups', 'Ground'),
            ],
        ];

        $result = $this->plugin->afterGetGroupedAllShippingRates($this->buildAddress(), $rates);

        $this->assertArrayHasKey('ups', $result);
        $this->assertCount(1, $result['ups'], 'NextDayAir should be removed but Ground should remain');
        $this->assertSame('Ground', $result['ups'][0]->getMethod());
    }

    public function testBothRulesFireAndUnionOfCodesRemoved(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getShippingMethodCodes')->willReturn(['flatrate_flatrate']);
        $this->config->method('isRestrictExpressOnBackorder')->willReturn(true);
        $this->config->method('getBackorderExpressMethodCodes')->willReturn(['ups_NextDayAir']);
        $this->ineligibilityChecker->method('hasIneligibleItems')->willReturn(true);
        $this->backorderChecker->method('hasBackorderItems')->willReturn(true);

        $rates = [
            'flatrate' => [$this->buildRate('flatrate', 'flatrate')],
            'ups' => [
                $this->buildRate('ups', 'NextDayAir'),
                $this->buildRate('ups', 'Ground'),
            ],
        ];

        $result = $this->plugin->afterGetGroupedAllShippingRates($this->buildAddress(), $rates);

        $this->assertArrayNotHasKey('flatrate', $result);
        $this->assertArrayHasKey('ups', $result);
        $this->assertCount(1, $result['ups']);
        $this->assertSame('Ground', $result['ups'][0]->getMethod());
    }

    public function testNeitherRuleAppliesRatesPassThrough(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getShippingMethodCodes')->willReturn(['flatrate_flatrate']);
        $this->config->method('isRestrictExpressOnBackorder')->willReturn(true);
        $this->config->method('getBackorderExpressMethodCodes')->willReturn(['ups_NextDayAir']);
        $this->ineligibilityChecker->method('hasIneligibleItems')->willReturn(false);
        $this->backorderChecker->method('hasBackorderItems')->willReturn(false);

        $rates = [
            'flatrate' => [$this->buildRate('flatrate', 'flatrate')],
            'ups' => [$this->buildRate('ups', 'NextDayAir')],
        ];

        $result = $this->plugin->afterGetGroupedAllShippingRates($this->buildAddress(), $rates);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('flatrate', $result);
        $this->assertArrayHasKey('ups', $result);
    }

    public function testBackorderRuleSkippedWhenToggleOff(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getShippingMethodCodes')->willReturn([]);
        $this->config->method('isRestrictExpressOnBackorder')->willReturn(false);
        // Even though backorder items exist, the toggle is off — rule must not run
        $this->backorderChecker->expects($this->never())->method('hasBackorderItems');

        $rates = ['ups' => [$this->buildRate('ups', 'NextDayAir')]];
        $result = $this->plugin->afterGetGroupedAllShippingRates($this->buildAddress(), $rates);

        $this->assertSame($rates, $result);
    }

    public function testSafetyNetReturnsOriginalRatesWhenFilterWouldEmptyAllMethods(): void
    {
        // Merchant misconfig: lists every method they offer as a "next-day code".
        // After filtering, no shipping methods remain → checkout would be stuck.
        // Plugin must short-circuit and return original rates, plus log a warning.
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getShippingMethodCodes')->willReturn(['freeshipping_freeshipping']);
        $this->config->method('isRestrictExpressOnBackorder')->willReturn(false);
        $this->ineligibilityChecker->method('hasIneligibleItems')->willReturn(true);

        $rates = [
            'freeshipping' => [$this->buildRate('freeshipping', 'freeshipping')],
        ];

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('would leave no methods available')
            );

        $result = $this->plugin->afterGetGroupedAllShippingRates($this->buildAddress(), $rates);

        // Customer keeps Free Delivery (the only option) — better wrong speed than stuck cart
        $this->assertSame($rates, $result);
    }

    public function testSafetyNetTriggersWhenBothRulesTogetherWouldEmptyEverything(): void
    {
        // Even more extreme: both rules together would remove every method.
        // Same safety net behavior — log + return originals.
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getShippingMethodCodes')->willReturn(['flatrate_flatrate']);
        $this->config->method('isRestrictExpressOnBackorder')->willReturn(true);
        $this->config->method('getBackorderExpressMethodCodes')->willReturn(['ups_NextDayAir']);
        $this->ineligibilityChecker->method('hasIneligibleItems')->willReturn(true);
        $this->backorderChecker->method('hasBackorderItems')->willReturn(true);

        $rates = [
            'flatrate' => [$this->buildRate('flatrate', 'flatrate')],
            'ups' => [$this->buildRate('ups', 'NextDayAir')],
        ];

        $this->logger->expects($this->once())->method('warning');

        $result = $this->plugin->afterGetGroupedAllShippingRates($this->buildAddress(), $rates);

        $this->assertSame($rates, $result);
    }

    public function testSafetyNetDoesNotTriggerWhenSomeMethodsRemain(): void
    {
        // Normal case: filter removes 1 of 2 methods, result is non-empty,
        // safety net should NOT fire (no warning logged).
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getShippingMethodCodes')->willReturn(['flatrate_flatrate']);
        $this->config->method('isRestrictExpressOnBackorder')->willReturn(false);
        $this->ineligibilityChecker->method('hasIneligibleItems')->willReturn(true);

        $rates = [
            'flatrate' => [$this->buildRate('flatrate', 'flatrate')],
            'tablerate' => [$this->buildRate('tablerate', 'bestway')],
        ];

        $this->logger->expects($this->never())->method('warning');

        $result = $this->plugin->afterGetGroupedAllShippingRates($this->buildAddress(), $rates);

        $this->assertArrayNotHasKey('flatrate', $result);
        $this->assertArrayHasKey('tablerate', $result);
    }

    public function testCheckerExceptionDoesNotCrashCheckout(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getShippingMethodCodes')->willReturn(['flatrate_flatrate']);
        $this->ineligibilityChecker->method('hasIneligibleItems')
            ->willThrowException(new \RuntimeException('DB exploded'));

        $this->logger->expects($this->once())->method('error');

        $rates = ['flatrate' => [$this->buildRate('flatrate', 'flatrate')]];
        $result = $this->plugin->afterGetGroupedAllShippingRates($this->buildAddress(), $rates);

        // Plugin must return the original rates rather than throw
        $this->assertSame($rates, $result);
    }

    // -------------------------------------------------------------------------
    // v1.4.3: Standard Methods whitelist mode
    // -------------------------------------------------------------------------

    public function testWhitelistModeKeepsOnlyStandardCodesOnIneligibleCart(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getStandardMethodCodes')
            ->willReturn(['flatrate_flatrate', 'freeshipping_freeshipping']);
        $this->config->method('isRestrictExpressOnBackorder')->willReturn(false);
        $this->ineligibilityChecker->method('hasIneligibleItems')->willReturn(true);

        $rates = [
            'flatrate'     => [$this->buildRate('flatrate', 'flatrate')],
            'freeshipping' => [$this->buildRate('freeshipping', 'freeshipping')],
            'ups'          => [$this->buildRate('ups', 'NextDayAir')],
            'fedex'        => [$this->buildRate('fedex', 'FEDEX_2_DAY')],
        ];

        $result = $this->plugin->afterGetGroupedAllShippingRates($this->buildAddress(), $rates);

        // Whitelist mode keeps ONLY the standard codes; ups + fedex dropped
        $this->assertArrayHasKey('flatrate', $result);
        $this->assertArrayHasKey('freeshipping', $result);
        $this->assertArrayNotHasKey('ups', $result);
        $this->assertArrayNotHasKey('fedex', $result);
    }

    public function testWhitelistModeIsBypassedWhenCartIsAllEligible(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getStandardMethodCodes')
            ->willReturn(['flatrate_flatrate']);
        $this->config->method('getShippingMethodCodes')->willReturn([]);
        $this->config->method('isRestrictExpressOnBackorder')->willReturn(false);

        // Cart has NO ineligible items — whitelist mode must NOT engage
        $this->ineligibilityChecker->method('hasIneligibleItems')->willReturn(false);

        $rates = [
            'flatrate' => [$this->buildRate('flatrate', 'flatrate')],
            'ups'      => [$this->buildRate('ups', 'NextDayAir')],
        ];

        $result = $this->plugin->afterGetGroupedAllShippingRates($this->buildAddress(), $rates);

        // All carriers preserved — eligible cart sees everything
        $this->assertSame($rates, $result);
    }

    public function testWhitelistModeReturnsOriginalRatesWhenWhitelistMatchesNothing(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        // Misconfigured: whitelist contains codes that don't exist among the
        // current rate list. Plugin must NOT leave the cart with zero options.
        $this->config->method('getStandardMethodCodes')
            ->willReturn(['nonexistent_carrier_method']);
        $this->config->method('isRestrictExpressOnBackorder')->willReturn(false);
        $this->ineligibilityChecker->method('hasIneligibleItems')->willReturn(true);

        $rates = [
            'flatrate' => [$this->buildRate('flatrate', 'flatrate')],
            'ups'      => [$this->buildRate('ups', 'NextDayAir')],
        ];

        $this->logger->expects($this->once())->method('warning');

        $result = $this->plugin->afterGetGroupedAllShippingRates($this->buildAddress(), $rates);

        // Safety net: original rates returned, checkout stays usable
        $this->assertSame($rates, $result);
    }

    public function testWhitelistModeLayersBackorderRestrictionOnTop(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        // Standard whitelist includes ups_NextDayAir — but it's also a backorder-
        // restricted express method, so it should still get filtered out when
        // the cart has backorder items.
        $this->config->method('getStandardMethodCodes')
            ->willReturn(['flatrate_flatrate', 'ups_NextDayAir']);
        $this->config->method('isRestrictExpressOnBackorder')->willReturn(true);
        $this->config->method('getBackorderExpressMethodCodes')
            ->willReturn(['ups_NextDayAir']);
        $this->ineligibilityChecker->method('hasIneligibleItems')->willReturn(true);
        $this->backorderChecker->method('hasBackorderItems')->willReturn(true);

        $rates = [
            'flatrate' => [$this->buildRate('flatrate', 'flatrate')],
            'ups'      => [$this->buildRate('ups', 'NextDayAir')],
            'fedex'    => [$this->buildRate('fedex', 'FEDEX_2_DAY')],
        ];

        $result = $this->plugin->afterGetGroupedAllShippingRates($this->buildAddress(), $rates);

        // flatrate kept (whitelisted, no backorder match)
        // ups dropped (whitelisted but backorder-restricted)
        // fedex dropped (not whitelisted)
        $this->assertArrayHasKey('flatrate', $result);
        $this->assertArrayNotHasKey('ups', $result);
        $this->assertArrayNotHasKey('fedex', $result);
    }
}
