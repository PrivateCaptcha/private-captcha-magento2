<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Adminhtml;

use InvalidArgumentException;
use Magento\Config\Model\Config as MagentoConfig;
use Magento\Config\Model\Config\Factory as ConfigFactory;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PrivateCaptcha\Client;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\CustomDomain;

class ConfigSaveValidator
{
    /** @var array<string, array{0: string, 1: string}> */
    private const FIELD_PATHS = [
        Config::PATH_SITE_KEY => ['credentials', 'site_key'],
        Config::PATH_API_KEY => ['credentials', 'api_key'],
        Config::PATH_CUSTOM_DOMAIN => ['advanced', 'custom_domain'],
        Config::PATH_EU_ISOLATION => ['advanced', 'eu_isolation'],
        Config::PATH_DEBUG_MODE => ['advanced', 'debug_mode'],
        Config::PATH_CUSTOM_STYLES => ['advanced', 'custom_styles'],
        Config::PATH_LANGUAGE => ['advanced', 'language'],
        Config::PATH_START_MODE => ['advanced', 'start_mode'],
        Config::FORM_PATHS[Config::FORM_CUSTOMER_LOGIN] => ['protected_forms', 'customer_login'],
        Config::FORM_PATHS[Config::FORM_CUSTOMER_REGISTRATION] => ['protected_forms', 'customer_registration'],
        Config::FORM_PATHS[Config::FORM_FORGOT_PASSWORD] => ['protected_forms', 'forgot_password'],
        Config::FORM_PATHS[Config::FORM_CONTACT] => ['protected_forms', 'contact_form'],
        Config::FORM_PATHS[Config::FORM_PRODUCT_REVIEW] => ['protected_forms', 'product_review'],
        Config::FORM_PATHS[Config::FORM_EMAIL_TO_FRIEND] => ['protected_forms', 'email_to_friend'],
        Config::FORM_PATHS[Config::FORM_WISHLIST_SHARE] => ['protected_forms', 'wishlist_share'],
        Config::FORM_PATHS[Config::FORM_ORDERS_RETURNS] => ['protected_forms', 'orders_returns'],
    ];

    /** @var array<string, string> */
    private const DEFAULT_VALUES = [
        Config::PATH_SITE_KEY => '',
        Config::PATH_API_KEY => '',
        Config::PATH_CUSTOM_DOMAIN => '',
        Config::PATH_EU_ISOLATION => '0',
        Config::PATH_DEBUG_MODE => '0',
        Config::PATH_CUSTOM_STYLES => '',
        Config::PATH_LANGUAGE => 'auto',
        Config::PATH_START_MODE => 'auto',
        Config::FORM_PATHS[Config::FORM_CUSTOMER_LOGIN] => '0',
        Config::FORM_PATHS[Config::FORM_CUSTOMER_REGISTRATION] => '0',
        Config::FORM_PATHS[Config::FORM_FORGOT_PASSWORD] => '0',
        Config::FORM_PATHS[Config::FORM_CONTACT] => '0',
        Config::FORM_PATHS[Config::FORM_PRODUCT_REVIEW] => '0',
        Config::FORM_PATHS[Config::FORM_EMAIL_TO_FRIEND] => '0',
        Config::FORM_PATHS[Config::FORM_WISHLIST_SHARE] => '0',
        Config::FORM_PATHS[Config::FORM_ORDERS_RETURNS] => '0',
    ];

    public function __construct(
        private readonly ReinitableConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly ConfigFactory $configFactory,
        private readonly SettingChecker $settingChecker,
        private readonly CustomDomain $customDomain,
        private readonly CurrentSettingsTester $settingsTester
    ) {
    }

    public function reinit(): void
    {
        $this->scopeConfig->reinit();
    }

