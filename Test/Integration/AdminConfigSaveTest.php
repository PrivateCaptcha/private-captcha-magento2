<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Integration;

use Magento\Config\Model\Config\Factory as ConfigFactory;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\Config as ConfigFixture;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\PrivateCaptcha\Model\Config;

final class AdminConfigSaveTest extends TestCase
{
    private ObjectManagerInterface $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoAppArea adminhtml
     * @magentoDbIsolation enabled
     */
    public function testSameRequestCredentialsAndEnablementSaveTogether(): void
    {
        $websiteId = $this->getCurrentWebsiteId();
        $this->save($websiteId, [
            'credentials' => [
                'fields' => [
                    'site_key' => ['value' => 'site-key'],
                    'api_key' => ['value' => 'api-key'],
                ],
            ],
            'protected_forms' => ['fields' => ['contact_form' => ['value' => '1']]],
        ]);

        $this->reinitializeConfig();
        $scopeConfig = $this->objectManager->get(ScopeConfigInterface::class);
        $websiteCode = $this->getCurrentWebsiteCode();

        self::assertSame(
            'site-key',
            $scopeConfig->getValue('private_captcha/credentials/site_key', ScopeInterface::SCOPE_WEBSITE, $websiteCode)
        );
        self::assertSame(
            'api-key',
            $scopeConfig->getValue(Config::PATH_API_KEY, ScopeInterface::SCOPE_WEBSITE, $websiteCode)
        );
        self::assertTrue(
            $scopeConfig->isSetFlag('private_captcha/protected_forms/contact_form', ScopeInterface::SCOPE_WEBSITE, $websiteCode)
        );

        $resource = $this->objectManager->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $storedApiKey = $connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName('core_config_data'), ['value'])
                ->where('path = ?', Config::PATH_API_KEY)
                ->where('scope = ?', ScopeInterface::SCOPE_WEBSITES)
                ->where('scope_id = ?', $websiteId)
        );
        self::assertIsString($storedApiKey);
        self::assertNotSame('api-key', $storedApiKey);
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoAppArea adminhtml
     * @magentoDbIsolation enabled
     */
    public function testMissingCredentialRejectsTheEntireSave(): void
    {
        $websiteId = $this->getCurrentWebsiteId();

        try {
            $this->save($websiteId, [
                'credentials' => ['fields' => ['site_key' => ['value' => 'site-key']]],
                'protected_forms' => ['fields' => ['contact_form' => ['value' => '1']]],
            ]);
            self::fail('Expected missing API Key to reject the save.');
        } catch (LocalizedException) {
        }

        $this->reinitializeConfig();
        $scopeConfig = $this->objectManager->get(ScopeConfigInterface::class);
        $websiteCode = $this->getCurrentWebsiteCode();

        self::assertNull(
            $scopeConfig->getValue('private_captcha/credentials/site_key', ScopeInterface::SCOPE_WEBSITE, $websiteCode)
        );
        self::assertFalse(
            $scopeConfig->isSetFlag('private_captcha/protected_forms/contact_form', ScopeInterface::SCOPE_WEBSITE, $websiteCode)
        );
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoAppArea adminhtml
     * @magentoDbIsolation enabled
     * @magentoConfigFixture default private_captcha/credentials/site_key default-site-key
     * @magentoConfigFixture default private_captcha/credentials/api_key default-api-key
     */
    #[ConfigFixture('private_captcha/credentials/site_key', 'default-site-key')]
    #[ConfigFixture('private_captcha/credentials/api_key', 'default-api-key')]
    public function testWebsiteCredentialRestoreUsesEffectiveDefaultValues(): void
    {
        $this->save(null, [
            'credentials' => [
                'fields' => [
                    'site_key' => ['value' => 'default-site-key'],
                    'api_key' => ['value' => 'default-api-key'],
                ],
            ],
        ]);

        $websiteId = $this->getCurrentWebsiteId();
        $this->save($websiteId, [
            'credentials' => [
                'fields' => [
                    'site_key' => ['value' => 'ignored', 'inherit' => '1'],
                    'api_key' => ['value' => '******', 'inherit' => '1'],
                ],
            ],
            'protected_forms' => ['fields' => ['contact_form' => ['value' => '1']]],
        ]);

        $this->reinitializeConfig();
        $scopeConfig = $this->objectManager->get(ScopeConfigInterface::class);
        $websiteCode = $this->getCurrentWebsiteCode();
        self::assertSame(
            'default-site-key',
            $scopeConfig->getValue(Config::PATH_SITE_KEY, ScopeInterface::SCOPE_WEBSITE, $websiteCode)
        );
        self::assertSame(
            'default-api-key',
            $scopeConfig->getValue(Config::PATH_API_KEY, ScopeInterface::SCOPE_WEBSITE, $websiteCode)
        );
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoAppArea adminhtml
     * @magentoDbIsolation enabled
     * @magentoConfigFixture default private_captcha/credentials/site_key default-site-key
     * @magentoConfigFixture default private_captcha/credentials/api_key default-api-key
     * @magentoConfigFixture current_website private_captcha/protected_forms/contact_form 1
     */
    #[ConfigFixture('private_captcha/credentials/site_key', 'default-site-key')]
    #[ConfigFixture('private_captcha/credentials/api_key', 'default-api-key')]
    #[ConfigFixture('private_captcha/protected_forms/contact_form', '1', ScopeInterface::SCOPE_WEBSITE, 'base')]
    public function testDefaultCredentialRemovalIsRejectedForAnInheritingEnabledWebsite(): void
    {
        $this->save(null, [
            'credentials' => [
                'fields' => [
                    'site_key' => ['value' => 'default-site-key'],
                    'api_key' => ['value' => 'default-api-key'],
                ],
            ],
        ]);
        $this->save($this->getCurrentWebsiteId(), [
            'protected_forms' => ['fields' => ['contact_form' => ['value' => '1']]],
        ]);

        try {
            $this->save(null, [
                'credentials' => ['fields' => ['site_key' => ['value' => '']]],
            ]);
            self::fail('Expected removing the inherited Site Key to reject the save.');
        } catch (LocalizedException) {
        }

        $this->reinitializeConfig();
        self::assertSame(
            'default-site-key',
            $this->objectManager->get(ScopeConfigInterface::class)->getValue('private_captcha/credentials/site_key')
        );
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoAppArea adminhtml
     * @magentoDbIsolation enabled
     */
    public function testInvalidCustomDomainRejectsTheEntireSave(): void
    {
        $websiteId = $this->getCurrentWebsiteId();

        try {
            $this->save($websiteId, [
                'advanced' => [
                    'fields' => [
                        'custom_domain' => ['value' => 'https://example.test/path'],
                        'debug_mode' => ['value' => '1'],
                    ],
                ],
            ]);
            self::fail('Expected an invalid Custom Domain to reject the save.');
        } catch (LocalizedException) {
        }

        $this->reinitializeConfig();
        $scopeConfig = $this->objectManager->get(ScopeConfigInterface::class);
        $websiteCode = $this->getCurrentWebsiteCode();
        self::assertNull(
            $scopeConfig->getValue(Config::PATH_CUSTOM_DOMAIN, ScopeInterface::SCOPE_WEBSITE, $websiteCode)
        );
        self::assertFalse(
            $scopeConfig->isSetFlag(Config::PATH_DEBUG_MODE, ScopeInterface::SCOPE_WEBSITE, $websiteCode)
        );
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoAppArea adminhtml
     * @magentoDbIsolation enabled
     */
    public function testStoreScopedLanguageAndCustomStylesSaveTogether(): void
    {
        $store = $this->objectManager->get(StoreManagerInterface::class)->getStore();
        $this->save($this->getCurrentWebsiteId(), [
            'advanced' => [
                'fields' => [
                    'language' => ['value' => 'en'],
                    'custom_styles' => ['value' => 'website-styles'],
                ],
            ],
        ]);
        $this->reinitializeConfig();

        $this->save(null, [
            'advanced' => [
                'fields' => [
                    'language' => ['value' => 'de'],
                    'custom_styles' => ['value' => '--private-captcha-accent: teal;'],
                ],
            ],
        ], (int) $store->getId());

        $this->reinitializeConfig();
        $config = $this->objectManager->get(Config::class);

        self::assertSame('de', $config->getLanguage((int) $store->getId()));
        self::assertSame('--private-captcha-accent: teal;', $config->getCustomStyles((int) $store->getId()));

        $this->save(null, [
            'advanced' => [
                'fields' => [
                    'language' => ['value' => 'ignored', 'inherit' => '1'],
                    'custom_styles' => ['value' => 'ignored', 'inherit' => '1'],
                ],
            ],
        ], (int) $store->getId());

        $this->reinitializeConfig();
        self::assertSame('en', $config->getLanguage((int) $store->getId()));
        self::assertSame('website-styles', $config->getCustomStyles((int) $store->getId()));
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoAppArea adminhtml
     * @magentoDbIsolation enabled
     */
    public function testStoreScopedWebsiteOnlyFieldIsRejected(): void
    {
        try {
            $this->save(
                null,
                ['protected_forms' => ['fields' => ['contact_form' => ['value' => '1']]]],
                (int) $this->objectManager->get(StoreManagerInterface::class)->getStore()->getId()
            );
            self::fail('Expected Store View scope to reject a Website-only field.');
        } catch (LocalizedException) {
        }

        $storeId = (int) $this->objectManager->get(StoreManagerInterface::class)->getStore()->getId();
        $resource = $this->objectManager->get(ResourceConnection::class);
        $storedValue = $resource->getConnection()->fetchOne(
            $resource->getConnection()->select()
                ->from($resource->getTableName('core_config_data'), ['value'])
                ->where('scope = ?', ScopeInterface::SCOPE_STORES)
                ->where('scope_id = ?', $storeId)
                ->where('path = ?', Config::FORM_PATHS[Config::FORM_CONTACT])
        );
        self::assertFalse($storedValue);
    }

    /**
     * @param array<string, array{fields: array<string, array{value: string, inherit?: string}>}> $groups
     */
    private function save(?int $websiteId, array $groups, ?int $storeId = null): void
    {
        $data = [
            'section' => 'private_captcha',
            'groups' => $groups,
        ];
        if ($websiteId !== null) {
            $data['website'] = (string) $websiteId;
        }
        if ($storeId !== null) {
            $data['store'] = (string) $storeId;
        }

        $this->objectManager->get(ConfigFactory::class)->create(['data' => $data])->save();
    }

    private function getCurrentWebsiteId(): int
    {
        return (int) $this->objectManager->get(StoreManagerInterface::class)->getStore()->getWebsiteId();
    }

    private function getCurrentWebsiteCode(): string
    {
        return (string) $this->objectManager->get(StoreManagerInterface::class)->getWebsite()->getCode();
    }

    private function reinitializeConfig(): void
    {
        $this->objectManager->get(ReinitableConfigInterface::class)->reinit();
    }
}
