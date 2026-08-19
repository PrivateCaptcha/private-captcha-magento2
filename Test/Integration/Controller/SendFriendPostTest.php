<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Integration\Controller;

use Laminas\Stdlib\Parameters;
use Magento\Catalog\Model\Session as CatalogSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Message\MessageInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\SendFriend\Helper\Data as SendFriendHelper;
use Magento\TestFramework\Mail\Template\TransportBuilderMock;
use Magento\TestFramework\TestCase\AbstractController;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\Validation\VerifierInterface;
use PrivateCaptcha\PrivateCaptcha\Observer\ValidatePredispatch;

final class SendFriendPostTest extends AbstractController
{
    private CatalogSession $catalogSession;

    private CustomerSession $customerSession;

    private TransportBuilderMock $transportBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->catalogSession = $objectManager->get(CatalogSession::class);
        $this->customerSession = $objectManager->get(CustomerSession::class);
        $this->transportBuilder = $objectManager->get(TransportBuilderMock::class);
        $this->catalogSession->unsetData('sendfriend_form_data');
        $this->transportBuilder->clean();
        if ($this->customerSession->isLoggedIn()) {
            $this->customerSession->logout();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->catalogSession)) {
            $this->catalogSession->unsetData('sendfriend_form_data');
        }
        if (isset($this->customerSession) && $this->customerSession->isLoggedIn()) {
            $this->customerSession->logout();
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
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoConfigFixture current_store sendfriend/email/enabled 1
     * @magentoConfigFixture current_store sendfriend/email/allow_guest 1
     * @magentoConfigFixture current_store customer/captcha/enable 0
     * @magentoConfigFixture base_website private_captcha/protected_forms/email_to_friend 0
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testDisabledEmailToFriendSubmissionRemainsNativeAndDoesNotVerify(): void
    {
        $verifier = new SendFriendPostTestVerifier(false);
        $this->replaceDependencies($verifier);

        try {
            $this->submit($this->requestPost(false));

            self::assertSame([], $verifier->calls);
            self::assertNotNull($this->transportBuilder->getSentMessage());
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoConfigFixture current_store sendfriend/email/enabled 1
     * @magentoConfigFixture current_store sendfriend/email/allow_guest 1
     * @magentoConfigFixture base_website private_captcha/protected_forms/email_to_friend 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testEnabledEmailToFriendPageRendersOnePublicWidget(): void
    {
        $this->getRequest()->setMethod(Http::METHOD_GET);
        $this->dispatch('sendfriend/product/send/id/1');

        $body = $this->getResponse()->getBody();
        self::assertSame(1, substr_count($body, 'class="private-captcha"'));
        self::assertStringNotContainsString('private-api-key', $body);
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoConfigFixture current_store sendfriend/email/enabled 1
     * @magentoConfigFixture current_store sendfriend/email/allow_guest 1
     * @magentoConfigFixture current_store customer/captcha/enable 0
     * @magentoConfigFixture base_website private_captcha/protected_forms/email_to_friend 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testMissingSolutionPreventsMailAndPersistsOnlySafeState(): void
    {
        $verifier = new SendFriendPostTestVerifier(true);
        $this->replaceDependencies($verifier);

        try {
            $this->submit($this->requestPost(false));

            self::assertSame([], $verifier->calls);
            self::assertNull($this->transportBuilder->getSentMessage());
            $this->assertSafePersistedState();
            $this->assertSendFriendRedirect();
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoConfigFixture current_store sendfriend/email/enabled 1
     * @magentoConfigFixture current_store sendfriend/email/allow_guest 1
     * @magentoConfigFixture current_store customer/captcha/enable 0
     * @magentoConfigFixture base_website private_captcha/protected_forms/email_to_friend 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testInvalidSolutionPreventsMailAndPersistsOnlySafeState(): void
    {
        $verifier = new SendFriendPostTestVerifier(false);
        $this->replaceDependencies($verifier);

        try {
            $this->submit($this->requestPost(true));

            self::assertSame([['solution', Config::FORM_EMAIL_TO_FRIEND]], $verifier->calls);
            self::assertNull($this->transportBuilder->getSentMessage());
            $this->assertSafePersistedState();
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoConfigFixture current_store sendfriend/email/enabled 1
     * @magentoConfigFixture current_store sendfriend/email/allow_guest 1
     * @magentoConfigFixture current_store customer/captcha/enable 0
     * @magentoConfigFixture base_website private_captcha/protected_forms/email_to_friend 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testValidSolutionSendsMailOnce(): void
    {
        $verifier = new SendFriendPostTestVerifier(true);
        $this->replaceDependencies($verifier);

        try {
            $this->submit($this->requestPost(true));

            self::assertSame([['solution', Config::FORM_EMAIL_TO_FRIEND]], $verifier->calls);
            self::assertNotNull($this->transportBuilder->getSentMessage());
            self::assertNull($this->catalogSession->getData('sendfriend_form_data'));
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture current_store sendfriend/email/enabled 1
     * @magentoConfigFixture current_store sendfriend/email/allow_guest 0
     * @magentoConfigFixture current_store customer/captcha/enable 0
     * @magentoConfigFixture base_website private_captcha/protected_forms/email_to_friend 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testAuthenticatedCustomerRequiresAndUsesVerification(): void
    {
        $verifier = new SendFriendPostTestVerifier(true);
        $this->replaceDependencies($verifier);
        $this->customerSession->setCustomerId(1);

        try {
            $this->submit($this->requestPost(true));

            self::assertSame([['solution', Config::FORM_EMAIL_TO_FRIEND]], $verifier->calls);
            self::assertNotNull($this->transportBuilder->getSentMessage());
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoConfigFixture current_store sendfriend/email/enabled 1
     * @magentoConfigFixture current_store sendfriend/email/allow_guest 1
     * @magentoConfigFixture current_store sendfriend/email/check_by 0
     * @magentoConfigFixture current_store sendfriend/email/max_per_hour 1
     * @magentoConfigFixture current_store customer/captcha/enable 0
     * @magentoConfigFixture base_website private_captcha/protected_forms/email_to_friend 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testValidSolutionRetainsNativeRateLimitBehavior(): void
    {
        $verifier = new SendFriendPostTestVerifier(true);
        $this->replaceDependencies($verifier);
        $cookieManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->get(CookieManagerInterface::class);
        $cookieManager->setPublicCookie(SendFriendHelper::COOKIE_NAME, (string) time());

        try {
            $this->submit($this->requestPost(true));

            self::assertSame([['solution', Config::FORM_EMAIL_TO_FRIEND]], $verifier->calls);
            self::assertNull($this->transportBuilder->getSentMessage());
            $this->assertSessionMessages(
                $this->equalTo(['You&#039;ve met your limit of 1 sends in an hour.']),
                MessageInterface::TYPE_ERROR
            );
        } finally {
            $cookieManager->deleteCookie(SendFriendHelper::COOKIE_NAME);
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoConfigFixture current_store sendfriend/email/enabled 1
     * @magentoConfigFixture current_store sendfriend/email/allow_guest 1
     * @magentoConfigFixture current_store sendfriend/email/check_by 1
     * @magentoConfigFixture current_store sendfriend/email/max_per_hour 1
     * @magentoConfigFixture current_store customer/captcha/enable 0
     * @magentoConfigFixture base_website private_captcha/protected_forms/email_to_friend 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testFailedVerificationDoesNotConsumeTheNativeRateLimit(): void
    {
        $verifier = new SendFriendPostTestSequenceVerifier([false, true]);
        $this->replaceDependencies($verifier);
        $this->getRequest()->setServer(new Parameters(['REMOTE_ADDR' => '127.0.0.1']));

        try {
            $this->submit($this->requestPost(true));
            self::assertNull($this->transportBuilder->getSentMessage());
            $this->removeIgnitionHandlers();
            \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
                ->get(ActionFlag::class)
                ->set('', ActionInterface::FLAG_NO_DISPATCH, false);

            $this->submit($this->requestPost(true));

            self::assertSame([
                ['solution', Config::FORM_EMAIL_TO_FRIEND],
                ['solution', Config::FORM_EMAIL_TO_FRIEND],
            ], $verifier->calls);
            self::assertNotNull($this->transportBuilder->getSentMessage());
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoConfigFixture current_store sendfriend/email/enabled 1
     * @magentoConfigFixture current_store sendfriend/email/allow_guest 1
     * @magentoConfigFixture current_store customer/captcha/enable 0
     * @magentoConfigFixture base_website private_captcha/protected_forms/email_to_friend 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testNativeValidationFailureAfterValidSolutionPersistsOnlySafeState(): void
    {
        $verifier = new SendFriendPostTestVerifier(true);
        $this->replaceDependencies($verifier);
        $post = $this->requestPost(true);
        $post['sender']['email'] = 'invalid-email';
        $expectedState = $this->safePost();
        $expectedState['sender']['email'] = 'invalid-email';

        try {
            $this->submit($post);

            self::assertSame([['solution', Config::FORM_EMAIL_TO_FRIEND]], $verifier->calls);
            self::assertNull($this->transportBuilder->getSentMessage());
            $this->assertSafePersistedState($expectedState);
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoConfigFixture current_store sendfriend/email/enabled 1
     * @magentoConfigFixture current_store sendfriend/email/allow_guest 0
     * @magentoConfigFixture base_website private_captcha/protected_forms/email_to_friend 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testIneligibleGuestKeepsNativeAuthenticationBehaviorWithoutVerification(): void
    {
        $verifier = new SendFriendPostTestVerifier(false);
        $this->replaceDependencies($verifier);

        try {
            $this->submit($this->requestPost(true));

            self::assertSame([], $verifier->calls);
            self::assertNull($this->transportBuilder->getSentMessage());
            self::assertTrue($this->getResponse()->isRedirect());
            self::assertStringContainsString(
                'customer/account/login',
                $this->getResponse()->getHeader('Location')->getFieldValue()
            );
            $this->assertEmptyOrSafePersistedState();
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoConfigFixture current_store sendfriend/email/enabled 0
     * @magentoConfigFixture base_website private_captcha/protected_forms/email_to_friend 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testNativeDisabledEmailToFriendDoesNotVerify(): void
    {
        $verifier = new SendFriendPostTestVerifier(false);
        $this->replaceDependencies($verifier);

        try {
            $this->submit($this->requestPost(true));

            self::assertSame([], $verifier->calls);
            $this->assert404NotFound();
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @return array<string, array<string, string|array<int, string>>>
     */
    private function safePost(): array
    {
        return [
            'sender' => [
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.test',
                'message' => 'Hello',
            ],
            'recipients' => [
                'name' => [0 => 'Charles Babbage'],
                'email' => [0 => 'charles@example.test'],
            ],
        ];
    }

    /**
     * @return array<string, string|array<string, string|array<int, string>>>
     */
    private function requestPost(bool $withSolution): array
    {
        $post = $this->safePost() + [
            'form_key' => (string) \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
                ->get(FormKey::class)
                ->getFormKey(),
            'api_token' => 'token',
            'return_url' => 'https://attacker.example.test',
        ];

        if ($withSolution) {
            $post['private-captcha-solution'] = 'solution';
        }

        return $post;
    }

    /**
     * @param array<string, string|array<string, string|array<int, string>>> $post SendFriend request payload.
     */
    private function submit(array $post): void
    {
        $this->getRequest()->setMethod(Http::METHOD_POST)->setPostValue($post);
        $this->dispatch('sendfriend/product/sendmail/id/1');
    }

    /**
     * @param array<string, array<string, string|array<int, string>>>|null $expectedState Expected native SendFriend state.
     */
    private function assertSafePersistedState(?array $expectedState = null): void
    {
        self::assertSame(
            $expectedState ?? $this->safePost(),
            $this->catalogSession->getData('sendfriend_form_data')
        );
    }

    private function assertEmptyOrSafePersistedState(): void
    {
        $state = $this->catalogSession->getData('sendfriend_form_data');
        self::assertTrue($state === null || $state === [] || $state === $this->safePost());
    }

    private function assertSendFriendRedirect(): void
    {
        self::assertTrue($this->getResponse()->isRedirect());
        self::assertStringContainsString(
            'sendfriend/product/send/id/1',
            $this->getResponse()->getHeader('Location')->getFieldValue()
        );
    }

    private function replaceDependencies(SendFriendPostTestVerifier $verifier): void
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

class SendFriendPostTestVerifier implements VerifierInterface
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

final class SendFriendPostTestSequenceVerifier extends SendFriendPostTestVerifier
{
    /** @var array<int, array{string, string}> */
    public array $calls = [];

    /** @var array<int, bool> */
    private array $results;

    /**
     * @param array<int, bool> $results
     */
    public function __construct(array $results)
    {
        parent::__construct(false);
        $this->results = $results;
    }

    public function isValid(string $solution, int $storeId, string $form): bool
    {
        $this->calls[] = [$solution, $form];

        return array_shift($this->results) ?? false;
    }
}