    public function validate(MagentoConfig $config): ConfigSaveValidationResult
    {
        if ($config->getSection() !== 'private_captcha') {
            return new ConfigSaveValidationResult(false);
        }

        $scope = $config->getScope();
        $isWebsiteScope = in_array($scope, [ScopeInterface::SCOPE_WEBSITE, ScopeInterface::SCOPE_WEBSITES], true);
        $isStoreScope = (bool) $config->getStore()
            || in_array($scope, [ScopeInterface::SCOPE_STORE, ScopeInterface::SCOPE_STORES], true);
        if (!$isWebsiteScope
            && !$isStoreScope
            && !in_array($scope, [null, '', ScopeConfigInterface::SCOPE_TYPE_DEFAULT], true)
        ) {
            throw new LocalizedException(
                __('Private Captcha supports only Default Config, Website, and Store View scopes.')
            );
        }

        $groups = $config->getData('groups');
        if (!is_array($groups)) {
            return new ConfigSaveValidationResult(false);
        }

        if ($isStoreScope) {
            $storeId = $config->getData('scope_id')
                ?? $config->getData('scope_code')
                ?? $config->getStore();
            $this->validateStoreValues($groups, $this->storeManager->getStore($storeId));

            return new ConfigSaveValidationResult(false);
        }

        if ($isWebsiteScope) {
            $websiteId = $config->getData('scope_id')
                ?? $config->getData('scope_code')
                ?? $config->getData('website');
            $website = $this->storeManager->getWebsite($websiteId);
            $testResults = [];
            $effectiveResult = $this->validateEffectiveValues($groups, $website, false, $config, $testResults);
            if ($effectiveResult['disable_forms']) {
                $this->disableForms($config);
            }

            return new ConfigSaveValidationResult($effectiveResult['test_failed']);
        }

        $websiteId = $scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT ? null : $config->getWebsite();
        if ($websiteId) {
            $website = $this->storeManager->getWebsite($websiteId);
            $testResults = [];
            $effectiveResult = $this->validateEffectiveValues($groups, $website, false, $config, $testResults);
            if ($effectiveResult['disable_forms']) {
                $this->disableForms($config);
            }

            return new ConfigSaveValidationResult($effectiveResult['test_failed']);
        }

        $testResults = [];
        $defaultResult = $this->validateEffectiveValues($groups, null, true, $config, $testResults);
        $settingsTestFailed = $defaultResult['test_failed'];
        $websiteIdsToDisable = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteConfig = $this->configFactory->create(
                [
                    'data' => [
                        'section' => 'private_captcha',
                        'website' => (string) $website->getId(),
                    ],
                ]
            );
            $websiteResult = $this->validateEffectiveValues(
                $groups,
                $website,
                true,
                $websiteConfig,
                $testResults
            );
            if ($websiteResult['disable_forms']) {
                $websiteIdsToDisable[] = (int) $website->getId();
            }
            $settingsTestFailed = $websiteResult['test_failed'] || $settingsTestFailed;
        }

        if ($defaultResult['disable_forms']) {
            $this->disableForms($config);
        }

