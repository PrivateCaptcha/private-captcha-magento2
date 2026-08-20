<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Unit\Model;

use InvalidArgumentException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\Client;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\CustomDomain;

final class ConfigTest extends TestCase
{
    private CustomDomain $customDomain;

    protected function setUp(): void
    {
        $this->customDomain = new CustomDomain();
    }

    public function testCustomDomainDerivesAllPublicEndpoints(): void
    {
        $domain = $this->customDomain->normalize(' https://cdn.example.test/ ');

        self::assertSame('example.test', $domain);
        self::assertSame('https://cdn.example.test/widget/js/privatecaptcha.js', $this->customDomain->getScriptUrl($domain));
        self::assertSame('https://api.example.test/puzzle', $this->customDomain->getPuzzleEndpoint($domain));
    }

    #[DataProvider('invalidCustomDomainProvider')]
    public function testCustomDomainRejectsInvalidHostValues(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->customDomain->normalize($value);
    }

    /** @return array<string, array{string}> */
    public static function invalidCustomDomainProvider(): array
    {
        return [
            'path' => ['https://example.test/path'],
            'port' => ['https://example.test:8443'],
            'credentials' => ['https://user@example.test'],
            'query string' => ['https://example.test?source=store'],
            'fragment' => ['https://example.test#widget'],
            'IPv4 address' => ['127.0.0.1'],
            'short IPv4 address' => ['127.1'],
            'integer IPv4 address' => ['2130706433'],
            'octal IPv4 address' => ['0177.0.0.1'],
            'local hostname' => ['localhost'],
            'extra trailing slash' => ['example.test//'],
        ];
    }

    #[DataProvider('formEnablementProvider')]
    public function testFormEnablementRequiresTheFlagAndBothCredentials(
        bool $flag,
        string $siteKey,
        string $apiKey,
        bool $expected
    ): void
    {
        $config = $this->createConfig(
            [
                Config::PATH_SITE_KEY => $siteKey,
                Config::PATH_API_KEY => $apiKey,
            ],
            [Config::FORM_PATHS[Config::FORM_CONTACT] => $flag]
        );

        self::assertSame($expected, $config->isFormEnabled(Config::FORM_CONTACT, 3));
    }

    /** @return array<string, array{bool, string, string, bool}> */
    public static function formEnablementProvider(): array
    {
        return [
            'disabled with credentials' => [false, 'site-key', 'api-key', false],
            'missing Site Key' => [true, '', 'api-key', false],
            'blank API Key' => [true, 'site-key', "\t", false],
            'enabled and configured' => [true, 'site-key', 'api-key', true],
        ];
    }

    public function testCustomDomainOverridesEuIsolation(): void
    {
        $config = $this->createConfig(
            ['private_captcha/advanced/custom_domain' => 'https://api.example.test/'],
            ['private_captcha/advanced/eu_isolation' => true]
        );

        self::assertFalse($config->isEuIsolation(3));
        self::assertSame('https://cdn.example.test/widget/js/privatecaptcha.js', $config->getScriptUrl(3));
        self::assertSame('https://api.example.test/puzzle', $config->getPuzzleEndpoint(3));
        self::assertSame('example.test', $config->getVerificationDomain(3));
    }

    public function testEuIsolationUsesTheSdkEuDomainWithoutCustomDomain(): void
    {
        $config = $this->createConfig([], ['private_captcha/advanced/eu_isolation' => true]);

        self::assertTrue($config->isEuIsolation(3));
        self::assertSame(Config::DEFAULT_SCRIPT_URL, $config->getScriptUrl(3));
        self::assertNull($config->getPuzzleEndpoint(3));
        self::assertSame(Client::EU_DOMAIN, $config->getVerificationDomain(3));
    }

    public function testInvalidLanguageAndStartModeUseSafeDefaults(): void
    {
        $config = $this->createConfig(
            [
                'private_captcha/advanced/language' => 'invalid',
                'private_captcha/advanced/start_mode' => 'invalid',
            ]
        );

        self::assertSame('auto', $config->getLanguage(3));
        self::assertSame('auto', $config->getStartMode(3));
    }

    public function testEmptyCustomStylesUseInheritedFontDefault(): void
    {
        $config = $this->createConfig([
            Config::PATH_CUSTOM_STYLES => " \t\n",
        ]);

        self::assertSame('font-size: inherit;', $config->getCustomStyles(3));
    }

    public function testConfiguredCustomStylesReplaceInheritedFontDefault(): void
    {
        $config = $this->createConfig([
            Config::PATH_CUSTOM_STYLES => 'font-size: 16px; color: teal;',
        ]);

        self::assertSame('font-size: 16px; color: teal;', $config->getCustomStyles(3));
    }

    private function createConfig(array $values = [], array $flags = []): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path, string $scope, string $scopeCode) use ($values): string {
                if ($scope === ScopeInterface::SCOPE_STORE) {
                    return $scopeCode === 'store-a' ? ($values[$path] ?? '') : '';
                }

                return $scope === ScopeInterface::SCOPE_WEBSITE && $scopeCode === 'website-a'
                    ? ($values[$path] ?? '')
                    : '';
            }
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static function (string $path, string $scope, string $websiteCode) use ($flags): bool {
                return $scope === ScopeInterface::SCOPE_WEBSITE && $websiteCode === 'website-a'
                    ? ($flags[$path] ?? false)
                    : false;
            }
        );

        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $store->method('getCode')->willReturn('store-a');
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getCode')->willReturn('website-a');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeManager->method('getWebsite')->willReturn($website);

        return new Config($scopeConfig, $storeManager, $this->customDomain);
    }
}
