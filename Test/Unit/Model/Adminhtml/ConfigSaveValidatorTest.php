<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Unit\Model\Adminhtml;

use Magento\Config\Model\Config as MagentoConfig;
use Magento\Config\Model\Config\Factory as ConfigFactory;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\PrivateCaptcha\Model\Adminhtml\ConfigSaveValidator;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\CustomDomain;

require_once dirname(__DIR__, 4) . '/Model/CustomDomain.php';
require_once dirname(__DIR__, 4) . '/Model/Config.php';
require_once dirname(__DIR__, 4) . '/Model/Adminhtml/ConfigSaveValidator.php';

final class ConfigSaveValidatorTest extends TestCase
{
    public function testEncryptedPlaceholderUsesExistingEffectiveApiKey(): void
    {
        $validator = $this->createValidator([
            Config::PATH_SITE_KEY => 'site-key',
            Config::PATH_API_KEY => 'existing-api-key',
        ]);

        $validator->validate($this->createSaveConfig('1', $this->buildGroups([
            'credentials' => ['api_key' => '******'],
            'protected_forms' => ['contact_form' => '1'],
        ])));
    }

    /**
     * @dataProvider invalidAdvancedValueProvider
     */
    #[DataProvider('invalidAdvancedValueProvider')]
    public function testInvalidAdvancedValueIsRejectedBeforeSave(string $field, string $expectedMessage): void
    {
        $validator = $this->createValidator();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($expectedMessage);

        $validator->validate($this->createSaveConfig('1', $this->buildGroups([
            'advanced' => [$field => 'invalid'],
        ])));
    }

    /** @return array<string, array{string, string}> */
    public static function invalidAdvancedValueProvider(): array
    {
        return [
            'language' => ['language', 'Language'],
            'start mode' => ['start_mode', 'Start Mode'],
            'EU isolation' => ['eu_isolation', 'EU Isolation'],
            'debug mode' => ['debug_mode', 'Debug Mode'],
        ];
    }

    /**
     * @dataProvider unsupportedScopeProvider
     */
    #[DataProvider('unsupportedScopeProvider')]
    public function testUnsupportedScopesAreRejected(?string $store, ?string $scope): void
    {
        $validator = $this->createValidator();

        $this->expectException(LocalizedException::class);

        $validator->validate($this->createSaveConfig(null, [], $store, $scope));
    }

    /** @return array<string, array{?string, ?string}> */
    public static function unsupportedScopeProvider(): array
    {
        return [
            'singular group scope' => [null, ScopeInterface::SCOPE_GROUP],
            'plural group scope' => [null, ScopeInterface::SCOPE_GROUPS],
            'unknown scope' => [null, 'custom'],
        ];
    }

    public function testStoreScopeRejectsInvalidLanguage(): void
    {
        $validator = $this->createValidator();

        $this->expectException(LocalizedException::class);

        $validator->validate($this->createSaveConfig(null, $this->buildGroups([
            'advanced' => ['language' => 'invalid'],
        ]), '3'));
    }

    /**
     * @dataProvider websiteOnlyStoreFieldProvider
     */
    #[DataProvider('websiteOnlyStoreFieldProvider')]
    public function testStoreScopeRejectsWebsiteOnlyFields(string $group, string $field): void
    {
        $validator = $this->createValidator();

        $this->expectException(LocalizedException::class);

        $validator->validate($this->createSaveConfig(null, $this->buildGroups([
            $group => [$field => '1'],
        ]), '3'));
    }

