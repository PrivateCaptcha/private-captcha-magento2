<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Unit\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\PrivateCaptcha\Model\Config as PrivateCaptchaConfig;
use PrivateCaptcha\PrivateCaptcha\Model\Config\Coexistence;
use PrivateCaptcha\PrivateCaptcha\Model\CustomDomain;

final class CoexistenceTest extends TestCase
{
    public function testFindsMappedNativeCaptchaAndRecaptchaOverlapsWithoutReturningKeys(): void
    {
        $coexistence = $this->createCoexistence([
            'private_captcha/credentials/site_key' => 'site-key',
            'private_captcha/credentials/api_key' => 'api-key',
            'private_captcha/protected_forms/contact_form' => '1',
            'customer/captcha/enable' => '1',
            'customer/captcha/forms' => 'contact_us,user_login',
            'recaptcha_frontend/type_for/contact' => 'invisible',
            'recaptcha_frontend/type_invisible/public_key' => 'public-key',
            'recaptcha_frontend/type_invisible/private_key' => 'private-key',
        ]);

        self::assertSame([
            ['form' => 'Contact Form', 'engine' => 'Magento CAPTCHA'],
            ['form' => 'Contact Form', 'engine' => 'Google reCAPTCHA v2 Invisible'],
        ], $coexistence->getOverlaps('website-a'));
    }

    public function testUsesEffectiveValuesForTheRequestedWebsiteOnly(): void
    {
        $coexistence = $this->createCoexistence([
            'website-a' => [
                'private_captcha/credentials/site_key' => 'site-key-a',
                'private_captcha/credentials/api_key' => 'api-key-a',
                'private_captcha/protected_forms/customer_login' => '1',
                'recaptcha_frontend/type_for/customer_login' => 'recaptcha_v3',
                'recaptcha_frontend/type_recaptcha_v3/public_key' => 'public-key',
                'recaptcha_frontend/type_recaptcha_v3/private_key' => 'private-key',
            ],
            'website-b' => [
                'private_captcha/credentials/site_key' => 'site-key-b',
                'private_captcha/credentials/api_key' => 'api-key-b',
                'private_captcha/protected_forms/customer_login' => '1',
                'recaptcha_frontend/type_for/customer_login' => '',
            ],
        ]);

        self::assertSame([
            ['form' => 'Customer Login', 'engine' => 'Google reCAPTCHA v3 Invisible'],
        ], $coexistence->getOverlaps('website-a'));
        self::assertSame([], $coexistence->getOverlaps('website-b'));
    }

    #[DataProvider('requiredRecaptchaModuleProvider')]
    public function testDoesNotReportRecaptchaWhenARequiredModuleIsDisabled(string $module): void
    {
        $coexistence = $this->createCoexistence(
            [
                'private_captcha/credentials/site_key' => 'site-key',
                'private_captcha/credentials/api_key' => 'api-key',
                'private_captcha/protected_forms/contact_form' => '1',
                'recaptcha_frontend/type_for/contact' => 'recaptcha',
                'recaptcha_frontend/type_recaptcha/public_key' => 'public-key',
                'recaptcha_frontend/type_recaptcha/private_key' => 'private-key',
            ],
            [$module => false]
        );

        self::assertSame([], $coexistence->getOverlaps('website-a'));
    }

    /** @return array<string, array{string}> */
    public static function requiredRecaptchaModuleProvider(): array
    {
        return [
            'selected engine' => ['Magento_ReCaptchaVersion2Checkbox'],
            'reCAPTCHA UI' => ['Magento_ReCaptchaUi'],
            'form integration' => ['Magento_ReCaptchaContact'],
            'base form' => ['Magento_Contact'],
        ];
    }

