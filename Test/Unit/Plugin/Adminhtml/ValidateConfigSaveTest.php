<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Unit\Plugin\Adminhtml;

use Magento\Config\Model\Config as MagentoConfig;
use Magento\Config\Model\Config\Factory as ConfigFactory;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\PrivateCaptcha\Model\Adminhtml\ConfigSaveValidator;
use PrivateCaptcha\PrivateCaptcha\Model\Adminhtml\ConfigSaveHandler;
use PrivateCaptcha\PrivateCaptcha\Model\Adminhtml\CurrentSettingsTester;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\CustomDomain;
use PrivateCaptcha\PrivateCaptcha\Plugin\Adminhtml\ValidateConfigSave;

final class ValidateConfigSaveTest extends TestCase
{
    public function testSuccessfulPrivateCaptchaSaveCleansOnlyAffectedCacheTypes(): void
    {
        $cleanedTypes = [];
        $cacheTypeList = $this->createStub(TypeListInterface::class);
        $cacheTypeList->method('cleanType')
            ->willReturnCallback(static function (string $cacheType) use (&$cleanedTypes): void {
                $cleanedTypes[] = $cacheType;
            });

        $plugin = new ValidateConfigSave(new ConfigSaveHandler(
            $this->createValidator(),
            $cacheTypeList,
            $this->createMock(LockManagerInterface::class),
            $this->createStub(ManagerInterface::class),
            $this->createStub(WriterInterface::class),
            $this->createResourceConnection()
        ));
        $subject = $this->createConfigSubject('private_captcha');

        $plugin->afterSave($subject, $subject);
        self::assertSame(['config', 'layout', 'block_html', 'full_page'], $cleanedTypes);
    }

    public function testUnrelatedConfigSaveDoesNotResolvePrivateCaptchaHandler(): void
    {
        $handler = $this->createMock(ConfigSaveHandler::class);
        $handler->expects(self::never())->method('aroundSave');
        $handler->expects(self::never())->method('afterSave');

        $plugin = new ValidateConfigSave($handler);
        $subject = $this->createConfigSubject('system');
        $result = $this->createConfigSubject('result');
        $proceedCalls = 0;

        $plugin->aroundSave(
            $subject,
            static function () use (&$proceedCalls, $result): MagentoConfig {
                $proceedCalls++;

                return $result;
            }
        );
        self::assertSame(1, $proceedCalls);
        $plugin->afterSave($subject, $result);
    }

    public function testUnrelatedConfigSaveRestoresSubjectWhenEarlierPluginReturnsNothing(): void
    {
        $handler = $this->createMock(ConfigSaveHandler::class);
        $handler->expects(self::never())->method('aroundSave');
        $handler->expects(self::never())->method('afterSave');

        $plugin = new ValidateConfigSave($handler);
        $subject = $this->createConfigSubject('system');

        self::assertSame($subject, $plugin->afterSave($subject, null));
    }

    public function testPrivateCaptchaSaveHoldsTheConfigurationLockUntilSaveCompletes(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects(self::once())
            ->method('lock')
            ->with('private_captcha_config_save', 5)
            ->willReturn(true);
        $lockManager->expects(self::once())
            ->method('unlock')
            ->with('private_captcha_config_save');
        $reinitableConfig = null;
        $validator = $this->createValidator($reinitableConfig);
        $reinitableConfig->expects(self::once())->method('reinit');

        $plugin = new ValidateConfigSave(new ConfigSaveHandler(
            $validator,
            $this->createMock(TypeListInterface::class),
            $lockManager,
            $this->createStub(ManagerInterface::class),
            $this->createStub(WriterInterface::class),
            $this->createResourceConnection()
        ));
        $subject = $this->createConfigSubject('private_captcha');

        $plugin->aroundSave($subject, static fn () => $subject);
    }

