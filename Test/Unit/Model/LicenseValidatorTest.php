<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Test\Unit\Model;

use ETechFlow\NextDayEligibility\Model\LicenseValidator;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Portal-only licensing tests.
 *
 * The module ships NO signing secret, so there is nothing to "compute a key"
 * from any more. Every test drives isValid() through the same surfaces an
 * attacker or a legitimate customer actually controls: the admin config values
 * and whether the portal is reachable. The centrepiece is
 * {@see testForgedKeyAndAttackerControlledConfigCannotBypass} — the guarantee
 * that nobody can license the module for their own domain without the portal.
 */
class LicenseValidatorTest extends TestCase
{
    private const PORTAL = 'https://portal.etechflow.com/api';

    /** @var ScopeConfigInterface|MockObject */
    private ScopeConfigInterface|MockObject $scopeConfig;

    /** @var StoreManagerInterface|MockObject */
    private StoreManagerInterface|MockObject $storeManager;

    /** @var CacheInterface|MockObject */
    private CacheInterface|MockObject $cache;

    /** @var Curl|MockObject */
    private Curl|MockObject $curl;

    private LicenseValidator $validator;

    protected function setUp(): void
    {
        $this->scopeConfig  = $this->createMock(ScopeConfigInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->cache        = $this->createMock(CacheInterface::class);
        $this->curl         = $this->createMock(Curl::class);
        $this->validator    = new LicenseValidator(
            $this->scopeConfig,
            $this->storeManager,
            $this->cache,
            $this->curl
        );
    }

    private function setHost(string $host, string $protocol = 'https'): void
    {
        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBaseUrl'])
            ->getMock();
        $store->method('getBaseUrl')->willReturn("{$protocol}://{$host}/");
        $this->storeManager->method('getStore')->willReturn($store);
    }

    /**
     * @param array<string,string> $config path => value; unlisted paths return ''.
     */
    private function setConfig(array $config): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnCallback(static fn ($path) => $config[$path] ?? '');
    }

    /** Portal reachable, returning the given HTTP status + body. */
    private function setPortalResponse(int $status, string $body): void
    {
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn($body);
    }

    /** Cache miss everywhere (no prior success, no cached verdict). */
    private function setCacheMiss(): void
    {
        $this->cache->method('load')->willReturn(false);
    }

    // ---------------------------------------------------------------- portal says yes

    public function testPortalIssuedKeyValidatedByPortalIsValid(): void
    {
        $this->setHost('shop.example.com');
        $this->setConfig([
            LicenseValidator::XML_PATH_LICENSE_KEY => 'SP-live-abc123',
            LicenseValidator::XML_PATH_PORTAL_API_URL => self::PORTAL,
        ]);
        $this->setCacheMiss();
        $this->setPortalResponse(200, '{"valid":true}');

        $this->assertTrue($this->validator->isValid());
    }

    public function testBundleKeyValidatedByPortalActivatesModule(): void
    {
        $this->setHost('shop.example.com');
        $this->setConfig([
            LicenseValidator::XML_PATH_LICENSE_KEY        => '',
            LicenseValidator::XML_PATH_BUNDLE_LICENSE_KEY => 'SP-bundle-xyz',
            LicenseValidator::XML_PATH_PORTAL_API_URL     => self::PORTAL,
        ]);
        $this->setCacheMiss();
        $this->setPortalResponse(200, '{"valid":true}');

        $this->assertTrue($this->validator->isValid());
    }

    // ---------------------------------------------------------------- portal says no

    public function testPortalRejectMakesModuleInvalid(): void
    {
        $this->setHost('shop.example.com');
        $this->setConfig([
            LicenseValidator::XML_PATH_LICENSE_KEY => 'SP-revoked',
            LicenseValidator::XML_PATH_PORTAL_API_URL => self::PORTAL,
        ]);
        $this->setCacheMiss();
        $this->setPortalResponse(200, '{"valid":false}');

        $this->assertFalse($this->validator->isValid());
    }

    public function testPortal403IpRevokedMakesModuleInvalid(): void
    {
        $this->setHost('shop.example.com');
        $this->setConfig([
            LicenseValidator::XML_PATH_LICENSE_KEY => 'SP-ip-removed',
            LicenseValidator::XML_PATH_PORTAL_API_URL => self::PORTAL,
        ]);
        $this->setCacheMiss();
        $this->setPortalResponse(403, '');

        $this->assertFalse($this->validator->isValid());
    }

    public function testExplicitRevokeFlagWinsOverEverything(): void
    {
        $this->setHost('shop.example.com');
        $this->setConfig([
            LicenseValidator::XML_PATH_LICENSE_KEY => 'SP-live-abc123',
            LicenseValidator::XML_PATH_PORTAL_API_URL => self::PORTAL,
            'etechflow_nextdayeligibility/license/revoked' => '1',
        ]);
        $this->setCacheMiss();
        // Portal would say yes, but the revoke flag short-circuits before any call.
        $this->setPortalResponse(200, '{"valid":true}');

        $this->assertFalse($this->validator->isValid());
    }

