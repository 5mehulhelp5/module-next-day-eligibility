<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Hybrid HMAC + portal license validator for ETechFlow_NextDayEligibility.
 * Follows PORTAL_LICENSING_GUIDE.md §3-step-1.
 *
 *   isValid() priority:
 *     1. revoked = 1                       → false (portal revoke wins even in dev)
 *     2. production_environment = No       → true (dev bypass)
 *     3. SP-XXXX key, portal answers       → portal's answer is final (true/false)
 *     4. SP-XXXX key, portal unreachable   → 48h local grace fallback
 *     5. Legacy HMAC per-module key        → hash_equals(computeKey(host), key)
 *     6. Bundle key                        → hash_equals(computeBundleKey(host), key)
 *     7. otherwise                         → false
 *
 *   The portal-first ordering (changed 2026-06-02) is what makes IP-revocation
 *   work: when the admin removes a server's IP from the portal subscription,
 *   /license/validate returns HTTP 403 with valid:false, which counts as an
 *   "explicit reject" and locks the module immediately. The 48h grace only
 *   kicks in when the curl call literally cannot reach the portal (timeout,
 *   network error, missing portal URL).
 */
class LicenseValidator
{
    public const XML_PATH_LICENSE_KEY            = 'etechflow_nextdayeligibility/license/license_key';
    public const XML_PATH_PRODUCTION_ENVIRONMENT = 'etechflow_nextdayeligibility/license/production_environment';
    public const XML_PATH_PORTAL_URL             = 'etechflow_nextdayeligibility/license/portal_url';
    public const XML_PATH_PORTAL_API_URL         = 'etechflow_nextdayeligibility/license/portal_api_url';

    public const XML_PATH_BUNDLE_LICENSE_KEY = 'etechflow_bundle/license/license_key';

    private const MODULE_ID = 'next-day-eligibility';
    private const BUNDLE_ID = 'etechflow-bundle';

    // PRESERVED from original v1.2.3 LicenseValidator — keeps bundle/HMAC compatibility (LICENSING_PROTOCOL.md).
    private const SECRET_FRAGMENTS = [
        'eTF-NDE-2026',
        'a8c2-fE4d',
        '7B9k-Lm3p',
        'Q5xW-yH8r',
    ];

    private const BUNDLE_SECRET_FRAGMENTS = [
        'eTF-BUNDLE-2026',
        'k2D9-mP4x',
        'L8nR-vH2j',
        'X7tY-zW5q',
    ];

