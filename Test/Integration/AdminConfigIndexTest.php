<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Integration;

use Magento\Authorization\Test\Fixture\Role;
use Magento\Backend\Model\Auth;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Authorization;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\ObjectManager;
use Magento\TestFramework\TestCase\AbstractController;
use Magento\User\Test\Fixture\User;

/**
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
final class AdminConfigIndexTest extends AbstractController
{
    private ?Auth $auth = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->_objectManager->get(UrlInterface::class)->turnOffSecretKey();
        ObjectManager::getInstance()->removeSharedInstance(Authorization::class);
        $this->auth = $this->_objectManager->get(Auth::class);
        $this->auth->login('private_captcha_admin', 'password1');
    }

    protected function tearDown(): void
    {
        if ($this->auth !== null) {
            $this->auth->getAuthStorage()->destroy(['send_expire_cookie' => false]);
        }
        $this->_objectManager->get(UrlInterface::class)->turnOnSecretKey();

        parent::tearDown();
        $this->removeIgnitionHandlers();
    }

    #[DataFixture(Role::class, ['resources' => ['PrivateCaptcha_PrivateCaptcha::config']], 'restricted_role')]
    #[DataFixture(User::class, [
        'username' => 'private_captcha_admin',
        'password' => 'password1',
        'role_id' => '$restricted_role.role_id$',
    ])]
    public function testRestrictedAdministratorCanOpenSectionlessConfigIndex(): void
    {
        $this->dispatch('backend/admin/system_config/index');

        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
    }

    private function removeIgnitionHandlers(): void
    {
        $errorHandler = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        if (is_array($errorHandler) && $errorHandler[0] instanceof \Swissup\Ignition\Model\Ignition) {
            restore_error_handler();
        }

        $exceptionHandler = set_exception_handler(static function (): void {
        });
        restore_exception_handler();
        if (is_array($exceptionHandler) && $exceptionHandler[0] instanceof \Swissup\Ignition\Model\Ignition) {
            restore_exception_handler();
        }
    }
}
