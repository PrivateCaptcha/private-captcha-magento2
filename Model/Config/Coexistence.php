<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Model\ScopeInterface;
use PrivateCaptcha\PrivateCaptcha\Model\Config as PrivateCaptchaConfig;

class Coexistence
{
    private const PRIVATE_CAPTCHA_FORMS = [
        PrivateCaptchaConfig::FORM_CUSTOMER_LOGIN => [
            'label' => 'Customer Login',
            'captcha' => ['form' => 'user_login', 'module' => 'Magento_Customer'],
            'recaptcha' => [
                'form' => 'customer_login',
                'integration_module' => 'Magento_ReCaptchaCustomer',
                'form_module' => 'Magento_Customer',
            ],
        ],
        PrivateCaptchaConfig::FORM_CUSTOMER_REGISTRATION => [
            'label' => 'Create Account / Registration',
            'captcha' => ['form' => 'user_create', 'module' => 'Magento_Customer'],
            'recaptcha' => [
                'form' => 'customer_create',
                'integration_module' => 'Magento_ReCaptchaCustomer',
                'form_module' => 'Magento_Customer',
            ],
        ],
        PrivateCaptchaConfig::FORM_FORGOT_PASSWORD => [
            'label' => 'Forgot Password',
            'captcha' => ['form' => 'user_forgotpassword', 'module' => 'Magento_Customer'],
            'recaptcha' => [
                'form' => 'customer_forgot_password',
                'integration_module' => 'Magento_ReCaptchaCustomer',
                'form_module' => 'Magento_Customer',
            ],
        ],
        PrivateCaptchaConfig::FORM_CONTACT => [
            'label' => 'Contact Form',
            'captcha' => ['form' => 'contact_us', 'module' => 'Magento_Contact'],
            'recaptcha' => [
                'form' => 'contact',
                'integration_module' => 'Magento_ReCaptchaContact',
                'form_module' => 'Magento_Contact',
            ],
        ],
        PrivateCaptchaConfig::FORM_PRODUCT_REVIEW => [
            'label' => 'Product Review Submission',
            'recaptcha' => [
                'form' => 'product_review',
                'integration_module' => 'Magento_ReCaptchaReview',
                'form_module' => 'Magento_Review',
            ],
        ],
        PrivateCaptchaConfig::FORM_EMAIL_TO_FRIEND => [
            'label' => 'Email to a Friend',
            'captcha' => ['form' => 'product_sendtofriend_form', 'module' => 'Magento_SendFriend'],
            'recaptcha' => [
                'form' => 'sendfriend',
                'integration_module' => 'Magento_ReCaptchaSendFriend',
                'form_module' => 'Magento_SendFriend',
            ],
        ],
        PrivateCaptchaConfig::FORM_WISHLIST_SHARE => [
            'label' => 'Share Wishlist',
            'captcha' => ['form' => 'share_wishlist_form', 'module' => 'Magento_Wishlist'],
            'recaptcha' => [
                'form' => 'wishlist',
                'integration_module' => 'Magento_ReCaptchaWishlist',
                'form_module' => 'Magento_Wishlist',
            ],
        ],
    ];

    private const RECAPTCHA_ENGINES = [
        'invisible' => [
            'label' => 'Google reCAPTCHA v2 Invisible',
            'config' => 'type_invisible',
            'module' => 'Magento_ReCaptchaVersion2Invisible',
        ],
        'recaptcha' => [
            'label' => 'Google reCAPTCHA v2 Checkbox',
            'config' => 'type_recaptcha',
            'module' => 'Magento_ReCaptchaVersion2Checkbox',
        ],
        'recaptcha_v3' => [
            'label' => 'Google reCAPTCHA v3 Invisible',
            'config' => 'type_recaptcha_v3',
            'module' => 'Magento_ReCaptchaVersion3Invisible',
        ],
    ];