    public function testDoesNotRequireUnrelatedRecaptchaAdminOrFrontendUiModules(): void
    {
        $coexistence = $this->createCoexistence(
            [
                'private_captcha/credentials/site_key' => 'site-key',
                'private_captcha/credentials/api_key' => 'api-key',
                'private_captcha/protected_forms/contact_form' => '1',
                'recaptcha_frontend/type_for/contact' => 'recaptcha',
                'recaptcha_frontend/type_recaptcha/public_key' => 'public-key',
                'recaptcha_frontend/type_recaptcha/private_key' => 'private-key',
            ],
            [
                'Magento_ReCaptchaAdminUi' => false,
                'Magento_ReCaptchaFrontendUi' => false,
            ]
        );

        self::assertSame([
            ['form' => 'Contact Form', 'engine' => 'Google reCAPTCHA v2 Checkbox'],
        ], $coexistence->getOverlaps('website-a'));
    }

    public function testDoesNotReportRecaptchaWithoutEffectiveCredentials(): void
    {
        $coexistence = $this->createCoexistence([
            'private_captcha/credentials/site_key' => 'site-key',
            'private_captcha/credentials/api_key' => 'api-key',
            'private_captcha/protected_forms/contact_form' => '1',
            'recaptcha_frontend/type_for/contact' => 'recaptcha',
            'recaptcha_frontend/type_recaptcha/public_key' => 'public-key',
            'recaptcha_frontend/type_recaptcha/private_key' => '',
        ]);

        self::assertSame([], $coexistence->getOverlaps('website-a'));
    }

    public function testDoesNotReportOverlapWithoutEffectivePrivateCaptchaCredentials(): void
    {
        $coexistence = $this->createCoexistence([
            'private_captcha/protected_forms/customer_login' => '1',
            'customer/captcha/enable' => '1',
            'customer/captcha/forms' => 'user_login',
        ]);

        self::assertSame([], $coexistence->getOverlaps('website-a'));
    }

    public function testDoesNotDiscloseCaptchaConfigurationWithoutTheRelevantAcl(): void
    {
        $coexistence = $this->createCoexistence(
            [
                'private_captcha/credentials/site_key' => 'site-key',
                'private_captcha/credentials/api_key' => 'api-key',
                'private_captcha/protected_forms/contact_form' => '1',
                'customer/captcha/enable' => '1',
                'customer/captcha/forms' => 'contact_us',
                'recaptcha_frontend/type_for/contact' => 'invisible',
                'recaptcha_frontend/type_invisible/public_key' => 'public-key',
                'recaptcha_frontend/type_invisible/private_key' => 'private-key',
            ],
            [],
            [
                'Magento_Customer::config_customer' => false,
                'Magento_ReCaptchaUi::config' => false,
            ]
        );

        self::assertSame([], $coexistence->getOverlaps('website-a'));
    }

    /**
     * @param array<string, string>|array<string, array<string, string>> $values
     * @param array<string, bool> $modules
     * @param array<string, bool> $permissions
     */
    private function createCoexistence(
        array $values,
        array $modules = [],
        array $permissions = []
    ): Coexistence {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path, string $scope, string $websiteCode) use ($values): string {
                if ($scope !== ScopeInterface::SCOPE_WEBSITE) {
                    return '';
                }

                $websiteValues = $values[$websiteCode] ?? $values;

                return is_array($websiteValues) ? ($websiteValues[$path] ?? '') : '';
            }
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static function (string $path, string $scope, string $websiteCode) use ($values): bool {
                if ($scope !== ScopeInterface::SCOPE_WEBSITE) {
                    return false;
                }

                $websiteValues = $values[$websiteCode] ?? $values;

                return is_array($websiteValues) && ($websiteValues[$path] ?? '') === '1';
            }
        );

        $moduleManager = $this->createStub(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturnCallback(
            static fn (string $module): bool => $modules[$module] ?? true
        );

        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => $permissions[$resource] ?? true
        );

        $privateCaptchaConfig = new PrivateCaptchaConfig(
            $scopeConfig,
            $this->createStub(StoreManagerInterface::class),
            new CustomDomain()
        );

        return new Coexistence($scopeConfig, $moduleManager, $authorization, $privateCaptchaConfig);
    }
}