    /** @return array<string, array{string, string}> */
    public static function websiteOnlyStoreFieldProvider(): array
    {
        return [
            'Site Key' => ['credentials', 'site_key'],
            'API Key' => ['credentials', 'api_key'],
            'EU Isolation' => ['advanced', 'eu_isolation'],
            'Custom Domain' => ['advanced', 'custom_domain'],
            'Debug Mode' => ['advanced', 'debug_mode'],
            'Start Mode' => ['advanced', 'start_mode'],
            'Customer Login' => ['protected_forms', 'customer_login'],
            'Customer Registration' => ['protected_forms', 'customer_registration'],
            'Forgot Password' => ['protected_forms', 'forgot_password'],
            'Contact Form' => ['protected_forms', 'contact_form'],
            'Product Review' => ['protected_forms', 'product_review'],
            'Email to Friend' => ['protected_forms', 'email_to_friend'],
            'Wishlist Share' => ['protected_forms', 'wishlist_share'],
            'Orders and Returns' => ['protected_forms', 'orders_returns'],
        ];
    }

    public function testStoreScopeInheritanceUsesWebsiteLanguage(): void
    {
        $this->expectNotToPerformAssertions();
        $validator = $this->createValidator([
            ScopeInterface::SCOPE_WEBSITES . ':' . Config::PATH_LANGUAGE => 'fr',
            ScopeInterface::SCOPE_STORES . ':' . Config::PATH_LANGUAGE => 'invalid-store-value',
        ]);

        $validator->validate($this->createSaveConfig(null, $this->buildGroups([
            'advanced' => [
                'language' => ['value' => 'ignored', 'inherit' => '1'],
            ],
        ]), '3'));
    }

    public function testStoreScopeUsesEffectiveDeployedLanguageInsteadOfPostedValue(): void
    {
        $this->expectNotToPerformAssertions();
        $validator = $this->createValidator(
            [ScopeInterface::SCOPE_STORES . ':' . Config::PATH_LANGUAGE => 'de'],
            [],
            static fn (string $path, string $scope, ?string $scopeCode): bool => [
                $path,
                $scope,
                $scopeCode,
            ] === [Config::PATH_LANGUAGE, ScopeInterface::SCOPE_STORES, 'store-a']
        );

        $validator->validate($this->createSaveConfig(null, $this->buildGroups([
            'advanced' => ['language' => 'invalid-posted-value'],
        ]), '3'));
    }

    public function testWebsiteLockCheckUsesCanonicalWebsiteScope(): void
    {
        $checkedSiteKey = false;
        $validator = $this->createValidator(
            [],
            [],
            static function (string $path, string $scope, ?string $scopeCode) use (&$checkedSiteKey): bool {
                if ($path === Config::PATH_SITE_KEY) {
                    self::assertSame(ScopeInterface::SCOPE_WEBSITES, $scope);
                    self::assertSame('website-a', $scopeCode);
                    $checkedSiteKey = true;
                }

                return false;
            }
        );

        $validator->validate($this->createSaveConfig('1', $this->buildGroups([
            'protected_forms' => ['contact_form' => '0'],
        ])));

        self::assertTrue($checkedSiteKey);
    }

    public function testLockedCredentialsUseTheirEffectiveDeploymentValues(): void
    {
        $validator = $this->createValidator(
            [
                Config::PATH_SITE_KEY => 'deployed-site-key',
                Config::PATH_API_KEY => 'deployed-api-key',
            ],
            [Config::PATH_SITE_KEY => true, Config::PATH_API_KEY => true]
        );

        $validator->validate($this->createSaveConfig('1', $this->buildGroups([
            'credentials' => ['site_key' => '', 'api_key' => ''],
            'protected_forms' => ['contact_form' => '1'],
        ])));
    }

