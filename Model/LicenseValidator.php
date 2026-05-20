<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Validates the per-domain license key for this module.
 *
 * Customers receive a license key bound to their Magento base URL host.
 * The module gates its behaviour on this validation: an invalid key causes
 * the module to silently no-op so customers can install on staging without
 * breaking their storefront.
 */
class LicenseValidator
{
    public const XML_PATH_LICENSE_KEY = 'etechflow_nextdayeligibility/license/license_key';

    /**
     * "Production Environment" toggle path. When set to 0 (No), the module
     * bypasses license validation entirely — for use on dev/staging installs
     * with non-standard domains. Industry-standard pattern (Amasty, Aheadworks).
     */
    public const XML_PATH_PRODUCTION_ENVIRONMENT = 'etechflow_nextdayeligibility/license/production_environment';

    /** Shared config path — same value across all eTechFlow modules. */
    public const XML_PATH_BUNDLE_LICENSE_KEY = 'etechflow_bundle/license/license_key';

    private const MODULE_ID = 'next-day-eligibility';

    /** Shared bundle identifier — must match across all eTechFlow modules. */
    private const BUNDLE_ID = 'etechflow-bundle';

    /** Per-module HMAC secret. Split across constants to make casual extraction harder. */
    private const SECRET_FRAGMENTS = [
        'eTF-NDE-2026',
        'a8c2-fE4d',
        '7B9k-Lm3p',
        'Q5xW-yH8r',
    ];

    /** Shared bundle HMAC secret. MUST be identical in every eTechFlow module's LicenseValidator. */
    private const BUNDLE_SECRET_FRAGMENTS = [
        'eTF-BUNDLE-2026',
        'k2D9-mP4x',
        'L8nR-vH2j',
        'X7tY-zW5q',
    ];

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface  $scopeConfig
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Whether the module is licensed for the current Magento install.
     *
     * Returns true when:
     *  - The current host matches a development pattern (.test, .local, localhost), OR
     *  - The configured license_key matches the expected key for the current host.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        $host = $this->getCurrentHost();

        if ($host === '') {
            return false;
        }

        // "Production Environment" toggle — when set to No, skip licensing entirely.
        // Industry-standard pattern: customers can clone production DB to dev/staging
        // on any domain and flip this off, without needing a separate licence key.
        if (!$this->isProductionEnvironment()) {
            return true;
        }

        if ($this->isDevelopmentHost($host)) {
            return true;
        }

        // Per-module key: activates this module only
        $configuredKey = $this->getConfiguredKey();
        if ($configuredKey !== '' && hash_equals($this->computeKey($host), $configuredKey)) {
            return true;
        }

        // Bundle key: a single key sold as the 3-module bundle activates all eTechFlow modules
        $bundleKey = $this->getConfiguredBundleKey();
        if ($bundleKey !== '' && hash_equals($this->computeBundleKey($host), $bundleKey)) {
            return true;
        }