        return new ConfigSaveValidationResult($settingsTestFailed, $websiteIdsToDisable);
    }

    /**
     * Validates the presentation values projected by a Store View save.
     *
     * @param array<string, mixed> $groups
     * @param StoreInterface $store Store View receiving the projected values.
     */
    private function validateStoreValues(array $groups, StoreInterface $store): void
    {
        foreach (self::FIELD_PATHS as $path => $_field) {
            if (in_array($path, [Config::PATH_CUSTOM_STYLES, Config::PATH_LANGUAGE], true)) {
                continue;
            }

            if ($this->getPostedFieldData($path, $groups) !== null) {
                throw new LocalizedException(
                    __('Private Captcha Store View scope supports only Custom Styles and Language.')
                );
            }
        }

        $language = $this->getProjectedStoreValue(Config::PATH_LANGUAGE, $groups, $store);
        if (!in_array($language, Config::LANGUAGES, true)) {
            throw new LocalizedException(__('Private Captcha Language is invalid.'));
        }

        $this->getProjectedStoreValue(Config::PATH_CUSTOM_STYLES, $groups, $store);
    }

    /**
     * Returns one effective presentation value after the projected Store View save.
     *
     * @param string $path Supported Store View configuration path.
     * @param array<string, mixed> $groups
     * @param StoreInterface $store Store View receiving the projected value.
     */
    private function getProjectedStoreValue(string $path, array $groups, StoreInterface $store): string
    {
        $storeCode = (string) $store->getCode();
        if ($this->settingChecker->isReadOnly($path, ScopeInterface::SCOPE_STORES, $storeCode)) {
            return $this->getCurrentValue($path, ScopeInterface::SCOPE_STORES, $storeCode);
        }

        $fieldData = $this->getPostedFieldData($path, $groups);
        if ($fieldData === null) {
            return $this->getCurrentValue($path, ScopeInterface::SCOPE_STORES, $storeCode);
        }

        if (!empty($fieldData['inherit'])) {
            $website = $this->storeManager->getWebsite((int) $store->getWebsiteId());

            return $this->getCurrentValue(
                $path,
                ScopeInterface::SCOPE_WEBSITES,
                (string) $website->getCode()
            );
        }

        return $this->toString($fieldData['value'] ?? null);
    }

    /**
     * @param array<string, mixed> $groups
     * @param array<string, bool> $testResults
     * @return array{test_failed: bool, disable_forms: bool}
     */
    private function validateEffectiveValues(
        array $groups,
        ?WebsiteInterface $website,
        bool $isDefaultSave,
        MagentoConfig $scopeConfig,
        array &$testResults
    ): array {
        $values = [];
        foreach (self::FIELD_PATHS as $path => $_field) {
            $values[$path] = $this->getProjectedValue($path, $groups, $website, $isDefaultSave, $scopeConfig);
        }

        try {
            $customDomain = $this->customDomain->normalize($values[Config::PATH_CUSTOM_DOMAIN]);
        } catch (InvalidArgumentException) {
            throw new LocalizedException(__('Private Captcha Custom Domain must be a valid hostname.'));
        }

        if (!in_array($values[Config::PATH_LANGUAGE], Config::LANGUAGES, true)) {
            throw new LocalizedException(__('Private Captcha Language is invalid.'));
        }

        if (!in_array($values[Config::PATH_START_MODE], Config::START_MODES, true)) {
            throw new LocalizedException(__('Private Captcha Start Mode is invalid.'));
        }

        foreach ([
            Config::PATH_EU_ISOLATION => __('EU Isolation'),
            Config::PATH_DEBUG_MODE => __('Debug Mode'),
        ] as $path => $label) {
            if (!in_array($values[$path], ['0', '1'], true)) {
                throw new LocalizedException(__('Private Captcha %1 must be Yes or No.', $label));
            }
        }

        $isEnabled = false;
        foreach (Config::FORM_PATHS as $path) {
            if (!in_array($values[$path], ['0', '1'], true)) {
                throw new LocalizedException(__('Private Captcha protected form values must be Yes or No.'));
            }
            $isEnabled = $isEnabled || $values[$path] === '1';
        }

        $hasCredentials = trim($values[Config::PATH_SITE_KEY]) !== ''
            && trim($values[Config::PATH_API_KEY]) !== '';
        $settingsTestFailed = $isEnabled && !$hasCredentials;
        if ($hasCredentials) {
            $domain = $customDomain !== ''
                ? $customDomain
                : ($values[Config::PATH_EU_ISOLATION] === '1' ? Client::EU_DOMAIN : null);
            $testKey = hash('sha256', $values[Config::PATH_API_KEY] . "\0" . ($domain ?? ''));
            if (!array_key_exists($testKey, $testResults)) {
                $testResults[$testKey] = $this->settingsTester->test($values[Config::PATH_API_KEY], $domain);
            }
            $settingsTestFailed = !$testResults[$testKey];
        }

        $disableForms = $settingsTestFailed && $isEnabled;
        if ($disableForms) {
            $this->assertEnabledFormsCanBeDisabled($values, $website);
        }

        return ['test_failed' => $settingsTestFailed, 'disable_forms' => $disableForms];
    }

    private function disableForms(MagentoConfig $config): void
    {
        $groups = $config->getData('groups');
        if (!is_array($groups)) {
            return;
        }

        foreach (Config::FORM_PATHS as $path) {
            [, $field] = self::FIELD_PATHS[$path];
            $fieldData = $groups['protected_forms']['fields'][$field] ?? [];
            $fieldData = is_array($fieldData) ? $fieldData : [];
            $fieldData['value'] = '0';
            unset($fieldData['inherit']);
            $groups['protected_forms']['fields'][$field] = $fieldData;
        }

        $config->setData('groups', $groups);
    }

    /** @param array<string, string> $values */
    private function assertEnabledFormsCanBeDisabled(array $values, ?WebsiteInterface $website): void
    {
        foreach (Config::FORM_PATHS as $path) {
            if ($values[$path] !== '1') {
                continue;
            }

            $scope = $website === null ? ScopeConfigInterface::SCOPE_TYPE_DEFAULT : ScopeInterface::SCOPE_WEBSITES;
            $scopeCode = $website === null ? null : (string) $website->getCode();
            if ($this->settingChecker->isReadOnly($path, $scope, $scopeCode)) {
                throw new LocalizedException(__(
                    'Private Captcha settings test failed, but an enabled form is locked by deployed configuration. '
                    . 'The configuration was not saved.'
                ));
            }
        }
    }

    /**
     * @param array<string, mixed> $groups
     */
    private function getProjectedValue(
        string $path,
        array $groups,
        ?WebsiteInterface $website,
        bool $isDefaultSave,
        MagentoConfig $scopeConfig
    ): string {
        $scope = $website === null ? ScopeConfigInterface::SCOPE_TYPE_DEFAULT : ScopeInterface::SCOPE_WEBSITES;
        $scopeCode = $website === null ? null : (string) $website->getCode();
        if ($this->settingChecker->isReadOnly($path, $scope, $scopeCode)) {
            return $this->getCurrentValue($path, $scope, $scopeCode);
        }

        if ($isDefaultSave) {
            $defaultValue = $this->getProjectedDefaultValue($path, $groups);
            if ($website === null) {
                return $defaultValue;
            }

            $inherit = false;
            $scopeConfig->getConfigDataValue($path, $inherit);

            return $inherit ? $defaultValue : $this->getCurrentValue($path, $scope, $scopeCode);
        }

        $websiteCode = $scopeCode;
        $fieldData = $this->getPostedFieldData($path, $groups);
        if ($fieldData === null) {
            return $this->getCurrentValue($path, ScopeInterface::SCOPE_WEBSITES, $websiteCode);
        }

        if (!empty($fieldData['inherit'])) {
            return $this->getCurrentValue($path, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null);
        }

        $value = $this->toString($fieldData['value'] ?? null);
        if ($path === Config::PATH_API_KEY && preg_match('/^\*+$/', $value) === 1) {
            return $this->getCurrentValue($path, ScopeInterface::SCOPE_WEBSITES, $websiteCode);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $groups
     */
    private function getProjectedDefaultValue(string $path, array $groups): string
    {
        $fieldData = $this->getPostedFieldData($path, $groups);
        if ($fieldData === null) {
            return $this->getCurrentValue($path, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null);
        }

        if (!empty($fieldData['inherit'])) {
            // Default scope has no parent, so restore falls back to module config.xml defaults.
            return self::DEFAULT_VALUES[$path];
        }

        $value = $this->toString($fieldData['value'] ?? null);
        if ($path === Config::PATH_API_KEY && preg_match('/^\*+$/', $value) === 1) {
            return $this->getCurrentValue($path, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $groups
     * @return array<string, mixed>|null
     */
    private function getPostedFieldData(string $path, array $groups): ?array
    {
        [$group, $field] = self::FIELD_PATHS[$path];
        $fieldData = $groups[$group]['fields'][$field] ?? null;

        return is_array($fieldData) ? $fieldData : null;
    }

    private function getCurrentValue(string $path, string $scope, ?string $scopeCode): string
    {
        return $this->toString($this->scopeConfig->getValue($path, $scope, $scopeCode));
    }

    private function toString(mixed $value): string
    {
        if ($value === null || is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw new LocalizedException(__('Private Captcha configuration values must be scalar.'));
    }
}
