<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Integration;

use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\TypePool;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\PrivateCaptcha\Model\Config;

final class ConfigScopeTest extends TestCase
{
    /**
     * @magentoDataFixture Magento/Store/_files/second_website_with_two_stores.php
     * @magentoConfigFixture default private_captcha/protected_forms/contact_form 0
     * @magentoConfigFixture current_website private_captcha/protected_forms/contact_form 1
     * @magentoConfigFixture current_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture current_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture test_website private_captcha/protected_forms/contact_form 0
     * @magentoConfigFixture test_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture test_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture fixture_second_store_store private_captcha/protected_forms/contact_form 1
     * @magentoConfigFixture fixture_second_store_store private_captcha/advanced/theme dark
     * @magentoConfigFixture fixture_second_store_store private_captcha/advanced/language de
     * @magentoConfigFixture fixture_second_store_store private_captcha/advanced/custom_styles second-store-styles
     * @magentoConfigFixture fixture_third_store_store private_captcha/advanced/language fr
     * @magentoConfigFixture fixture_third_store_store private_captcha/advanced/theme light
     * @magentoConfigFixture fixture_third_store_store private_captcha/advanced/custom_styles third-store-styles
     */
    public function testWebsiteScopedValuesRemainIsolatedFromStoreAndOtherWebsiteOverrides(): void
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $config = $objectManager->get(Config::class);
        $storeManager = $objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);

        self::assertTrue($config->isFormEnabled(Config::FORM_CONTACT));
        self::assertFalse(
            $config->isFormEnabled(Config::FORM_CONTACT, (int) $storeManager->getStore('fixture_second_store')->getId())
        );
        self::assertSame(
            'dark',
            $config->getTheme((int) $storeManager->getStore('fixture_second_store')->getId())
        );
        self::assertSame(
            'de',
            $config->getLanguage((int) $storeManager->getStore('fixture_second_store')->getId())
        );
        self::assertSame(
            'second-store-styles',
            $config->getCustomStyles((int) $storeManager->getStore('fixture_second_store')->getId())
        );
        self::assertSame(
            'light',
            $config->getTheme((int) $storeManager->getStore('fixture_third_store')->getId())
        );
        self::assertSame(
            'fr',
            $config->getLanguage((int) $storeManager->getStore('fixture_third_store')->getId())
        );
        self::assertSame(
            'third-store-styles',
            $config->getCustomStyles((int) $storeManager->getStore('fixture_third_store')->getId())
        );
    }

    /**
     * @magentoAppArea adminhtml
     */
    public function testMergedConfigProtectsSecretsAndMarksDeploymentSettings(): void
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $typePool = $objectManager->get(TypePool::class);
        $apiKey = $objectManager->get(Structure::class)
            ->getElement('private_captcha/credentials/api_key');

        self::assertInstanceOf(Encrypted::class, $apiKey->getBackendModel());
        self::assertTrue($typePool->isPresent('private_captcha/credentials/api_key', TypePool::TYPE_SENSITIVE));
        self::assertTrue($typePool->isPresent('private_captcha/credentials/site_key', TypePool::TYPE_ENVIRONMENT));
        self::assertTrue($typePool->isPresent('private_captcha/credentials/api_key', TypePool::TYPE_ENVIRONMENT));
        self::assertTrue($typePool->isPresent('private_captcha/advanced/custom_domain', TypePool::TYPE_ENVIRONMENT));
    }
}