        return false;
    }

    /**
     * Compute the license key for an arbitrary host. Used by both validation
     * and the CLI key-generation tool. Host is canonicalized first so
     * www.example.com and example.com always yield the same key.
     *
     * @param string $host
     * @return string
     */
    public function computeKey(string $host): string
    {
        $payload = $this->canonicalize($host) . ':' . self::MODULE_ID;
        $secret  = implode('', self::SECRET_FRAGMENTS);
        $raw     = hash_hmac('sha256', $payload, $secret, true);

        // URL-safe base64 without padding
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Compute the bundle license key for an arbitrary host. The same algorithm
     * runs inside every eTechFlow module, so one bundle key activates all of them.
     *
     * @param string $host
     * @return string
     */
    public function computeBundleKey(string $host): string
    {
        $payload = $this->canonicalize($host) . ':' . self::BUNDLE_ID;
        $secret  = implode('', self::BUNDLE_SECRET_FRAGMENTS);
        $raw     = hash_hmac('sha256', $payload, $secret, true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Canonicalize a host for licensing comparison.
     *
     * Lowercases, trims whitespace, and strips a single leading "www." prefix.
     * Treats www.example.com and example.com as the same site — every major
     * module vendor (Amasty, Aheadworks, MageWorx, Mageplaza) does this.
     *
     * @param string $host
     * @return string
     */
    private function canonicalize(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }

    /**
     * Read the configured license key, trimmed.
     *
     * @return string
     */
    public function getConfiguredKey(): string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_LICENSE_KEY,
            ScopeInterface::SCOPE_STORE
        );

        return trim((string) $value);
    }

    /**
     * Read the configured bundle license key, trimmed.
     *
     * @return string
     */
    public function getConfiguredBundleKey(): string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_BUNDLE_LICENSE_KEY,
            ScopeInterface::SCOPE_STORE
        );

        return trim((string) $value);
    }

    /**
     * Whether the current store is flagged as a production environment.
     *
     * Defaults to TRUE if the config has never been touched — so existing
     * customers upgrading from a previous version aren't unexpectedly licensed.
     * A merchant must EXPLICITLY set it to "No" on dev/staging installs.
     *
     * @return bool
     */
    public function isProductionEnvironment(): bool
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_PRODUCTION_ENVIRONMENT,
            ScopeInterface::SCOPE_STORE
        );

        // Treat unset (null/empty) as Yes — safest default for upgrade compatibility.
        // Customer must EXPLICITLY save "No" (stored as "0") to turn licensing off.
        if ($value === null || $value === '') {
            return true;
        }

        return (bool) $value;
    }

    /**
     * Return the host of the current store's base URL, lowercased.
     *
     * @return string
     */
    public function getCurrentHost(): string
    {
        try {
            $url = $this->storeManager->getStore()->getBaseUrl();
            $host = parse_url($url, PHP_URL_HOST);
            return is_string($host) ? strtolower($host) : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Identify development hosts that bypass licensing.
     *
     * Mirrors the standard "unlimited dev/staging environments" policy used by
     * Amasty, Aheadworks, MageWorx, Mageplaza, Magefan — a paid license is
     * required only for production hosts.
     *
     * Patterns recognised:
     *   - Loopback and RFC 1918 private network IPs (10/8, 172.16/12, 192.168/16, 127/8)
     *   - RFC 6761 reserved TLDs (.test, .local, .localhost, .example, .invalid) and the .dev convention
     *   - Common staging subdomain prefixes (staging., stage., dev., qa., uat., test., preview., sandbox.)
     *   - Hyphen-staging patterns (foo-staging.com, foo-dev.com, etc.)
     *   - Adobe Commerce Cloud staging environments (*.magento.cloud, *.magentocloud.com)
     *   - Tunnelling services developers use (*.ngrok.io, *.ngrok-free.app, *.loca.lt, *.serveo.net)
     *
     * @param string $host
     * @return bool
     */
    /**
     * Public wrapper around isDevelopmentHost() so the admin status block
     * can show whether the current host is bypassing licence checks.
     *
     * @param string|null $host Defaults to the canonical current store host.
     * @return bool
     */
    public function isDevHost(?string $host = null): bool
    {
        $check = $host !== null ? $this->canonicalize($host) : $this->canonicalize($this->getCurrentHost());
        return $this->isDevelopmentHost($check);
    }

    private function isDevelopmentHost(string $host): bool
    {
        // Loopback
        if ($host === 'localhost' || str_starts_with($host, '127.')) {
            return true;
        }

        // RFC 1918 private IPv4 ranges
        if (str_starts_with($host, '10.') || str_starts_with($host, '192.168.')) {
            return true;
        }
        if (preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $host)) {
            return true;
        }

        // Reserved TLDs (RFC 6761) + common dev TLDs
        $devSuffixes = ['.test', '.local', '.localhost', '.dev', '.example', '.invalid'];
        foreach ($devSuffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        // Common staging subdomain prefixes
        $devPrefixes = ['staging.', 'stage.', 'dev.', 'qa.', 'uat.', 'test.', 'preview.', 'sandbox.'];
        foreach ($devPrefixes as $prefix) {
            if (str_starts_with($host, $prefix)) {
                return true;
            }
        }

        // Hyphen-staging patterns: my-shop-staging.com, my-shop-dev.com, etc.
        if (preg_match('/-(staging|stage|dev|qa|uat|test|preview|sandbox)\./', $host)) {
            return true;
        }

        // Adobe Commerce Cloud staging environments
        $cloudSuffixes = ['.magento.cloud', '.magentocloud.com', '.cloud.magento'];
        foreach ($cloudSuffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        // Developer tunnelling services
        $tunnelSuffixes = ['.ngrok.io', '.ngrok-free.app', '.loca.lt', '.serveo.net'];
        foreach ($tunnelSuffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
