<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Integration\Controller;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Message\MessageInterface;
use Magento\TestFramework\Mail\Template\TransportBuilderMock;
use Magento\TestFramework\TestCase\AbstractController;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\Validation\VerifierInterface;
use PrivateCaptcha\PrivateCaptcha\Observer\ValidatePredispatch;

final class ForgotPasswordPostTest extends AbstractController
{
    private Session $customerSession;

    private TransportBuilderMock $transportBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->customerSession = $objectManager->get(Session::class);
        $this->transportBuilder = $objectManager->get(TransportBuilderMock::class);
        $this->customerSession->unsetData('forgotten_email');
        $this->transportBuilder->clean();
    }

    protected function tearDown(): void
    {
        if (isset($this->customerSession)) {
            $this->customerSession->unsetData('forgotten_email');
        }
        if (isset($this->transportBuilder)) {
            $this->transportBuilder->clean();
        }
        $this->restoreDependencies();
        parent::tearDown();
        $this->removeIgnitionHandlers();
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/forgot_password 0
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 0
     * @magentoConfigFixture current_store customer/password/limit_password_reset_requests_method 0
     */
    public function testDisabledForgotPasswordRemainsNativeAndDoesNotVerify(): void
    {
        $verifier = new ForgotPasswordPostTestVerifier(false);
        $this->replaceDependencies($verifier);

        try {
            $this->submit($this->requestPost('customer@example.com', false));

            self::assertSame([], $verifier->calls);
            self::assertNotNull($this->transportBuilder->getSentMessage());
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/forgot_password 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testEnabledForgotPasswordPageRendersOnePublicWidget(): void
    {
        $this->getRequest()->setMethod('GET');
        $this->dispatch('customer/account/forgotpassword');

        $body = $this->getResponse()->getBody();
        self::assertSame(1, substr_count($body, 'class="private-captcha"'));
        self::assertStringNotContainsString('private-api-key', $body);
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     */
    public function testForgotPasswordPageConsumesStoredEmailOnce(): void
    {
        $email = 'forgot-refill@example.test';
        $this->customerSession->setData('forgotten_email', $email);
        $this->getRequest()->setMethod(Http::METHOD_GET);
        $this->dispatch('customer/account/forgotpassword');

        self::assertStringContainsString(
            'value="' . $email . '"',
            html_entity_decode($this->getResponse()->getBody(), ENT_QUOTES, 'UTF-8')
        );
        self::assertNull($this->customerSession->getData('forgotten_email'));
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/forgot_password 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testMissingSolutionPreventsMailAndRefillsOnlyEmailOnce(): void
    {
        $email = 'forgot-missing@example.test';
        $verifier = new ForgotPasswordPostTestVerifier(true);
        $this->replaceDependencies($verifier);

        try {
            $this->submit($this->requestPost($email, false));

            self::assertSame([], $verifier->calls);
            self::assertNull($this->transportBuilder->getSentMessage());
            $this->assertForgotPasswordRedirect();
            self::assertSame($email, $this->customerSession->getData('forgotten_email'));
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/forgot_password 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testInvalidSolutionPreventsMailAndRefillsOnlyEmail(): void
    {
        $email = 'forgot-invalid@example.test';
        $verifier = new ForgotPasswordPostTestVerifier(false);
        $this->replaceDependencies($verifier);

        try {
            $this->submit($this->requestPost($email, true));

            self::assertSame([['solution', Config::FORM_FORGOT_PASSWORD]], $verifier->calls);
            self::assertNull($this->transportBuilder->getSentMessage());
            self::assertSame($email, $this->customerSession->getData('forgotten_email'));
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/forgot_password 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 0
     * @magentoConfigFixture current_store customer/password/limit_password_reset_requests_method 0
     */
    public function testValidSolutionSendsResetMailForAnExistingCustomer(): void
    {
        $email = 'customer@example.com';
        $verifier = new ForgotPasswordPostTestVerifier(true);
        $this->replaceDependencies($verifier);

        try {
            $this->submit($this->requestPost($email, true));

            self::assertSame([['solution', Config::FORM_FORGOT_PASSWORD]], $verifier->calls);
            self::assertNotNull($this->transportBuilder->getSentMessage());
            $this->assertSuccessMessage($email);
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/forgot_password 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 0
     * @magentoConfigFixture current_store customer/password/limit_password_reset_requests_method 0
     */
    public function testValidSolutionRetainsAntiEnumerationForANonexistentCustomer(): void
    {
        $email = 'forgot-nonexistent@example.test';
        $verifier = new ForgotPasswordPostTestVerifier(true);
        $this->replaceDependencies($verifier);

        try {
            $this->submit($this->requestPost($email, true));

            self::assertSame([['solution', Config::FORM_FORGOT_PASSWORD]], $verifier->calls);
            self::assertNull($this->transportBuilder->getSentMessage());
            $this->assertSuccessMessage($email);
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/forgot_password 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 0
     */
    public function testNativeInvalidEmailAfterValidSolutionRefillsOnlyEmail(): void
    {
        $email = 'invalid-email';
        $verifier = new ForgotPasswordPostTestVerifier(true);
        $this->replaceDependencies($verifier);

        try {
            $this->submit($this->requestPost($email, true));

            self::assertSame([['solution', Config::FORM_FORGOT_PASSWORD]], $verifier->calls);
            self::assertNull($this->transportBuilder->getSentMessage());
            self::assertSame($email, $this->customerSession->getData('forgotten_email'));
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/forgot_password 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 1
     * @magentoConfigFixture current_store customer/captcha/forms user_forgotpassword
     * @magentoConfigFixture current_store customer/captcha/mode always
     * @magentoConfigFixture current_store customer/password/limit_password_reset_requests_method 0
     */
    public function testValidPrivateAndNativeCaptchaSolutionsSendOneResetMail(): void
    {
        $email = 'customer@example.com';
        $verifier = new ForgotPasswordPostTestVerifier(true);
        $this->replaceDependencies($verifier);
        $this->customerSession->setData('user_forgotpassword_word', [
            'data' => 'NativeCaptcha',
            'words' => 'NativeCaptcha',
            'expires' => time() + 3600,
        ]);
        $post = $this->requestPost($email, true);
        $post['captcha'] = ['user_forgotpassword' => 'NativeCaptcha'];

        try {
            $this->submit($post);

            self::assertSame([['solution', Config::FORM_FORGOT_PASSWORD]], $verifier->calls);
            self::assertNotNull($this->transportBuilder->getSentMessage());
            $this->assertSuccessMessage($email);
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/forgot_password 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testResetCompletionRouteDoesNotReachVerifier(): void
    {
        $verifier = new ForgotPasswordPostTestVerifier(false);
        $this->replaceDependencies($verifier);

        try {
            $this->getRequest()->setMethod('GET');
            $this->dispatch('customer/account/createPassword');

            self::assertSame([], $verifier->calls);
            self::assertStringNotContainsString('class="private-captcha"', $this->getResponse()->getBody());
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @return array<string, string>
     */
    private function requestPost(string $email, bool $withSolution): array
    {
        $post = [
            'email' => $email,
            'api_token' => 'token',
            'success_url' => 'https://attacker.example.test/success',
            'error_url' => 'https://attacker.example.test/error',
        ];

        if ($withSolution) {
            $post['private-captcha-solution'] = 'solution';
        }

        return $post;
    }

    /**
     * @param array<string, string|array<string, string>> $post Forgot-password request payload.
     */
    private function submit(array $post): void
    {
        $this->getRequest()->setMethod(Http::METHOD_POST)->setPostValue($post);
        $this->dispatch('customer/account/forgotPasswordPost');
    }

    private function assertForgotPasswordRedirect(): void
    {
        self::assertTrue($this->getResponse()->isRedirect());
        self::assertStringContainsString(
            'customer/account/forgotpassword',
            $this->getResponse()->getHeader('Location')->getFieldValue()
        );
    }

    private function assertSuccessMessage(string $email): void
    {
        $this->assertSessionMessages(
            $this->equalTo([
                __(
                    'If there is an account associated with %1 you will receive an email with a link to reset your password.',
                    $email
                ),
            ]),
            MessageInterface::TYPE_SUCCESS
        );
    }

    private function replaceDependencies(ForgotPasswordPostTestVerifier $verifier): void
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

final class ForgotPasswordPostTestVerifier implements VerifierInterface
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
