<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Integration\Controller;

use Magento\Contact\Model\MailInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\DataObject;
use Magento\TestFramework\TestCase\AbstractController;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\Validation\VerifierInterface;
use PrivateCaptcha\PrivateCaptcha\Observer\ValidatePredispatch;

final class ContactPostTest extends AbstractController
{
    protected function tearDown(): void
    {
        $this->restoreDependencies();
        parent::tearDown();
        $this->removeIgnitionHandlers();
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/contact_form 0
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testDisabledContactSubmissionRemainsNativeAndDoesNotVerify(): void
    {
        $verifier = new ContactPostTestVerifier(false);
        $mail = $this->createMock(MailInterface::class);
        $mail->expects(self::once())->method('send');
        $this->replaceDependencies($verifier, $mail);

        $this->submit($this->requestPost(false));

        self::assertSame([], $verifier->calls);
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/contact_form 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store private_captcha/advanced/theme dark
     * @magentoConfigFixture current_store private_captcha/advanced/language de
     * @magentoConfigFixture current_store private_captcha/advanced/custom_styles store-view-styles
     */
    public function testEnabledContactPageRendersOnePublicWidget(): void
    {
        $this->getRequest()->setMethod('GET');
        $this->dispatch('contact/index/index');

        $body = $this->getResponse()->getBody();
        self::assertSame(1, substr_count($body, 'class="private-captcha"'));
        self::assertStringContainsString('data-sitekey="public-site-key"', $body);
        self::assertStringContainsString('data-solution-field="private-captcha-solution"', $body);
        self::assertStringContainsString('data-theme="dark"', $body);
        self::assertStringContainsString('data-lang="de"', $body);
        self::assertStringContainsString('data-styles="store-view-styles"', $body);
        self::assertStringNotContainsString('private-api-key', $body);
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/contact_form 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testMissingSolutionFailsBeforeMailAndPreservesOnlySafeState(): void
    {
        $verifier = new ContactPostTestVerifier(true);
        $mail = $this->createMock(MailInterface::class);
        $mail->expects(self::never())->method('send');
        $this->replaceDependencies($verifier, $mail);

        $this->submit($this->requestPost(false));

        self::assertSame([], $verifier->calls);
        self::assertSafePersistedState();
        $this->assertContactRedirect();
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/contact_form 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testInvalidSolutionFailsBeforeMail(): void
    {
        $verifier = new ContactPostTestVerifier(false);
        $mail = $this->createMock(MailInterface::class);
        $mail->expects(self::never())->method('send');
        $this->replaceDependencies($verifier, $mail);

        $this->submit($this->requestPost(true));

        self::assertSame([['solution', Config::FORM_CONTACT]], $verifier->calls);
        self::assertSafePersistedState();
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/contact_form 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testVerifiedHoneypotSubmissionDoesNotSendMail(): void
    {
        $verifier = new ContactPostTestVerifier(true);
        $mail = $this->createMock(MailInterface::class);
        $mail->expects(self::never())->method('send');
        $this->replaceDependencies($verifier, $mail);
        $post = $this->requestPost(true);
        $post['hideit'] = 'bot';

        $this->submit($post);

        self::assertSame([['solution', Config::FORM_CONTACT]], $verifier->calls);
        self::assertSafePersistedState();
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/contact_form 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testValidSolutionSendsMailOnceWithoutSensitiveVariables(): void
    {
        $verifier = new ContactPostTestVerifier(true);
        $mail = $this->createMock(MailInterface::class);
        $mail->expects(self::once())
            ->method('send')
            ->with(
                'ada@example.test',
                self::callback(static function (array $variables): bool {
                    $data = $variables['data'] ?? null;

                    return $data instanceof DataObject && $data->getData() === [
                        'name' => 'Ada Lovelace',
                        'email' => 'ada@example.test',
                        'telephone' => '555-0100',
                        'comment' => 'Hello',
                    ];
                })
            );
        $this->replaceDependencies($verifier, $mail);

        $this->getRequest()->setQueryValue([0 => ['private-captcha-solution' => 'query-solution']]);
        $this->submit($this->requestPost(true));

        self::assertSame([['solution', Config::FORM_CONTACT]], $verifier->calls);
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/contact_form 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testNativeValidationFailureAfterValidSolutionPersistsOnlySafeState(): void
    {
        $verifier = new ContactPostTestVerifier(true);
        $mail = $this->createMock(MailInterface::class);
        $mail->expects(self::never())->method('send');
        $this->replaceDependencies($verifier, $mail);
        $post = $this->requestPost(true);
        $post['comment'] = '';
        $expectedState = $this->safePost();
        $expectedState['comment'] = '';

        $this->submit($post);

        self::assertSame([['solution', Config::FORM_CONTACT]], $verifier->calls);
        $this->assertSafePersistedState($expectedState);
    }

    /**
     * @return array<string, string>
     */
    private function safePost(): array
    {
        return [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'telephone' => '555-0100',
            'comment' => 'Hello',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function requestPost(bool $withSolution): array
    {
        $post = $this->safePost() + [
            'form_key' => (string) \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
                ->get(FormKey::class)
                ->getFormKey(),
            'api_token' => 'token',
            'hideit' => '',
        ];

        if ($withSolution) {
            $post['private-captcha-solution'] = 'solution';
        }

        return $post;
    }

    /**
     * @param array<string, string> $post Contact request payload.
     */
    private function submit(array $post): void
    {
        $this->getRequest()->setMethod('POST')->setPostValue($post);
        $this->dispatch('contact/index/post');
    }

    /**
     * @param array<string, string>|null $expectedState Expected native Contact persistor values.
     */
    private function assertSafePersistedState(?array $expectedState = null): void
    {
        $persistor = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(DataPersistorInterface::class);

        self::assertSame($expectedState ?? $this->safePost(), $persistor->get('contact_us'));
        $persistor->clear('contact_us');
    }

    private function assertContactRedirect(): void
    {
        self::assertTrue($this->getResponse()->isRedirect());
        self::assertStringContainsString(
            'contact/index/index',
            $this->getResponse()->getHeader('Location')->getFieldValue()
        );
    }

    private function replaceDependencies(ContactPostTestVerifier $verifier, MailInterface $mail): void
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $objectManager->removeSharedInstance(ValidatePredispatch::class, true);
        $objectManager->addSharedInstance($verifier, VerifierInterface::class, true);
        $objectManager->addSharedInstance($mail, MailInterface::class, true);
    }

    private function restoreDependencies(): void
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $objectManager->removeSharedInstance(ValidatePredispatch::class, true);
        $objectManager->removeSharedInstance(VerifierInterface::class, true);
        $objectManager->removeSharedInstance(MailInterface::class, true);
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

final class ContactPostTestVerifier implements VerifierInterface
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
