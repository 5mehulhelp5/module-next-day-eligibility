<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Test\Unit\Model;

use ETechFlow\NextDayEligibility\Model\BackorderChecker;
use ETechFlow\NextDayEligibility\Model\Config;
use ETechFlow\NextDayEligibility\Model\ConfigProvider;
use ETechFlow\NextDayEligibility\Model\IneligibilityChecker;
use ETechFlow\NextDayEligibility\Model\SalableStockChecker;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the checkout banner state is driven by EITHER rule firing:
 *  - Next-day eligibility (existing v1.0.4 behavior)
 *  - Backorder express restriction (folded in v1.1.0)
 */
class ConfigProviderTest extends TestCase
{
    /** @var Config|MockObject */
    private Config|MockObject $config;

    /** @var CheckoutSession|MockObject */
    private CheckoutSession|MockObject $checkoutSession;

    /** @var IneligibilityChecker|MockObject */
    private IneligibilityChecker|MockObject $ineligibilityChecker;

    /** @var BackorderChecker|MockObject */
    private BackorderChecker|MockObject $backorderChecker;

    /** @var SalableStockChecker|MockObject */
    private SalableStockChecker|MockObject $salableStockChecker;

    /** @var LoggerInterface|MockObject */
    private LoggerInterface|MockObject $logger;

    /** @var ConfigProvider */
    private ConfigProvider $provider;

    protected function setUp(): void
    {
        $this->config               = $this->createMock(Config::class);
        $this->checkoutSession      = $this->createMock(CheckoutSession::class);
        $this->ineligibilityChecker = $this->createMock(IneligibilityChecker::class);
        $this->backorderChecker     = $this->createMock(BackorderChecker::class);
        $this->salableStockChecker  = $this->createMock(SalableStockChecker::class);
        $this->logger               = $this->createMock(LoggerInterface::class);

        $quote = $this->createMock(Quote::class);
        $quote->method('getAllItems')->willReturn([]);
        $this->checkoutSession->method('getQuote')->willReturn($quote);

        $this->provider = new ConfigProvider(
            $this->config,
            $this->checkoutSession,
            $this->ineligibilityChecker,
            $this->backorderChecker,
            $this->salableStockChecker,
            $this->logger
        );
    }

    public function testReturnsNotRestrictedWhenModuleDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        $cfg = $this->provider->getConfig();

        $this->assertFalse($cfg['nextDayEligibility']['isRestricted']);
    }

    public function testReturnsNotRestrictedWhenNoticeDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isShowNotice')->willReturn(false);

        $cfg = $this->provider->getConfig();

        $this->assertFalse($cfg['nextDayEligibility']['isRestricted']);
    }

    public function testRestrictedWhenNextDayRuleFires(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isShowNotice')->willReturn(true);
        $this->config->method('getShippingMethodCodes')->willReturn(['flatrate_flatrate']);
        $this->ineligibilityChecker->method('hasIneligibleItems')->willReturn(true);

        $cfg = $this->provider->getConfig();

        $this->assertTrue($cfg['nextDayEligibility']['isRestricted']);
    }

    public function testRestrictedWhenBackorderRuleFires(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isShowNotice')->willReturn(true);
        $this->config->method('getShippingMethodCodes')->willReturn([]);
        $this->config->method('isRestrictExpressOnBackorder')->willReturn(true);
        $this->config->method('getBackorderExpressMethodCodes')->willReturn(['ups_NextDayAir']);
        $this->backorderChecker->method('hasBackorderItems')->willReturn(true);

        $cfg = $this->provider->getConfig();

        $this->assertTrue($cfg['nextDayEligibility']['isRestricted']);
    }

    public function testNotRestrictedWhenNeitherRuleFires(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isShowNotice')->willReturn(true);
        $this->config->method('getShippingMethodCodes')->willReturn(['flatrate_flatrate']);
        $this->config->method('isRestrictExpressOnBackorder')->willReturn(true);
        $this->config->method('getBackorderExpressMethodCodes')->willReturn(['ups_NextDayAir']);
        $this->ineligibilityChecker->method('hasIneligibleItems')->willReturn(false);
        $this->backorderChecker->method('hasBackorderItems')->willReturn(false);

        $cfg = $this->provider->getConfig();

        $this->assertFalse($cfg['nextDayEligibility']['isRestricted']);
    }

    public function testBackorderCheckerSkippedWhenToggleOff(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isShowNotice')->willReturn(true);
        $this->config->method('getShippingMethodCodes')->willReturn([]);
        $this->config->method('isRestrictExpressOnBackorder')->willReturn(false);

        // Even if backorder items exist, the checker must NOT be invoked
        $this->backorderChecker->expects($this->never())->method('hasBackorderItems');

        $cfg = $this->provider->getConfig();

        $this->assertFalse($cfg['nextDayEligibility']['isRestricted']);
    }

    public function testCheckerExceptionFallsBackToNotRestricted(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isShowNotice')->willReturn(true);
        $this->config->method('getShippingMethodCodes')->willReturn(['flatrate_flatrate']);
        $this->ineligibilityChecker->method('hasIneligibleItems')
            ->willThrowException(new \RuntimeException('Quote not loaded'));

        $this->logger->expects($this->once())->method('error');

        $cfg = $this->provider->getConfig();

        $this->assertFalse($cfg['nextDayEligibility']['isRestricted']);
    }

    public function testConfigPayloadIncludesAllNoticeFields(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isShowNotice')->willReturn(true);
        $this->config->method('getShippingMethodCodes')->willReturn(['flatrate_flatrate']);
        $this->config->method('getNoticeStyle')->willReturn('info');
        $this->config->method('getNoticeTitle')->willReturn('Heads up');
        $this->config->method('getNoticeMessage')->willReturn('Mixed cart');
        $this->ineligibilityChecker->method('hasIneligibleItems')->willReturn(true);

        $cfg = $this->provider->getConfig();

        $this->assertSame('info', $cfg['nextDayEligibility']['noticeStyle']);
        $this->assertSame('Heads up', $cfg['nextDayEligibility']['noticeTitle']);
        $this->assertSame('Mixed cart', $cfg['nextDayEligibility']['noticeMessage']);
    }

    public function testRestrictedWhenSalableShortfallFires(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isShowNotice')->willReturn(true);
        $this->config->method('getShippingMethodCodes')->willReturn([]);
        $this->config->method('isRestrictExpressOnBackorder')->willReturn(false);
        $this->config->method('isRestrictOnInsufficientSalable')->willReturn(true);
        $this->salableStockChecker->method('hasShortfall')->willReturn(true);

        $cfg = $this->provider->getConfig();

        $this->assertTrue($cfg['nextDayEligibility']['isRestricted']);
    }

    public function testSalableCheckerSkippedWhenToggleOff(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isShowNotice')->willReturn(true);
        $this->config->method('getShippingMethodCodes')->willReturn([]);
        $this->config->method('isRestrictExpressOnBackorder')->willReturn(false);
        $this->config->method('isRestrictOnInsufficientSalable')->willReturn(false);

        // Toggle off — the checker must never be consulted.
        $this->salableStockChecker->expects($this->never())->method('hasShortfall');

        $cfg = $this->provider->getConfig();

        $this->assertFalse($cfg['nextDayEligibility']['isRestricted']);
    }
}
