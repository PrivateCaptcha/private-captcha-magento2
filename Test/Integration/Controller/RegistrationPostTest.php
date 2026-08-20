<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Integration\Controller;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Mail\Template\TransportBuilderMock;
use Magento\TestFramework\TestCase\AbstractController;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\Validation\VerifierInterface;
use PrivateCaptcha\PrivateCaptcha\Observer\ValidatePredispatch;

final class RegistrationPostTest extends AbstractController
{
    private CustomerRepositoryInterface $customerRepository;

    private Session $customerSession;

    private TransportBuilderMock $transportBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->customerRepository = $objectManager->get(CustomerRepositoryInterface::class);
        $this->customerSession = $objectManager->get(Session::class);
        $this->transportBuilder = $objectManager->get(TransportBuilderMock::class);
        $this->customerSession->unsetData('customer_form_data');
        $this->transportBuilder->clean();
    }

    protected function tearDown(): void
    {
        if (isset($this->customerSession)) {
            $this->customerSession->unsetData('customer_form_data');
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
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_registration 0
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_website customer/create_account/confirm 0
     */
    public function testDisabledRegistrationRemainsNativeAndDoesNotVerify(): void
    {
        $email = 'registration-disabled@example.test';
        $verifier = new RegistrationPostTestVerifier(false);
        $this->replaceDependencies($verifier);

        $this->submit($this->requestPost($email, false));

        self::assertSame([], $verifier->calls);
        $this->customerRepository->get($email);
        self::assertNotNull($this->transportBuilder->getSentMessage());
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_registration 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testEnabledRegistrationPageRendersOnePublicWidget(): void
    {
        $this->getRequest()->setMethod('GET');
        $this->dispatch('customer/account/create');

        $body = $this->getResponse()->getBody();
        self::assertSame(1, substr_count($body, 'class="private-captcha"'));
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_registration 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testMissingSolutionPreventsAccountAndPersistsOnlySafeState(): void
    {
        $email = 'registration-missing@example.test';
        $verifier = new RegistrationPostTestVerifier(true);
        $this->replaceDependencies($verifier);

        $this->submit($this->requestPost($email, false));

        self::assertSame([], $verifier->calls);
        $this->assertCustomerDoesNotExist($email);
        self::assertNull($this->transportBuilder->getSentMessage());
        $this->assertSafeCustomerFormData($email);
        $this->assertRegistrationRedirect();
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_registration 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testInvalidSolutionPreventsAccountAndEmail(): void
    {
        $email = 'registration-invalid@example.test';
        $verifier = new RegistrationPostTestVerifier(false);
        $this->replaceDependencies($verifier);

        $this->submit($this->requestPost($email, true));

        self::assertSame([['solution', Config::FORM_CUSTOMER_REGISTRATION]], $verifier->calls);
        $this->assertCustomerDoesNotExist($email);
        self::assertNull($this->transportBuilder->getSentMessage());
        $this->assertSafeCustomerFormData($email);
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_registration 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testValidSolutionCreatesOneCustomerAndSendsOneEmail(): void
    {
        $email = 'registration-valid@example.test';
        $verifier = new RegistrationPostTestVerifier(true);
        $this->replaceDependencies($verifier);
        $this->customerSession->setData('customer_form_data', ['stale' => 'state']);

        $this->submit($this->requestPost($email, true));

        self::assertSame([['solution', Config::FORM_CUSTOMER_REGISTRATION]], $verifier->calls);
        $this->customerRepository->get($email);
        self::assertNotNull($this->transportBuilder->getSentMessage());
        self::assertNull($this->customerSession->getData('customer_form_data'));
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_registration 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 1
     * @magentoConfigFixture current_store customer/captcha/forms user_create
     * @magentoConfigFixture current_store customer/captcha/mode always
     */
    public function testNativeCaptchaFailureAfterValidSolutionPersistsOnlySafeState(): void
    {
        $email = 'registration-native-captcha@example.test';
        $verifier = new RegistrationPostTestVerifier(true);
        $this->replaceDependencies($verifier);

        $this->submit($this->requestPost($email, true));

        self::assertSame([['solution', Config::FORM_CUSTOMER_REGISTRATION]], $verifier->calls);
        $this->assertCustomerDoesNotExist($email);
        self::assertNull($this->transportBuilder->getSentMessage());
        $this->assertSafeCustomerFormData($email);
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_registration 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 1
     * @magentoConfigFixture current_store customer/captcha/forms user_create
     * @magentoConfigFixture current_store customer/captcha/mode always
     */
    public function testValidPrivateAndNativeCaptchaSolutionsCreateOneCustomer(): void
    {
        $email = 'registration-native-captcha-valid@example.test';
        $verifier = new RegistrationPostTestVerifier(true);
        $this->replaceDependencies($verifier);
        $this->customerSession->setData('user_create_word', [
            'data' => 'NativeCaptcha',
            'words' => 'NativeCaptcha',
            'expires' => time() + 3600,
        ]);
        $post = $this->requestPost($email, true);
        $post['captcha'] = ['user_create' => 'NativeCaptcha'];

        $this->submit($post);

        self::assertSame([['solution', Config::FORM_CUSTOMER_REGISTRATION]], $verifier->calls);
        $this->customerRepository->get($email);
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/customer_registration 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testNativeValidationFailureAfterValidSolutionPersistsOnlySafeState(): void
    {
        $email = 'registration-native-failure@example.test';
        $verifier = new RegistrationPostTestVerifier(true);
        $this->replaceDependencies($verifier);
        $post = $this->requestPost($email, true);
        $post['password_confirmation'] = 'DifferentPassword1!';
        $post += $this->safeAddressPost();
        $post['street'] = [
            0 => '1234 Fake Street',
            1 => 'Suite 2',
            '*' => 'not a street line',
            'password' => 'password',
        ];

        $this->submit($post);

        self::assertSame([['solution', Config::FORM_CUSTOMER_REGISTRATION]], $verifier->calls);
        $this->assertCustomerDoesNotExist($email);
        self::assertNull($this->transportBuilder->getSentMessage());
        $this->assertSafeCustomerFormData($email, $this->safePost($email) + $this->safeAddressPost());
    }

    /**
     * @return array<string, string>
     */
    private function safePost(string $email): array
    {
        return [
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'email' => $email,
            'is_subscribed' => '1',
        ];
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    private function safeAddressPost(): array
    {
        return [
            'create_address' => '1',
            'telephone' => '5123334444',
            'street' => [0 => '1234 Fake Street', 1 => 'Suite 2'],
            'country_id' => 'US',
            'city' => 'Austin',
            'postcode' => '78701',
            'default_billing' => '1',
            'default_shipping' => '1',
        ];
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    private function requestPost(string $email, bool $withSolution): array
    {
        $post = $this->safePost($email) + [
            'form_key' => (string) \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
                ->get(FormKey::class)
                ->getFormKey(),
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'success_url' => 'https://attacker.example.test/success',
            'error_url' => 'https://attacker.example.test/error',
            'api_token' => 'token',
        ];

        if ($withSolution) {
            $post['private-captcha-solution'] = 'solution';
        }

        return $post;
    }

    /**
     * @param array<string, string|array<int|string, string>> $post Registration request payload.
     */
    private function submit(array $post): void
    {
        $this->getRequest()->setMethod(Http::METHOD_POST)->setPostValue($post);
        $this->dispatch('customer/account/createPost');
    }

    private function assertCustomerDoesNotExist(string $email): void
    {
        try {
            $this->customerRepository->get($email);
            self::fail(sprintf('Customer %s exists unexpectedly.', $email));
        } catch (NoSuchEntityException) {
        }
    }

    /**
     * @param array<string, string|array<int, string>>|null $expectedState
     */
    private function assertSafeCustomerFormData(string $email, ?array $expectedState = null): void
    {
        self::assertSame($expectedState ?? $this->safePost($email), $this->customerSession->getData('customer_form_data'));
    }

    private function assertRegistrationRedirect(): void
    {
        self::assertTrue($this->getResponse()->isRedirect());
        self::assertStringContainsString(
            'customer/account/create',
            $this->getResponse()->getHeader('Location')->getFieldValue()
        );
    }

    private function replaceDependencies(RegistrationPostTestVerifier $verifier): void
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

final class RegistrationPostTestVerifier implements VerifierInterface
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
