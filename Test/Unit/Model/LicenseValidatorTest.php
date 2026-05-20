<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Test\Unit\Model;

use ETechFlow\NextDayEligibility\Model\LicenseValidator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LicenseValidatorTest extends TestCase
{
    /** @var ScopeConfigInterface|MockObject */
    private ScopeConfigInterface|MockObject $scopeConfig;

    /** @var StoreManagerInterface|MockObject */
    private StoreManagerInterface|MockObject $storeManager;

    /** @var LicenseValidator */
    private LicenseValidator $validator;

    protected function setUp(): void
    {
        $this->scopeConfig  = $this->createMock(ScopeConfigInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->validator    = new LicenseValidator($this->scopeConfig, $this->storeManager);
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

    private function setLicenseKey(string $key, string $bundleKey = '', string $productionEnvironment = '1'): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnCallback(static function ($path) use ($key, $bundleKey, $productionEnvironment) {
                return match ($path) {
                    LicenseValidator::XML_PATH_LICENSE_KEY            => $key,
                    LicenseValidator::XML_PATH_BUNDLE_LICENSE_KEY     => $bundleKey,
                    LicenseValidator::XML_PATH_PRODUCTION_ENVIRONMENT => $productionEnvironment,
                    default                                           => '',
                };
            });
    }

    public function testDevelopmentHostBypassesLicensing(): void
    {
        $this->setHost('app.magento2.test');
        // No license key set
        $this->scopeConfig->method('getValue')->willReturn('');

        $this->assertTrue($this->validator->isValid());
    }

    public function testLocalhostBypassesLicensing(): void
    {
        $this->setHost('localhost');
        $this->scopeConfig->method('getValue')->willReturn('');

        $this->assertTrue($this->validator->isValid());
    }

    public function testProductionHostWithoutKeyIsInvalid(): void
    {
        $this->setHost('shop.example.com');
        $this->setLicenseKey('');

        $this->assertFalse($this->validator->isValid());
    }

    public function testProductionHostWithCorrectKeyIsValid(): void
    {
        $host = 'shop.example.com';
        $this->setHost($host);

        // Generate the key the way the production code would
        $expectedKey = $this->validator->computeKey($host);

        $this->setLicenseKey($expectedKey);

        $this->assertTrue($this->validator->isValid());
    }

    public function testProductionHostWithWrongKeyIsInvalid(): void
    {
        $this->setHost('shop.example.com');
        $this->setLicenseKey('totally-wrong-key');

        $this->assertFalse($this->validator->isValid());
    }

    public function testKeyForOneHostDoesNotValidateOnAnother(): void
    {
        // Generate a key for a different host
        $keyForOtherHost = $this->validator->computeKey('other.example.com');

        $this->setHost('shop.example.com');
        $this->setLicenseKey($keyForOtherHost);

        $this->assertFalse($this->validator->isValid());
    }

    public function testKeyValidationIsCaseInsensitiveOnHost(): void
    {
        $upperHost = 'Shop.Example.Com';
        $lowerHost = 'shop.example.com';

        // Key generated from lower-case host
        $key = $this->validator->computeKey($lowerHost);

        // Validation against upper-case host should still match
        $this->setHost($upperHost);
        $this->setLicenseKey($key);

        $this->assertTrue($this->validator->isValid());
    }

    public function testBundleKeyAloneActivatesModule(): void
    {
        $host = 'shop.example.com';
        $this->setHost($host);

        // No per-module key, but a valid bundle key
        $bundleKey = $this->validator->computeBundleKey($host);
        $this->setLicenseKey('', $bundleKey);

        $this->assertTrue($this->validator->isValid());
    }

    public function testWrongBundleKeyDoesNotActivateModule(): void
    {
        $this->setHost('shop.example.com');
        $this->setLicenseKey('', 'this-is-not-a-real-bundle-key');

        $this->assertFalse($this->validator->isValid());
    }

    public function testBundleKeyForDifferentHostDoesNotActivate(): void
    {
        $bundleKeyForOtherHost = $this->validator->computeBundleKey('other.example.com');

        $this->setHost('shop.example.com');
        $this->setLicenseKey('', $bundleKeyForOtherHost);

        $this->assertFalse($this->validator->isValid());
    }

    public function testPerModuleAndBundleKeysAreDifferent(): void
    {
        $host = 'shop.example.com';
        $moduleKey = $this->validator->computeKey($host);
        $bundleKey = $this->validator->computeBundleKey($host);

        $this->assertNotSame($moduleKey, $bundleKey);
    }

    // --- www. normalization tests ---

    public function testWwwPrefixIsNormalizedSoOneKeyCoversBoth(): void
    {
        // A key minted for the apex must validate when the store is on the www subdomain
        $apexKey = $this->validator->computeKey('shop.coolstore.com');

        $this->setHost('www.shop.coolstore.com');
        $this->setLicenseKey($apexKey);

        $this->assertTrue($this->validator->isValid());
    }

    public function testKeyMintedForWwwAlsoActivatesOnApex(): void
    {
        // And the reverse — a key minted from a www host activates on the apex
        $wwwKey = $this->validator->computeKey('www.coolstore.com');

        $this->setHost('coolstore.com');
        $this->setLicenseKey($wwwKey);

        $this->assertTrue($this->validator->isValid());
    }

    public function testComputeKeyIsCaseAndWwwInsensitive(): void
    {
        $this->assertSame(
            $this->validator->computeKey('coolstore.com'),
            $this->validator->computeKey('www.coolstore.com')
        );
        $this->assertSame(
            $this->validator->computeKey('coolstore.com'),
            $this->validator->computeKey('WWW.CoolStore.COM')
        );
    }

    // --- expanded dev-host detection ---

    /**
     * @dataProvider devHostProvider
     */
    public function testDevelopmentHostsBypassLicensing(string $host): void
    {
        $this->setHost($host);
        // No license configured — must still validate because host is recognised as dev/staging
        $this->scopeConfig->method('getValue')->willReturn('');

        $this->assertTrue($this->validator->isValid(), "Expected $host to bypass licensing");
    }

    public static function devHostProvider(): array
    {
        return [
            'localhost'                  => ['localhost'],
            'loopback IPv4'              => ['127.0.0.1'],
            'private 10/8'               => ['10.0.5.20'],
            'private 192.168/16'         => ['192.168.1.10'],
            'private 172.16/12'          => ['172.16.0.5'],
            'private 172.31/12'          => ['172.31.255.5'],
            '.test TLD'                  => ['shop.test'],
            '.local TLD'                 => ['shop.local'],
            '.localhost TLD'             => ['shop.localhost'],
            '.dev TLD'                   => ['shop.dev'],
            '.example TLD'               => ['shop.example'],
            '.invalid TLD'               => ['shop.invalid'],
            'staging. subdomain'         => ['staging.coolstore.com'],
            'stage. subdomain'           => ['stage.coolstore.com'],
            'dev. subdomain'             => ['dev.coolstore.com'],
            'qa. subdomain'              => ['qa.coolstore.com'],
            'uat. subdomain'             => ['uat.coolstore.com'],
            'test. subdomain'            => ['test.coolstore.com'],
            'preview. subdomain'         => ['preview.coolstore.com'],
            'sandbox. subdomain'         => ['sandbox.coolstore.com'],
            'hyphen-staging in apex'     => ['coolstore-staging.com'],
            'hyphen-dev in apex'         => ['coolstore-dev.com'],
            'hyphen-uat in apex'         => ['coolstore-uat.com'],
            'Adobe Cloud magento.cloud'  => ['coolstore.magento.cloud'],
            'Adobe Cloud magentocloud'   => ['coolstore.magentocloud.com'],
            'ngrok.io tunnel'            => ['abc123.ngrok.io'],
            'ngrok-free.app tunnel'      => ['abc123.ngrok-free.app'],
            'loca.lt tunnel'             => ['mystore.loca.lt'],
        ];
    }

    // --- Production Environment toggle tests ---

    public function testToggleOffBypassesLicensingOnProductionHost(): void
    {
        $this->setHost('realstore.com');
        // No license key, but toggle set to "0" (No)
        $this->setLicenseKey('', '', '0');

        $this->assertTrue($this->validator->isValid(), 'Toggle = No should bypass license check entirely');
    }

    public function testToggleOnRequiresValidKey(): void
    {
        $this->setHost('realstore.com');
        // Empty license key, toggle set to "1" (Yes — explicit production)
        $this->setLicenseKey('', '', '1');

        $this->assertFalse($this->validator->isValid(), 'Toggle = Yes should require a valid licence key');
    }

    public function testToggleNotSetTreatedAsProduction(): void
    {
        $this->setHost('realstore.com');
        // No production_environment value at all (legacy upgrade scenario)
        $this->setLicenseKey('', '', '');

        $this->assertFalse(
            $this->validator->isValid(),
            'Unset toggle must default to production (Yes) — protects upgrades from accidentally going unlicensed'
        );
    }

    public function testToggleOffOverridesValidKey(): void
    {
        // Customer has a valid key for their original domain
        $key = $this->validator->computeKey('originalstore.com');

        // But they cloned the DB to a totally different test domain
        $this->setHost('completely-different-test-site.example');
        $this->setLicenseKey($key, '', '0');

        // Toggle = No means we don't care about the key at all
        $this->assertTrue($this->validator->isValid());
    }

    // --- end Production Environment toggle tests ---

    public function testProductionHostsDoNotBypassLicensing(): void
    {
        // Sanity check — real-looking domains should still require a license
        $productionHosts = [
            'coolstore.com',
            'www.coolstore.com',
            'shop.coolstore.com',
            'eu.coolstore.com',
            'coolstore.co.uk',
            'coolstore.io',
        ];

        foreach ($productionHosts as $host) {
            $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
            $this->storeManager = $this->createMock(StoreManagerInterface::class);
            $this->validator    = new LicenseValidator($this->scopeConfig, $this->storeManager);

            $this->setHost($host);
            $this->scopeConfig->method('getValue')->willReturn('');

            $this->assertFalse(
                $this->validator->isValid(),
                "Expected $host to require a license"
            );
        }
    }
}