    public function testLockedCredentialsDoNotAcceptPostedValues(): void
    {
        $validator = $this->createValidator(
            [],
            [Config::PATH_SITE_KEY => true, Config::PATH_API_KEY => true]
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Site Key and API Key');

        $validator->validate($this->createSaveConfig('1', $this->buildGroups([
            'credentials' => ['site_key' => 'posted-site-key', 'api_key' => 'posted-api-key'],
            'protected_forms' => ['contact_form' => '1'],
        ])));
    }

    /**
     * @param array<string, string> $values
     * @param array<string, bool> $lockedPaths
     * @param callable(string, string, ?string): bool|null $isReadOnly
     */
    private function createValidator(
        array $values = [],
        array $lockedPaths = [],
        ?callable $isReadOnly = null
    ): ConfigSaveValidator
    {
        $scopeConfig = $this->createStub(ReinitableConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path, string $scope, ?string $scopeCode = null) use ($values): string {
                $scopedValue = $values[$scope . ':' . $path] ?? null;
                if ($scopedValue !== null) {
                    return $scopedValue;
                }

                return $values[$path] ?? match ($path) {
                    Config::PATH_EU_ISOLATION, Config::PATH_DEBUG_MODE => '0',
                    Config::PATH_LANGUAGE, Config::PATH_START_MODE => 'auto',
                    Config::FORM_PATHS[Config::FORM_CUSTOMER_LOGIN],
                    Config::FORM_PATHS[Config::FORM_CUSTOMER_REGISTRATION],
                    Config::FORM_PATHS[Config::FORM_FORGOT_PASSWORD],
                    Config::FORM_PATHS[Config::FORM_CONTACT],
                    Config::FORM_PATHS[Config::FORM_PRODUCT_REVIEW],
                    Config::FORM_PATHS[Config::FORM_EMAIL_TO_FRIEND],
                    Config::FORM_PATHS[Config::FORM_WISHLIST_SHARE],
                    Config::FORM_PATHS[Config::FORM_ORDERS_RETURNS] => '0',
                    default => '',
                };
            }
        );

        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getCode')->willReturn('website-a');

        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn('store-a');
        $store->method('getWebsiteId')->willReturn(1);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willReturn($website);
        $storeManager->method('getStore')->willReturn($store);

        $settingChecker = $this->createStub(SettingChecker::class);
        $settingChecker->method('isReadOnly')->willReturnCallback(
            static function (string $path, string $scope, ?string $scopeCode) use ($lockedPaths, $isReadOnly): bool {
                if ($isReadOnly !== null) {
                    return $isReadOnly($path, $scope, $scopeCode);
                }

                return $lockedPaths[$path] ?? false;
            }
        );

        return new ConfigSaveValidator(
            $scopeConfig,
            $storeManager,
            $this->createStub(ConfigFactory::class),
            $settingChecker,
            new CustomDomain()
        );
    }

    /**
     * @param array<string, array<string, string|array{value: string, inherit: string}>> $groups
     */
    private function createSaveConfig(
        ?string $website,
        array $groups,
        ?string $store = null,
        ?string $scope = null
    ): MagentoConfig
    {
        return new class ($website, $groups, $store, $scope) extends MagentoConfig {
            /** @param array<string, array{fields: array<string, array{value: string, inherit?: string}>}> $groups */
            public function __construct(
                private readonly ?string $website,
                private readonly array $groups,
                private readonly ?string $store,
                private readonly ?string $scope
            ) {
            }

            public function getSection(): string
            {
                return 'private_captcha';
            }

            public function getWebsite(): ?string
            {
                return $this->website;
            }

            public function getStore(): ?string
            {
                return $this->store;
            }

            public function getScope(): ?string
            {
                return $this->scope;
            }

            public function getData($key = '', $index = null)
            {
                return match ($key) {
                    'groups' => $this->groups,
                    'scope_id' => null,
                    'scope_code' => null,
                    'website' => $this->website,
                    default => null,
                };
            }

            public function getConfigDataValue($path, &$inherit = null, $configData = null): string
            {
                $inherit = true;

                return '';
            }
        };
    }

    /**
     * @param array<string, array<string, string|array{value: string, inherit: string}>> $values
     * @return array<string, array{fields: array<string, array{value: string, inherit?: string}>}>
     */
    private function buildGroups(array $values): array
    {
        $groups = [];
        foreach ($values as $group => $fields) {
            foreach ($fields as $field => $value) {
                $groups[$group]['fields'][$field] = is_array($value) ? $value : ['value' => $value];
            }
        }

        return $groups;
    }
}
