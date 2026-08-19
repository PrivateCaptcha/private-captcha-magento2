<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Plugin\Adminhtml;

use Magento\Config\Controller\Adminhtml\System\Config\Index;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;

/** Supplies a concrete section before Magento performs section-level ACL checks. */
class ResolveConfigSection
{
    private const CONFIG_RESOURCE = 'Magento_Config::config';
    private const PRIVATE_CAPTCHA_RESOURCE = 'PrivateCaptcha_PrivateCaptcha::config';

    /**
     * @param AuthorizationInterface $authorization
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization
    ) {
    }

    /**
     * Defaults a sectionless request only for administrators restricted to this module.
     *
     * @param Index $subject
     * @param RequestInterface $request
     * @return void
     */
    public function beforeDispatch(Index $subject, RequestInterface $request): void
    {
        if ($request->getParam('section')
            || $this->authorization->isAllowed(self::CONFIG_RESOURCE)
            || !$this->authorization->isAllowed(self::PRIVATE_CAPTCHA_RESOURCE)
        ) {
            return;
        }

        $request->setParams(['section' => 'private_captcha']);
    }
}