    private const CAPTCHA_ACL_RESOURCE = 'Magento_Customer::config_customer';
    private const RECAPTCHA_ACL_RESOURCE = 'Magento_ReCaptchaUi::config';
    private const RECAPTCHA_UI_MODULE = 'Magento_ReCaptchaUi';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ModuleManager $moduleManager,
        private readonly AuthorizationInterface $authorization,
        private readonly PrivateCaptchaConfig $privateCaptchaConfig
    ) {
    }

    /**
     * @return list<array{form: string, engine: string}>
     */
    public function getOverlaps(string $websiteCode): array
    {
        if ($websiteCode === '') {
            return [];
        }

        $canViewCaptcha = $this->authorization->isAllowed(self::CAPTCHA_ACL_RESOURCE)
            && $this->moduleManager->isEnabled('Magento_Captcha');
        $canViewRecaptcha = $this->authorization->isAllowed(self::RECAPTCHA_ACL_RESOURCE);
        $overlaps = [];

        foreach (self::PRIVATE_CAPTCHA_FORMS as $privateCaptchaForm => $mapping) {
            if (!$this->privateCaptchaConfig->isFormEnabledForWebsite($privateCaptchaForm, $websiteCode)) {
                continue;
            }

            if ($canViewCaptcha && isset($mapping['captcha']) && $this->isNativeCaptchaEnabled($mapping['captcha'], $websiteCode)) {
                $overlaps[] = ['form' => $mapping['label'], 'engine' => 'Magento CAPTCHA'];
            }

            if ($canViewRecaptcha) {
                $engine = $this->getRecaptchaEngine($mapping['recaptcha'], $websiteCode);
                if ($engine !== null) {
                    $overlaps[] = ['form' => $mapping['label'], 'engine' => $engine];
                }
            }
        }

        return $overlaps;
    }

    /**
     * @param array{form: string, module: string} $mapping
     */
    private function isNativeCaptchaEnabled(array $mapping, string $websiteCode): bool
    {
        if (!$this->moduleManager->isEnabled($mapping['module'])
            || !$this->isSetFlag('customer/captcha/enable', $websiteCode)) {
            return false;
        }

        $forms = preg_split('/\s*,\s*/', $this->getValue('customer/captcha/forms', $websiteCode), -1, PREG_SPLIT_NO_EMPTY);

        return in_array($mapping['form'], $forms ?: [], true);
    }

    /**
     * @param array{form: string, integration_module: string, form_module: string} $mapping
     */
    private function getRecaptchaEngine(array $mapping, string $websiteCode): ?string
    {
        if (!$this->areModulesEnabled([
            self::RECAPTCHA_UI_MODULE,
            $mapping['integration_module'],
            $mapping['form_module'],
        ])) {
            return null;
        }

        $type = $this->getValue('recaptcha_frontend/type_for/' . $mapping['form'], $websiteCode);
        if (!isset(self::RECAPTCHA_ENGINES[$type])) {
            return null;
        }

        $engine = self::RECAPTCHA_ENGINES[$type];
        if (!$this->moduleManager->isEnabled($engine['module'])
            || !$this->hasRecaptchaCredentials($engine['config'], $websiteCode)) {
            return null;
        }

        return $engine['label'];
    }

    private function hasRecaptchaCredentials(string $configGroup, string $websiteCode): bool
    {
        return !empty($this->getValue('recaptcha_frontend/' . $configGroup . '/public_key', $websiteCode))
            && !empty($this->getValue('recaptcha_frontend/' . $configGroup . '/private_key', $websiteCode));
    }

    /** @param list<string> $modules */
    private function areModulesEnabled(array $modules): bool
    {
        foreach ($modules as $module) {
            if (!$this->moduleManager->isEnabled($module)) {
                return false;
            }
        }

        return true;
    }

    private function isSetFlag(string $path, string $websiteCode): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_WEBSITE, $websiteCode);
    }

    private function getValue(string $path, string $websiteCode): string
    {
        return (string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_WEBSITE, $websiteCode);
    }
}
