<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Controller\Adminhtml\License;

use ETechFlow\NextDayEligibility\Model\LicenseValidator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\Type\Config as ConfigCacheType;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\View\Result\PageFactory;

/**
 * Landing page after Stripe payment. Calls the eTechFlow portal to activate the
 * subscription (the portal verifies the Stripe session with ITS OWN key), gets
 * the license key, saves it to config, and shows success.
 */
class Activated extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_NextDayEligibility::config';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly CurlFactory $curlFactory,
        private readonly WriterInterface $configWriter,
        private readonly CacheInterface $cache,
        private readonly LicenseValidator $licenseValidator
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $sessionId = trim((string) $this->getRequest()->getParam('session_id', ''));
        $subId     = trim((string) $this->getRequest()->getParam('sub_id', ''));
        $plan      = trim((string) $this->getRequest()->getParam('plan', ''));
        $domain    = trim((string) $this->getRequest()->getParam('domain', '')) ?: $this->licenseValidator->getCurrentHost();
        $name      = trim((string) $this->getRequest()->getParam('name', ''));
        $email     = trim((string) $this->getRequest()->getParam('email', ''));

        if (!$sessionId) {
            $this->messageManager->addErrorMessage(__('Invalid payment callback.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('etechflow_nextdayeligibility/license/gate');
        }

        $portal  = rtrim(str_replace('/license/validate', '', $this->licenseValidator->getPortalUrl()), '/');
        $payload = json_encode(array_filter([
            'session_id' => $sessionId,
            'sub_id'     => $subId ?: null,
            'domain'     => $domain,
            'name'       => $name,
            'email'      => $email,
            'plan'       => $plan,
        ]));

        $licenseKey = '';
        $planName   = '';
        $error      = '';

        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(25);
            $curl->addHeader('Content-Type', 'application/json');
            $curl->addHeader('Accept', 'application/json');
            $curl->addHeader('X-ETF-License-Token', 'lcsk_8f3b9d2a7c14e605b9af2e7c1d8043f6');
            $curl->post('https://module.etechflow.com/api/license/result', $payload);
            $status = (int) $curl->getStatus();
            $body   = (string) $curl->getBody();
            $data   = json_decode($body, true);

            if ($status === 200 && !empty($data['license_key'])) {
                $licenseKey = (string) $data['license_key'];
                $planName   = (string) ($data['plan'] ?? $plan);
            } else {
                $error = is_array($data) && !empty($data['error']) ? $data['error'] : ('Portal returned status ' . $status . ': ' . $body);
            }
        } catch (\Throwable $e) {
            $error = 'Could not reach portal: ' . $e->getMessage();
        }

        if ($licenseKey) {
            $this->configWriter->save(LicenseValidator::XML_PATH_LICENSE_KEY, $licenseKey);
            $this->cache->clean([ConfigCacheType::CACHE_TAG]);
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->prepend(__('Subscription Activated'));

        $block = $page->getLayout()->getBlock('etechflow.bed.license.activated');
        if ($block) {
            $block->setData('license_key', $licenseKey)
                  ->setData('plan', $planName)
                  ->setData('error', $error)
                  ->setData('settings_url', $this->getUrl('adminhtml/system_config/edit/section/etechflow_nextdayeligibility'))
                  ->setData('management_url', $this->getUrl('etechflow_nextdayeligibility/license/gate'));
        }

        return $page;
    }
}
