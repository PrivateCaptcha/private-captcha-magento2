<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PrivateCaptcha\Client;

class Config
{
    public const FORM_CUSTOMER_LOGIN = 'customer_login';
    public const FORM_CUSTOMER_REGISTRATION = 'customer_registration';
    public const FORM_FORGOT_PASSWORD = 'forgot_password';
    public const FORM_CONTACT = 'contact_form';
    public const FORM_PRODUCT_REVIEW = 'product_review';
    public const FORM_EMAIL_TO_FRIEND = 'email_to_friend';
    public const FORM_WISHLIST_SHARE = 'wishlist_share';
    public const FORM_ORDERS_RETURNS = 'orders_returns';

    public const SOLUTION_FIELD = 'private-captcha-solution';
    public const DEFAULT_SCRIPT_URL = 'https://cdn.privatecaptcha.com/widget/js/privatecaptcha.js';
    public const DEFAULT_CUSTOM_STYLES = 'font-size: inherit;';

    public const PATH_SITE_KEY = 'private_captcha/credentials/site_key';
    public const PATH_API_KEY = 'private_captcha/credentials/api_key';
    public const PATH_EU_ISOLATION = 'private_captcha/advanced/eu_isolation';
    public const PATH_CUSTOM_DOMAIN = 'private_captcha/advanced/custom_domain';
    public const PATH_DEBUG_MODE = 'private_captcha/advanced/debug_mode';
    public const PATH_CUSTOM_STYLES = 'private_captcha/advanced/custom_styles';
    public const PATH_THEME = 'private_captcha/advanced/theme';
    public const PATH_LANGUAGE = 'private_captcha/advanced/language';
    public const PATH_START_MODE = 'private_captcha/advanced/start_mode';

    public const FORM_PATHS = [
        self::FORM_CUSTOMER_LOGIN => 'private_captcha/protected_forms/customer_login',
        self::FORM_CUSTOMER_REGISTRATION => 'private_captcha/protected_forms/customer_registration',
        self::FORM_FORGOT_PASSWORD => 'private_captcha/protected_forms/forgot_password',
        self::FORM_CONTACT => 'private_captcha/protected_forms/contact_form',
        self::FORM_PRODUCT_REVIEW => 'private_captcha/protected_forms/product_review',
        self::FORM_EMAIL_TO_FRIEND => 'private_captcha/protected_forms/email_to_friend',
        self::FORM_WISHLIST_SHARE => 'private_captcha/protected_forms/wishlist_share',
        self::FORM_ORDERS_RETURNS => 'private_captcha/protected_forms/orders_returns',
    ];

    public const THEMES = ['light', 'dark'];
    public const LANGUAGES = ['auto', 'en', 'de', 'es', 'fr', 'it', 'nl', 'sv', 'no', 'pl', 'fi', 'et', 'uk', 'tr'];
    public const START_MODES = ['auto', 'click'];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomDomain $customDomain
    ) {
    }

    public function getWebsiteCode(?int $storeId = null): string
    {
        $store = $this->storeManager->getStore($storeId);

        return (string) $this->storeManager->getWebsite((int) $store->getWebsiteId())->getCode();
    }

    public function getSiteKey(?int $storeId = null): string
    {
        return $this->getValue(self::PATH_SITE_KEY, $storeId);
    }

    public function getApiKey(?int $storeId = null): string
    {
        return $this->getValue(self::PATH_API_KEY, $storeId);
    }

    public function isFormEnabled(string $form, ?int $storeId = null): bool
    {
        return $this->isFormEnabledForWebsite($form, $this->getWebsiteCode($storeId));
    }

    /**
     * Returns whether a form is active in the effective Website configuration.
     *
     * @param string $form Form identifier configured by the module.
     * @param string $websiteCode Website code used to resolve effective values.
     */
    public function isFormEnabledForWebsite(string $form, string $websiteCode): bool
    {
        if ($websiteCode === '' || !isset(self::FORM_PATHS[$form])) {
            return false;
        }

        if (!$this->scopeConfig->isSetFlag(
            self::FORM_PATHS[$form],
            ScopeInterface::SCOPE_WEBSITE,
            $websiteCode
        )) {
            return false;
        }

        return trim($this->getWebsiteValue(self::PATH_SITE_KEY, $websiteCode)) !== ''
            && trim($this->getWebsiteValue(self::PATH_API_KEY, $websiteCode)) !== '';
    }

    public function isEuIsolation(?int $storeId = null): bool
    {
        return $this->getCustomDomain($storeId) === '' && $this->isSetFlag(self::PATH_EU_ISOLATION, $storeId);
    }

    public function getCustomDomain(?int $storeId = null): string
    {
        return $this->customDomain->normalize($this->getValue(self::PATH_CUSTOM_DOMAIN, $storeId));
    }

    public function getScriptUrl(?int $storeId = null): string
    {
        $customDomain = $this->getCustomDomain($storeId);

        return $customDomain === ''
            ? self::DEFAULT_SCRIPT_URL
            : $this->customDomain->getScriptUrl($customDomain);
    }

    public function getPuzzleEndpoint(?int $storeId = null): ?string
    {
        $customDomain = $this->getCustomDomain($storeId);

        return $customDomain === '' ? null : $this->customDomain->getPuzzleEndpoint($customDomain);
    }

    public function getVerificationDomain(?int $storeId = null): ?string
    {
        $customDomain = $this->getCustomDomain($storeId);
        if ($customDomain !== '') {
            return $customDomain;
        }

        return $this->isSetFlag(self::PATH_EU_ISOLATION, $storeId) ? Client::EU_DOMAIN : null;
    }

    public function isDebugMode(?int $storeId = null): bool
    {
        return $this->isSetFlag(self::PATH_DEBUG_MODE, $storeId);
    }

    public function getCustomStyles(?int $storeId = null): string
    {
        $styles = $this->getStoreValue(self::PATH_CUSTOM_STYLES, $storeId);

        return trim($styles) === '' ? self::DEFAULT_CUSTOM_STYLES : $styles;
    }

    public function getTheme(?int $storeId = null): string
    {
        $theme = $this->getStoreValue(self::PATH_THEME, $storeId);

        return in_array($theme, self::THEMES, true) ? $theme : 'light';
    }

    public function getLanguage(?int $storeId = null): string
    {
        $language = $this->getStoreValue(self::PATH_LANGUAGE, $storeId);

        return in_array($language, self::LANGUAGES, true) ? $language : 'auto';
    }

    public function getStartMode(?int $storeId = null): string
    {
        $startMode = $this->getValue(self::PATH_START_MODE, $storeId);

        return in_array($startMode, self::START_MODES, true) ? $startMode : 'auto';
    }

    private function getValue(string $path, ?int $storeId): string
    {
        return $this->getWebsiteValue($path, $this->getWebsiteCode($storeId));
    }

    private function getWebsiteValue(string $path, string $websiteCode): string
    {
        return (string) $this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteCode
        );
    }

    /**
     * Resolves a presentation value through Default, Website, and Store View inheritance.
     *
     * @param string $path Store-scoped presentation configuration path.
     * @param int|null $storeId Store View ID, or null for the current Store View.
     */
    private function getStoreValue(string $path, ?int $storeId): string
    {
        return (string) $this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE,
            (string) $this->storeManager->getStore($storeId)->getCode()
        );
    }

    private function isSetFlag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            $path,
            ScopeInterface::SCOPE_WEBSITE,
            $this->getWebsiteCode($storeId)
        );
    }
}