    public function testUnavailableConfigurationLockRejectsTheSave(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects(self::once())
            ->method('lock')
            ->with('private_captcha_config_save', 5)
            ->willReturn(false);
        $lockManager->expects(self::never())->method('unlock');
        $reinitableConfig = null;
        $validator = $this->createValidator($reinitableConfig);
        $reinitableConfig->expects(self::never())->method('reinit');

        $plugin = new ValidateConfigSave(new ConfigSaveHandler(
            $validator,
            $this->createMock(TypeListInterface::class),
            $lockManager,
            $this->createStub(ManagerInterface::class),
            $this->createStub(WriterInterface::class),
            $this->createResourceConnection()
        ));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('already being saved');

        $plugin->aroundSave($this->createConfigSubject('private_captcha'), static function (): void {
            self::fail('The save must not proceed without the configuration lock.');
        });
    }

    public function testConfigurationLockIsReleasedWhenSaveFails(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects(self::once())->method('lock')->willReturn(true);
        $lockManager->expects(self::once())
            ->method('unlock')
            ->with('private_captcha_config_save');
        $reinitableConfig = null;
        $validator = $this->createValidator($reinitableConfig);
        $reinitableConfig->expects(self::exactly(2))->method('reinit');
        $connection = null;
        $resourceConnection = $this->createResourceConnection($connection);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::never())->method('commit');
        $connection->expects(self::once())->method('rollBack');

        $plugin = new ValidateConfigSave(new ConfigSaveHandler(
            $validator,
            $this->createMock(TypeListInterface::class),
            $lockManager,
            $this->createStub(ManagerInterface::class),
            $this->createStub(WriterInterface::class),
            $resourceConnection
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('save failed');

        $plugin->aroundSave($this->createConfigSubject('private_captcha'), static function (): void {
            throw new \RuntimeException('save failed');
        });
    }

    public function testConfigurationLockIsReleasedWhenDatabaseConnectionFails(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects(self::once())->method('lock')->willReturn(true);
        $lockManager->expects(self::once())
            ->method('unlock')
            ->with('private_captcha_config_save');
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::once())
            ->method('getConnection')
            ->willThrowException(new \RuntimeException('connection failed'));
        $reinitableConfig = null;
        $validator = $this->createValidator($reinitableConfig);
        $reinitableConfig->expects(self::never())->method('reinit');
        $plugin = new ValidateConfigSave(new ConfigSaveHandler(
            $validator,
            $this->createStub(TypeListInterface::class),
            $lockManager,
            $this->createStub(ManagerInterface::class),
            $this->createStub(WriterInterface::class),
            $resourceConnection
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('connection failed');

        $plugin->aroundSave($this->createConfigSubject('private_captcha'), static function (): void {
            self::fail('The save must not proceed without a database connection.');
        });
    }

    private function createValidator(?ReinitableConfigInterface &$reinitableConfig = null): ConfigSaveValidator
    {
        $scopeConfig = $this->createMock(ReinitableConfigInterface::class);
        $reinitableConfig = $scopeConfig;
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path): string {
                return match ($path) {
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

        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn('website-a');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willReturn($website);

        $settingChecker = $this->createMock(SettingChecker::class);
        $settingChecker->method('isReadOnly')->willReturn(false);

        return new ConfigSaveValidator(
            $scopeConfig,
            $storeManager,
            $this->createMock(ConfigFactory::class),
            $settingChecker,
            new CustomDomain(),
            $this->createStub(CurrentSettingsTester::class)
        );
    }

    private function createResourceConnection(?AdapterInterface &$connection = null): ResourceConnection
    {
        $connection = $this->createMock(AdapterInterface::class);
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);

        return $resourceConnection;
    }

    private function createConfigSubject(string $section): MagentoConfig
    {
        return new class ($section) extends MagentoConfig {
            public function __construct(private readonly string $section)
            {
            }

            public function getSection(): string
            {
                return $this->section;
            }

            public function getWebsite(): string
            {
                return '1';
            }

            public function getStore(): ?string
            {
                return null;
            }

            public function getData($key = '', $index = null)
            {
                return $key === 'groups'
                    ? ['protected_forms' => ['fields' => ['contact_form' => ['value' => '0']]]]
                    : null;
            }
        };
    }
}