    private const CACHE_TTL_VALID  = 60; // portal said valid → cache 60s so admin IP-removal propagates within 1 minute (per PORTAL_LICENSING_GUIDE.md PORTAL_CACHE_TTL)
    private const CACHE_TTL_REJECT = 60; // portal said NO → recheck within 1 minute so re-authorisation propagates fast
    private const CACHE_TAG = 'etechflow_nde_license';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly CacheInterface $cache,
        private readonly Curl $curl
    ) {
    }

    public function isValid(): bool
    {
        $host = $this->getCurrentHost();
        if ($host === '') {
            return false;
        }

        if ($this->isExplicitlyRevoked()) {
            return false;
        }

        if (!$this->isProductionEnvironment()) {
            return true;
        }

        $configuredKey = $this->getConfiguredKey();

        if (str_starts_with($configuredKey, 'SP-')) {
            // Portal answer wins: explicit accept/reject is honoured immediately.
            // The 48h local grace is only a fallback for the portal being unreachable
            // (so a network blip doesn't black-hole live storefronts) — it MUST NOT
            // mask an explicit reject, otherwise admin IP removal would not lock the module.
            $portalAnswer = $this->validateViaPortal($host, $configuredKey);
            if ($portalAnswer === true) {
                return true;
            }
            if ($portalAnswer === false) {
                return false;
            }
            return $this->isLocallyIssuedKey($configuredKey, $host);
        }

        if ($configuredKey !== '' && hash_equals($this->computeKey($host), $configuredKey)) {
            return true;
        }

        $bundleKey = $this->getConfiguredBundleKey();
        if ($bundleKey !== '' && hash_equals($this->computeBundleKey($host), $bundleKey)) {
            return true;
        }

        return false;
    }

    /**
     * Ask the portal whether this host+key is currently authorised.
     *
     * @return bool|null  true  = portal said valid
     *                    false = portal explicitly rejected (200 valid:false, 401, 403)
     *                    null  = portal unreachable / unconfigured (caller may fall back to grace)
     */
    private function validateViaPortal(string $host, string $licenseKey): ?bool
    {
        $cacheKey = 'etf_nde_lic_' . md5($host . ':' . $licenseKey);
        $cached   = $this->cache->load($cacheKey);
        if ($cached === '1') {
            return true;
        }
        if ($cached === '0') {
            return false;
        }

        $apiBase = $this->getPortalApiBase();
        if ($apiBase === '') {
            return null; // no portal configured → grace fallback
        }

        $url = rtrim($apiBase, '/') . '/license/validate'
            . '?domain='      . urlencode($this->canonicalize($host))
            . '&license_key=' . urlencode($licenseKey)
            . '&platform=magento'
            . '&module='      . urlencode(self::MODULE_ID);

        $status = 0;
        $body   = '';
        try {
            $this->curl->setTimeout(5);
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('User-Agent', 'ETechFlow-NDE/1.0');
            $this->curl->get($url);
            $status = (int) $this->curl->getStatus();
            $body   = (string) $this->curl->getBody();
        } catch (\Exception) {
            return null; // network error → grace fallback
        }

        if ($status === 200 && $body !== '') {
            $data  = json_decode($body, true);
            $valid = !empty($data['valid']);
            $this->cache->save(
                $valid ? '1' : '0',
                $cacheKey,
                [self::CACHE_TAG],
                $valid ? self::CACHE_TTL_VALID : self::CACHE_TTL_REJECT
            );
            return $valid;
        }

        if ($status === 401 || $status === 403) {
            // Portal answered and said NO (e.g. IP revoked, subscription suspended, key revoked).
            $this->cache->save('0', $cacheKey, [self::CACHE_TAG], self::CACHE_TTL_REJECT);
            return false;
        }

        // 0 / 5xx / other → treat as unreachable, no caching.
        return null;
    }

    private function getPortalApiBase(): string
    {
        $api = trim((string) $this->scopeConfig->getValue(self::XML_PATH_PORTAL_API_URL));
        if ($api !== '') {
            return $api;
        }
        $browser = trim((string) $this->scopeConfig->getValue(self::XML_PATH_PORTAL_URL));
        if ($browser !== '' && !str_contains($browser, '127.0.0.1') && !str_contains($browser, 'localhost')) {
            return $browser;
        }
        return '';
    }

    public function computeKey(string $host): string
    {
        $payload = $this->canonicalize($host) . ':' . self::MODULE_ID;
        $secret  = implode('', self::SECRET_FRAGMENTS);
        $raw     = hash_hmac('sha256', $payload, $secret, true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function computeBundleKey(string $host): string
    {
        $payload = $this->canonicalize($host) . ':' . self::BUNDLE_ID;
        $secret  = implode('', self::BUNDLE_SECRET_FRAGMENTS);
        $raw     = hash_hmac('sha256', $payload, $secret, true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function canonicalize(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }

    public function getConfiguredKey(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_LICENSE_KEY, ScopeInterface::SCOPE_STORE);
        return trim((string) $value);
    }

    public function getConfiguredBundleKey(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_BUNDLE_LICENSE_KEY, ScopeInterface::SCOPE_STORE);
        return trim((string) $value);
    }

    public function isProductionEnvironment(): bool
    {
        // Sandbox toggle removed: production licensing is always enforced.
        return true;
        $value = $this->scopeConfig->getValue(self::XML_PATH_PRODUCTION_ENVIRONMENT, ScopeInterface::SCOPE_STORE);
        if ($value === null || $value === '') {
            return true;
        }
        return (bool) $value;
    }

    public function getCurrentHost(): string
    {
        try {
            $url  = $this->storeManager->getStore()->getBaseUrl();
            $host = parse_url($url, PHP_URL_HOST);
            return is_string($host) ? strtolower($host) : '';
        } catch (\Exception) {
            return '';
        }
    }

    public function isDevHost(?string $host = null): bool
    {
        $check = $host !== null ? strtolower(trim($host)) : $this->canonicalize($this->getCurrentHost());
        return $this->isDevelopmentHost($check);
    }

    /**
     * Per guide gotcha L: REMOVED the hyphen-regex that false-matched
     * magento-dev.etechflow.com (and similar `*-dev.*` prod hosts).
     * ADDED .ngrok-free.dev to tunnel suffixes.
     */
    private function isDevelopmentHost(string $host): bool
    {
        if ($host === 'localhost' || str_starts_with($host, '127.')) return true;
        if (str_starts_with($host, '10.') || str_starts_with($host, '192.168.')) return true;
        if (preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $host)) return true;
        foreach (['.test', '.local', '.localhost', '.dev', '.example', '.invalid'] as $s) {
            if (str_ends_with($host, $s)) return true;
        }
        foreach (['staging.', 'stage.', 'dev.', 'qa.', 'uat.', 'test.', 'preview.', 'sandbox.'] as $p) {
            if (str_starts_with($host, $p)) return true;
        }
        foreach (['.magento.cloud', '.magentocloud.com', '.ngrok.io', '.ngrok-free.app', '.ngrok-free.dev', '.loca.lt'] as $s) {
            if (str_ends_with($host, $s)) return true;
        }
        return false;
    }

    private function isLocallyIssuedKey(string $key, string $host): bool
    {
        $issuedKey = trim((string) $this->scopeConfig->getValue('etechflow_nextdayeligibility/license/issued_key'));
        if ($issuedKey === '' || !hash_equals($issuedKey, $key)) {
            return false;
        }
        $issuedDomain = trim((string) $this->scopeConfig->getValue('etechflow_nextdayeligibility/license/issued_domain'));
        if ($issuedDomain === '' || $this->canonicalize($issuedDomain) !== $this->canonicalize($host)) {
            return false;
        }
        $sessionId = trim((string) $this->scopeConfig->getValue('etechflow_nextdayeligibility/license/stripe_session_id'));
        if ($sessionId === '') {
            return false;
        }
        $issuedAt = (int) $this->scopeConfig->getValue('etechflow_nextdayeligibility/license/issued_at');
        if ($issuedAt === 0) {
            return false;
        }
        return (time() - $issuedAt) < 172800; // 48-hour grace
    }

    private function isExplicitlyRevoked(): bool
    {
        return (string) $this->scopeConfig->getValue(
            'etechflow_nextdayeligibility/license/revoked',
            ScopeInterface::SCOPE_STORE
        ) === '1';
    }
}
