<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Integration\Controller;

use Magento\Customer\Model\Session;
use Magento\Customer\Controller\Ajax\Login as AjaxLogin;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Event\ManagerInterface;
use Magento\TestFramework\TestCase\AbstractController;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\Validation\VerifierInterface;
use PrivateCaptcha\PrivateCaptcha\Observer\ValidatePredispatch;

final class CustomerLoginProtectionTest extends AbstractController
{
    private Session $customerSession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerSession = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(Session::class);
        if ($this->customerSession->isLoggedIn()) {
            $this->customerSession->logout();
        }
        $this->customerSession->unsetData('username');
    }

    protected function tearDown(): void
    {
        if (isset($this->customerSession)) {
            if ($this->customerSession->isLoggedIn()) {
                $this->customerSession->logout();
            }
            $this->customerSession->unsetData('username');
        }
        $this->restoreDependencies();
        parent::tearDown();
        $this->removeIgnitionHandlers();
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_login 0
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 0
     */
    public function testDisabledLoginRemainsNativeAndDoesNotVerify(): void
    {
        $verifier = new CustomerLoginProtectionTestVerifier(false);
        $this->replaceDependencies($verifier);

        $this->submitNormal($this->normalPost('customer@example.com', 'password', false));

        self::assertSame([], $verifier->calls);
        self::assertTrue($this->customerSession->isLoggedIn());
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_login 0
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 0
     */
    public function testDisabledAjaxLoginRemainsNativeAndDoesNotVerify(): void
    {
        $verifier = new CustomerLoginProtectionTestVerifier(false);
        $this->replaceDependencies($verifier);

        $this->executeAjax($this->ajaxPost('customer@example.com', 'password', false));

        self::assertSame([], $verifier->calls);
        self::assertTrue($this->customerSession->isLoggedIn());
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_login 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key
     * @magentoConfigFixture current_store customer/captcha/enable 0
     */
    public function testEnabledFlagWithoutCredentialsLeavesLoginNativeAndDoesNotVerify(): void
    {
        $verifier = new CustomerLoginProtectionTestVerifier(false);
        $this->replaceDependencies($verifier);

        $this->submitNormal($this->normalPost('customer@example.com', 'password', false));

        self::assertSame([], $verifier->calls);
        self::assertTrue($this->customerSession->isLoggedIn());
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_login 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key
     */
    public function testMissingApiKeyDoesNotRenderALoginWidget(): void
    {
        $this->getRequest()->setMethod(Http::METHOD_GET);
        $this->dispatch('customer/account/login');

        $body = $this->getResponse()->getBody();
        self::assertStringNotContainsString('private-captcha-container', $body);
        self::assertStringNotContainsString(Config::DEFAULT_SCRIPT_URL, $body);
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_login 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 0
     */
    public function testMissingNormalSolutionPreventsLoginAndPersistsOnlyUsername(): void
    {
        $email = 'customer@example.com';
        $verifier = new CustomerLoginProtectionTestVerifier(true);
        $this->replaceDependencies($verifier);

        $this->submitNormal($this->normalPost($email, 'password', false));

        self::assertSame([], $verifier->calls);
        self::assertFalse($this->customerSession->isLoggedIn());
        self::assertSame($email, $this->customerSession->getData('username'));
        self::assertNull($this->customerSession->getData('password'));
        $this->assertLoginRedirect();
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_login 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 0
     */
    public function testInvalidNormalSolutionPreventsLogin(): void
    {
        $verifier = new CustomerLoginProtectionTestVerifier(false);
        $this->replaceDependencies($verifier);

        $this->submitNormal($this->normalPost('customer@example.com', 'password', true));

        self::assertSame([['solution', Config::FORM_CUSTOMER_LOGIN]], $verifier->calls);
        self::assertFalse($this->customerSession->isLoggedIn());
        self::assertSame('customer@example.com', $this->customerSession->getData('username'));
        self::assertNull($this->customerSession->getData('password'));
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_login 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 0
     */
    public function testValidNormalSolutionPreservesNativeLoginSuccess(): void
    {
        $verifier = new CustomerLoginProtectionTestVerifier(true);
        $this->replaceDependencies($verifier);

        $this->submitNormal($this->normalPost('customer@example.com', 'password', true));

        self::assertSame([['solution', Config::FORM_CUSTOMER_LOGIN]], $verifier->calls);
        self::assertTrue($this->customerSession->isLoggedIn());
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_login 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 1
     * @magentoConfigFixture current_store customer/captcha/forms user_login
     * @magentoConfigFixture current_store customer/captcha/mode always
     */
    public function testValidPrivateAndNativeCaptchaSolutionsPreserveLoginSuccess(): void
    {
        $verifier = new CustomerLoginProtectionTestVerifier(true);
        $this->replaceDependencies($verifier);
        $this->customerSession->setData('user_login_word', [
            'data' => 'NativeCaptcha',
            'words' => 'NativeCaptcha',
            'expires' => time() + 3600,
        ]);
        $post = $this->normalPost('customer@example.com', 'password', true);
        $post['captcha'] = ['user_login' => 'NativeCaptcha'];

        $this->submitNormal($post);

        self::assertSame([['solution', Config::FORM_CUSTOMER_LOGIN]], $verifier->calls);
        self::assertTrue($this->customerSession->isLoggedIn());
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_login 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 0
     */
    public function testVerifiedNativeNormalLoginFailureRetainsOnlyUsername(): void
    {
        $verifier = new CustomerLoginProtectionTestVerifier(true);
        $this->replaceDependencies($verifier);

        $this->submitNormal($this->normalPost('customer@example.com', 'wrong-password', true));

        self::assertSame([['solution', Config::FORM_CUSTOMER_LOGIN]], $verifier->calls);
        self::assertFalse($this->customerSession->isLoggedIn());
        self::assertSame('customer@example.com', $this->customerSession->getData('username'));
        self::assertNull($this->customerSession->getData('password'));
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_login 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 0
     */
    public function testMissingAjaxSolutionPreventsLoginWithNativeJsonContract(): void
    {
        $verifier = new CustomerLoginProtectionTestVerifier(true);
        $this->replaceDependencies($verifier);

        $this->submitAjax($this->ajaxPost('customer@example.com', 'password', false));

        self::assertSame([], $verifier->calls);
        self::assertFalse($this->customerSession->isLoggedIn());
        $this->assertPrivateCaptchaJsonFailure();
        self::assertSame(
            'application/json',
            $this->getResponse()->getHeader('Content-Type')->getFieldValue()
        );
        self::assertFalse($this->getResponse()->getHeader('Location'));
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_login 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 0
     */
    public function testInvalidAjaxSolutionPreventsLoginWithNativeJsonContract(): void
    {
        $verifier = new CustomerLoginProtectionTestVerifier(false);
        $this->replaceDependencies($verifier);

        $this->submitAjax($this->ajaxPost('customer@example.com', 'password', true));

        self::assertSame([['solution', Config::FORM_CUSTOMER_LOGIN]], $verifier->calls);
        self::assertFalse($this->customerSession->isLoggedIn());
        $this->assertPrivateCaptchaJsonFailure();
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_login 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 0
     */
    public function testMalformedAjaxPayloadPreventsLoginWithNativeJsonContract(): void
    {
        $verifier = new CustomerLoginProtectionTestVerifier(true);
        $this->replaceDependencies($verifier);

        $this->submitAjaxRaw('{');

        self::assertSame([], $verifier->calls);
        self::assertFalse($this->customerSession->isLoggedIn());
        $this->assertPrivateCaptchaJsonFailure();
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_login 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 0
     */
    public function testNonXhrAjaxRequestCannotAuthenticate(): void
    {
        $verifier = new CustomerLoginProtectionTestVerifier(true);
        $this->replaceDependencies($verifier);
        $this->getRequest()->setMethod(Http::METHOD_POST);
        $this->getRequest()->setContent(json_encode(
            $this->ajaxPost('customer@example.com', 'password', true),
            JSON_THROW_ON_ERROR
        ));

        $this->dispatch('customer/ajax/login');

        self::assertSame([['solution', Config::FORM_CUSTOMER_LOGIN]], $verifier->calls);
        self::assertFalse($this->customerSession->isLoggedIn());
        self::assertSame(400, $this->getResponse()->getHttpResponseCode());
        self::assertFalse($this->getResponse()->getHeader('Location'));
        self::assertStringNotContainsString('password', $this->getResponse()->getBody());
        self::assertStringNotContainsString('solution', $this->getResponse()->getBody());
        self::assertStringNotContainsString('api_token', $this->getResponse()->getBody());
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_login 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 0
     */
    public function testValidAjaxSolutionPreservesNativeLoginSuccess(): void
    {
        $verifier = new CustomerLoginProtectionTestVerifier(true);
        $this->replaceDependencies($verifier);

        $response = $this->executeAjax($this->ajaxPost('customer@example.com', 'password', true));

        self::assertSame([['solution', Config::FORM_CUSTOMER_LOGIN]], $verifier->calls);
        self::assertTrue($this->customerSession->isLoggedIn());
        self::assertSame([
            'errors' => false,
            'message' => 'Login successful.',
        ], $response);
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_login 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testEnabledLoginPageRendersOnePublicWidgetAndConsumesUsername(): void
    {
        $email = 'login-refill@example.test';
        $this->customerSession->setData('username', $email);
        $this->getRequest()->setMethod(Http::METHOD_GET);
        $this->dispatch('customer/account/login');

        $body = html_entity_decode($this->getResponse()->getBody(), ENT_QUOTES, 'UTF-8');
        self::assertSame(1, substr_count($body, 'class="private-captcha"'));
        self::assertStringContainsString('name="login[username]" value="' . $email . '"', $body);
        self::assertStringContainsString('private-captcha-login-popup', $body);
        self::assertNull($this->customerSession->getData('username'));
    }

    /**
     * @return array<string, array<string, string>|string>
     */
    private function normalPost(string $email, string $password, bool $withSolution): array
    {
        $post = [
            'login' => [
                'username' => $email,
                'password' => $password,
            ],
            'api_token' => 'token',
            'success_url' => 'https://attacker.example.test/success',
        ];
        if ($withSolution) {
            $post['private-captcha-solution'] = 'solution';
        }

        return $post;
    }

    /**
     * @return array<string, string>
     */
    private function ajaxPost(string $email, string $password, bool $withSolution): array
    {
        $post = [
            'username' => $email,
            'password' => $password,
            'captcha_form_id' => 'user_login',
            'captcha_string' => '',
            'api_token' => 'token',
            'success_url' => 'https://attacker.example.test/success',
        ];
        if ($withSolution) {
            $post['private-captcha-solution'] = 'solution';
        }

        return $post;
    }

    /**
     * @param array<string, array<string, string>|string> $post
     */
    private function submitNormal(array $post): void
    {
        $this->getRequest()->setMethod(Http::METHOD_POST)->setPostValue($post);
        $this->dispatch('customer/account/loginPost');
    }

    /**
     * @param array<string, string> $post
     */
    private function submitAjax(array $post): void
    {
        $this->prepareAjaxRequest($post);
        $this->dispatch('customer/ajax/login');
    }

    private function submitAjaxRaw(string $content): void
    {
        $this->getRequest()->setMethod(Http::METHOD_POST);
        $this->getRequest()->getHeaders()->addHeaderLine('X-Requested-With', 'XMLHttpRequest');
        $this->getRequest()->setContent($content);
        $this->dispatch('customer/ajax/login');
    }

    /**
     * @param array<string, string> $post
     * @return array<string, bool|string>
     */
    private function executeAjax(array $post): array
    {
        $this->prepareAjaxRequest($post);
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $controller = $objectManager->create(AjaxLogin::class);
        $objectManager->get(ManagerInterface::class)->dispatch('controller_action_predispatch', [
            'controller_action' => $controller,
            'request' => $this->getRequest(),
        ]);
        $result = $controller->execute();
        $result->renderResult($this->getResponse());

        return $this->decodeResponse();
    }

    /**
     * @param array<string, string> $post
     */
    private function prepareAjaxRequest(array $post): void
    {
        $this->getRequest()->setMethod(Http::METHOD_POST);
        $this->getRequest()->getHeaders()->addHeaderLine('X-Requested-With', 'XMLHttpRequest');
        $this->getRequest()->setContent(json_encode($post, JSON_THROW_ON_ERROR));
    }

    private function assertLoginRedirect(): void
    {
        self::assertTrue($this->getResponse()->isRedirect());
        self::assertStringContainsString(
            'customer/account/login',
            $this->getResponse()->getHeader('Location')->getFieldValue()
        );
    }

    private function assertPrivateCaptchaJsonFailure(): void
    {
        self::assertSame([
            'errors' => true,
            'message' => 'Private Captcha verification failed. Please try again.',
        ], $this->decodeResponse());
    }

    /**
     * @return array<string, bool|string>
     */
    private function decodeResponse(): array
    {
        return json_decode($this->getResponse()->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function replaceDependencies(CustomerLoginProtectionTestVerifier $verifier): void
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $objectManager->removeSharedInstance(ValidatePredispatch::class, true);
        $objectManager->addSharedInstance($verifier, VerifierInterface::class, true);
    }

    private function restoreDependencies(): void
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $objectManager->removeSharedInstance(ValidatePredispatch::class, true);
        $objectManager->removeSharedInstance(VerifierInterface::class, true);
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

final class CustomerLoginProtectionTestVerifier implements VerifierInterface
{
    /** @var array<int, array{string, string}> */
    public array $calls = [];

    public function __construct(
        private readonly bool $result
    ) {
    }

    public function isValid(string $solution, int $storeId, string $form): bool
    {
        $this->calls[] = [$solution, $form];

        return $this->result;
    }
}