    // ---------------------------------------------------------------- THE HARD TEST

    /**
     * The guarantee the whole rewrite exists for: a third party who owns the
     * module source can NOT license it for their own domain. They can set any
     * admin config they like (including a key that starts with "SP-" and every
     * field the old client-side "grace" used to trust), and they can keep the
     * portal unreachable. It must still come back invalid, because the only
     * thing that can say "yes" is the portal, and the only thing that seeds the
     * offline grace is a genuine portal success — neither of which they have.
     */
    public function testForgedKeyAndAttackerControlledConfigCannotBypass(): void
    {
        $this->setHost('totally-pirated-store.com');
        $this->setConfig([
            // A plausibly-formatted key the attacker typed in themselves.
            LicenseValidator::XML_PATH_LICENSE_KEY => 'SP-i-made-this-up',
            // Portal deliberately left unconfigured so no server can reject them.
            LicenseValidator::XML_PATH_PORTAL_API_URL => '',
            LicenseValidator::XML_PATH_PORTAL_URL     => '',
            // Every field the removed local-grace path used to trust, all forged.
            'etechflow_nextdayeligibility/license/issued_key'        => 'SP-i-made-this-up',
            'etechflow_nextdayeligibility/license/issued_domain'     => 'totally-pirated-store.com',
            'etechflow_nextdayeligibility/license/stripe_session_id' => 'cs_fake_123',
            'etechflow_nextdayeligibility/license/issued_at'         => (string) time(),
        ]);
        // No cached genuine success exists for them.
        $this->setCacheMiss();

        $this->assertFalse(
            $this->validator->isValid(),
            'A forged key with no portal must never license the module.'
        );
    }

    public function testNonPortalKeyFormatIsRejected(): void
    {
        $this->setHost('shop.example.com');
        $this->setConfig([
            LicenseValidator::XML_PATH_LICENSE_KEY => 'some-legacy-hmac-looking-key',
            LicenseValidator::XML_PATH_PORTAL_API_URL => self::PORTAL,
        ]);
        $this->setCacheMiss();

        $this->assertFalse($this->validator->isValid());
    }

    public function testNoKeyIsInvalid(): void
    {
        $this->setHost('shop.example.com');
        $this->setConfig([LicenseValidator::XML_PATH_PORTAL_API_URL => self::PORTAL]);
        $this->setCacheMiss();

        $this->assertFalse($this->validator->isValid());
    }

    // ---------------------------------------------------------------- offline grace

    public function testGraceFromPriorSuccessKeepsStorefrontLiveWhilePortalUnreachable(): void
    {
        $host = 'shop.example.com';
        $key  = 'SP-live-abc123';
        $this->setHost($host);
        $this->setConfig([
            LicenseValidator::XML_PATH_LICENSE_KEY => $key,
            // Portal unreachable (unconfigured) this request.
            LicenseValidator::XML_PATH_PORTAL_API_URL => '',
            LicenseValidator::XML_PATH_PORTAL_URL     => '',
        ]);

        // A genuine portal success was cached recently (only writeGrace can do this).
        $graceKey = 'etf_nde_lic_grace_' . md5($host . ':' . $key);
        $this->cache->method('load')->willReturnCallback(
            static fn ($k) => $k === $graceKey ? (string) time() : false
        );

        $this->assertTrue($this->validator->isValid());
    }

    public function testGraceDoesNotApplyToADifferentHost(): void
    {
        // Grace was seeded for shop.example.com; the pirate is on another host,
        // so their grace-key lookup misses and they get nothing.
        $goodGraceKey = 'etf_nde_lic_grace_' . md5('shop.example.com:SP-live-abc123');
        $this->cache->method('load')->willReturnCallback(
            static fn ($k) => $k === $goodGraceKey ? (string) time() : false
        );

        $this->setHost('totally-pirated-store.com');
        $this->setConfig([
            LicenseValidator::XML_PATH_LICENSE_KEY => 'SP-live-abc123',
            LicenseValidator::XML_PATH_PORTAL_API_URL => '',
            LicenseValidator::XML_PATH_PORTAL_URL     => '',
        ]);

        $this->assertFalse($this->validator->isValid());
    }

    public function testPortalRejectIsNotMaskedByStaleGrace(): void
    {
        $host = 'shop.example.com';
        $key  = 'SP-revoked';
        $this->setHost($host);
        $this->setConfig([
            LicenseValidator::XML_PATH_LICENSE_KEY => $key,
            LicenseValidator::XML_PATH_PORTAL_API_URL => self::PORTAL,
        ]);

        // Even with a cached prior success, an explicit portal reject wins.
        $graceKey = 'etf_nde_lic_grace_' . md5($host . ':' . $key);
        $this->cache->method('load')->willReturnCallback(
            static fn ($k) => $k === $graceKey ? (string) time() : false
        );
        $this->setPortalResponse(403, '');

        $this->assertFalse($this->validator->isValid());
    }
}
