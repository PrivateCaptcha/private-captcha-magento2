<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Integration;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Captcha\Helper\Data as CaptchaHelper;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Session\Generic;
use Magento\Framework\Validation\ValidationResult;
use Magento\ReCaptchaUi\Model\RequestHandlerInterface;
use Magento\ReCaptchaValidationApi\Api\ValidatorInterface as RecaptchaValidatorInterface;
use Magento\ReCaptchaWishlist\Observer\ShareWishlistObserver;
use Magento\TestFramework\Mail\Template\TransportBuilderMock;
use Magento\TestFramework\TestCase\AbstractController;
use Magento\Wishlist\Model\Wishlist;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\Validation\VerifierInterface;
use PrivateCaptcha\PrivateCaptcha\Observer\ValidatePredispatch;
use PrivateCaptcha\PrivateCaptcha\Plugin\Wishlist\ScrubAuthenticationState;

final class WishlistShareProtectionTest extends AbstractController
{
    private CustomerSession $customerSession;

    private Generic $wishlistSession;

    private TransportBuilderMock $transportBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->customerSession = $objectManager->get(CustomerSession::class);
        $this->wishlistSession = $objectManager->get('Magento\\Wishlist\\Model\\Session');
        $this->transportBuilder = $objectManager->get(TransportBuilderMock::class);
        $this->wishlistSession->unsetData('sharing_form');
        $this->customerSession->unsBeforeWishlistRequest();
        $this->customerSession->unsBeforeRequestParams();
        $this->customerSession->unsBeforeWishlistUrl();
        $this->transportBuilder->clean();
        $objectManager->get(ActionFlag::class)->set('', ActionInterface::FLAG_NO_DISPATCH, false);
        if ($this->customerSession->isLoggedIn()) {
            $this->customerSession->logout();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->wishlistSession)) {
            $this->wishlistSession->unsetData('sharing_form');
        }
        if (isset($this->customerSession)) {
            $this->customerSession->unsBeforeWishlistRequest();
            $this->customerSession->unsBeforeRequestParams();
            $this->customerSession->unsBeforeWishlistUrl();
            if ($this->customerSession->isLoggedIn()) {
                $this->customerSession->logout();
            }
        }
        if (isset($this->transportBuilder)) {
            $this->transportBuilder->clean();
        }
        $this->restoreNativeRecaptchaDependencies();
        $this->restoreDependencies();
        parent::tearDown();
        $this->removeIgnitionHandlers();
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Wishlist/_files/wishlist.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/wishlist_share 0
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testDisabledWishlistShareRemainsNativeAndDoesNotVerify(): void
    {
        $verifier = new WishlistShareProtectionTestVerifier(false);
        $this->replaceDependencies($verifier);
        $this->login();

        $this->submit($this->requestPost(false));

        self::assertSame([], $verifier->calls);
        self::assertNotNull($this->transportBuilder->getSentMessage());
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Wishlist/_files/wishlist.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/wishlist_share 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 1
     * @magentoConfigFixture current_store customer/captcha/forms share_wishlist_form
     * @magentoConfigFixture current_store customer/captcha/always_for/share_wishlist_form 1
     */
    public function testEnabledPageUsesAnAdjacentWidgetWithoutReplacingNativeCaptcha(): void
    {
        $this->login();
        $this->getRequest()->setMethod(Http::METHOD_GET);
        $this->dispatch('wishlist/index/share');

        $body = $this->getResponse()->getBody();
        $formEnd = strpos($body, '</form>');
        $widget = strpos($body, 'class="private-captcha"');

        self::assertNotFalse($formEnd);
        self::assertSame(1, substr_count($body, 'class="private-captcha"'));
        self::assertGreaterThan($formEnd, $widget);
        self::assertStringContainsString('detachedTarget', $body);
        self::assertStringContainsString('id="captcha_share_wishlist_form"', $body);
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Wishlist/_files/wishlist.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/wishlist_share 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture base_website recaptcha_frontend/type_invisible/public_key test_public_key
     * @magentoConfigFixture base_website recaptcha_frontend/type_invisible/private_key test_private_key
     * @magentoConfigFixture base_website recaptcha_frontend/type_for/wishlist invisible
     * @magentoConfigFixture default_store recaptcha_frontend/type_for/wishlist invisible
     */
    public function testEnabledPageRetainsNativeRecaptchaOutput(): void
    {
        $this->login();
        $this->getRequest()->setMethod(Http::METHOD_GET);
        $this->dispatch('wishlist/index/share');

        $body = $this->getResponse()->getBody();

        self::assertSame(1, substr_count($body, 'class="private-captcha"'));
        self::assertStringContainsString('field-recaptcha', $body);
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Wishlist/_files/wishlist.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/wishlist_share 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testMissingSolutionPreventsMailAndPersistsOnlySafeState(): void
    {
        $verifier = new WishlistShareProtectionTestVerifier(true);
        $this->replaceDependencies($verifier);
        $this->login();
        $wishlistId = $this->getWishlistId();

        $this->submit($this->requestPost(false), $this->sendPath($wishlistId));

        self::assertSame([], $verifier->calls);
        self::assertNull($this->transportBuilder->getSentMessage());
        $this->assertSafeState();
        $this->assertShareRedirect($wishlistId);
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Wishlist/_files/wishlist.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/wishlist_share 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testInvalidSolutionPreventsMailAndPersistsOnlySafeState(): void
    {
        $verifier = new WishlistShareProtectionTestVerifier(false);
        $this->replaceDependencies($verifier);
        $this->login();

        $this->submit($this->requestPost(true));

        self::assertSame([['solution', Config::FORM_WISHLIST_SHARE]], $verifier->calls);
        self::assertNull($this->transportBuilder->getSentMessage());
        $this->assertSafeState();
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Wishlist/_files/wishlist.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/wishlist_share 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testValidSolutionSendsMailAndUpdatesTheWishlistOnce(): void
    {
        $verifier = new WishlistShareProtectionTestVerifier(true);
        $this->replaceDependencies($verifier);
        $this->login();
        $shared = $this->getSharedCount();
        $wishlistId = $this->getWishlistId();

        $this->submit($this->requestPost(true), $this->sendPath($wishlistId));

        self::assertSame([['solution', Config::FORM_WISHLIST_SHARE]], $verifier->calls);
        self::assertNotNull($this->transportBuilder->getSentMessage());
        self::assertSame($shared + 1, $this->getSharedCount());
        self::assertNull($this->wishlistSession->getData('sharing_form'));
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Wishlist/_files/wishlist.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/wishlist_share 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testNativeValidationFailureAfterVerificationPersistsOnlySafeState(): void
    {
        $verifier = new WishlistShareProtectionTestVerifier(true);
        $this->replaceDependencies($verifier);
        $this->login();
        $post = $this->requestPost(true);
        $post['emails'] = 'not-an-email';
        $expectedState = $this->safePost();
        $expectedState['emails'] = 'not-an-email';

        $this->submit($post);

        self::assertSame([['solution', Config::FORM_WISHLIST_SHARE]], $verifier->calls);
        self::assertNull($this->transportBuilder->getSentMessage());
        $this->assertSafeState($expectedState);
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Wishlist/_files/wishlist.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/wishlist_share 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 1
     * @magentoConfigFixture current_store customer/captcha/forms share_wishlist_form
     * @magentoConfigFixture current_store customer/captcha/always_for/share_wishlist_form 1
     */
    public function testFailedNativeCaptchaAfterVerificationPreventsMail(): void
    {
        $verifier = new WishlistShareProtectionTestVerifier(true);
        $this->replaceDependencies($verifier);
        $this->login();
        $shared = $this->getSharedCount();

        $this->submit($this->requestPost(true));

        self::assertSame([['solution', Config::FORM_WISHLIST_SHARE]], $verifier->calls);
        self::assertNull($this->transportBuilder->getSentMessage());
        self::assertSame($shared, $this->getSharedCount());
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Wishlist/_files/wishlist.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/wishlist_share 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_store customer/captcha/enable 1
     * @magentoConfigFixture current_store customer/captcha/forms share_wishlist_form
     * @magentoConfigFixture current_store customer/captcha/always_for/share_wishlist_form 1
     */
    public function testValidNativeCaptchaAfterVerificationSendsMailOnce(): void
    {
        $verifier = new WishlistShareProtectionTestVerifier(true);
        $this->replaceDependencies($verifier);
        $this->login();
        $shared = $this->getSharedCount();
        $post = $this->requestPost(true);
        $post['captcha']['share_wishlist_form'] = $this->getNativeCaptchaWord();

        $this->submit($post);

        self::assertSame([['solution', Config::FORM_WISHLIST_SHARE]], $verifier->calls);
        self::assertNotNull($this->transportBuilder->getSentMessage());
        self::assertSame($shared + 1, $this->getSharedCount());
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Wishlist/_files/wishlist.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/wishlist_share 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture base_website recaptcha_frontend/type_invisible/public_key test_public_key
     * @magentoConfigFixture base_website recaptcha_frontend/type_invisible/private_key test_private_key
     * @magentoConfigFixture base_website recaptcha_frontend/type_for/wishlist invisible
     * @magentoConfigFixture default_store recaptcha_frontend/type_for/wishlist invisible
     */
    public function testMissingNativeRecaptchaAfterVerificationPreventsMail(): void
    {
        $verifier = new WishlistShareProtectionTestVerifier(true);
        $this->replaceDependencies($verifier);
        $this->login();
        $shared = $this->getSharedCount();

        $this->submit($this->requestPost(true, false));

        self::assertSame([['solution', Config::FORM_WISHLIST_SHARE]], $verifier->calls);
        self::assertNull($this->transportBuilder->getSentMessage());
        self::assertSame($shared, $this->getSharedCount());
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Wishlist/_files/wishlist.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/wishlist_share 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture base_website recaptcha_frontend/type_invisible/public_key test_public_key
     * @magentoConfigFixture base_website recaptcha_frontend/type_invisible/private_key test_private_key
     * @magentoConfigFixture base_website recaptcha_frontend/type_for/wishlist invisible
     * @magentoConfigFixture default_store recaptcha_frontend/type_for/wishlist invisible
     */
    public function testValidNativeRecaptchaAfterVerificationSendsMailOnce(): void
    {
        $verifier = new WishlistShareProtectionTestVerifier(true);
        $this->replaceDependencies($verifier);
        $this->login();
        $shared = $this->getSharedCount();
        $validationResult = $this->createMock(ValidationResult::class);
        $validationResult->expects(self::once())->method('isValid')->willReturn(true);
        $recaptchaValidator = $this->createMock(RecaptchaValidatorInterface::class);
        $recaptchaValidator->expects(self::once())
            ->method('isValid')
            ->with('NativeReCaptcha', self::anything())
            ->willReturn($validationResult);
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $objectManager->removeSharedInstance(ShareWishlistObserver::class, true);
        $objectManager->removeSharedInstance(RequestHandlerInterface::class, true);
        $objectManager->addSharedInstance($recaptchaValidator, RecaptchaValidatorInterface::class, true);

        $this->submit($this->requestPost(true));

        self::assertSame([['solution', Config::FORM_WISHLIST_SHARE]], $verifier->calls);
        self::assertNotNull($this->transportBuilder->getSentMessage());
        self::assertSame($shared + 1, $this->getSharedCount());
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Wishlist/_files/wishlist.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/wishlist_share 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testUnauthenticatedNativeAuthenticationSkipsVerification(): void
    {
        $verifier = new WishlistShareProtectionTestVerifier(false);
        $this->replaceDependencies($verifier);
        $wishlistId = $this->getWishlistId();
        $post = $this->requestPost(true);
        $post['referer_url'] = $this->getWishlistShareUrl([
            'wishlist_id' => $wishlistId,
            'private-captcha-solution' => 'solution',
        ]);

        $this->submit($post, $this->sendPath($wishlistId));

        self::assertSame([], $verifier->calls);
        self::assertNull($this->transportBuilder->getSentMessage());
        // The isolated integration sandbox excludes the current compiled action-plugin metadata.
        $this->scrubWishlistAuthenticationState();
        $expectedState = $this->safePost() + ['wishlist_id' => $wishlistId];
        self::assertSame($expectedState, $this->customerSession->getBeforeWishlistRequest());
        self::assertSame($expectedState, $this->customerSession->getBeforeRequestParams());
        self::assertStringContainsString(
            'wishlist/index/share/wishlist_id/' . $wishlistId,
            (string) $this->customerSession->getBeforeWishlistUrl()
        );
        self::assertStringNotContainsString(
            'private-captcha-solution',
            (string) $this->customerSession->getBeforeWishlistUrl()
        );
        self::assertNull($this->wishlistSession->getData('sharing_form'));
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Wishlist/_files/wishlist.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/wishlist_share 0
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testDisabledUnauthenticatedShareRetainsNativeAuthenticationState(): void
    {
        $verifier = new WishlistShareProtectionTestVerifier(false);
        $this->replaceDependencies($verifier);
        $wishlistId = $this->getWishlistId();
        $post = $this->requestPost(true);

        $this->submit($post, $this->sendPath($wishlistId));

        self::assertSame([], $verifier->calls);
        self::assertNull($this->transportBuilder->getSentMessage());
        $expectedState = ['wishlist_id' => (string) $wishlistId] + $post;
        self::assertSame($expectedState, $this->customerSession->getBeforeWishlistRequest());
        $this->scrubWishlistAuthenticationState();
        self::assertSame($expectedState, $this->customerSession->getBeforeWishlistRequest());
    }

    private function login(): void
    {
        $this->customerSession->setCustomerId(1);
    }

    /**
     * @return array<string, string>
     */
    private function safePost(): array
    {
        return [
            'emails' => 'charles@example.test',
            'message' => 'Hello',
            'rss_url' => '1',
        ];
    }

    /**
     * @return array<string, string|array<string, string>>
     */
    private function requestPost(bool $withSolution, bool $withNativeRecaptcha = true): array
    {
        $post = $this->safePost() + [
            'form_key' => (string) \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
                ->get(FormKey::class)
                ->getFormKey(),
            'captcha' => ['share_wishlist_form' => 'NativeCaptcha'],
            'api_token' => 'token',
            'return_url' => 'https://attacker.example.test',
        ];

        if ($withNativeRecaptcha) {
            $post['g-recaptcha-response'] = 'NativeReCaptcha';
        }

        if ($withSolution) {
            $post['private-captcha-solution'] = 'solution';
        }

        return $post;
    }

    /**
     * @param array<string, string|array<string, string>> $post Wishlist form data.
     */
    private function submit(array $post, string $path = 'wishlist/index/send'): void
    {
        $this->getRequest()->setMethod(Http::METHOD_POST)->setPostValue($post);
        $this->dispatch($path);
    }

    /**
     * @param array<string, string>|null $expectedState Expected native wishlist sharing state.
     */
    private function assertSafeState(?array $expectedState = null): void
    {
        self::assertSame($expectedState ?? $this->safePost(), $this->wishlistSession->getData('sharing_form'));
    }

    private function assertShareRedirect(int $wishlistId): void
    {
        self::assertTrue($this->getResponse()->isRedirect());
        self::assertStringContainsString(
            'wishlist/index/share',
            $this->getResponse()->getHeader('Location')->getFieldValue()
        );
        self::assertStringContainsString(
            'wishlist_id/' . $wishlistId,
            $this->getResponse()->getHeader('Location')->getFieldValue()
        );
    }

    private function sendPath(int $wishlistId): string
    {
        return 'wishlist/index/send/wishlist_id/' . $wishlistId;
    }

    private function getNativeCaptchaWord(): string
    {
        $captcha = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->get(CaptchaHelper::class)
            ->getCaptcha('share_wishlist_form');
        $captcha->generate();

        return (string) $captcha->getWord();
    }

    private function getWishlistId(): int
    {
        return (int) \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->create(Wishlist::class)
            ->loadByCustomerId(1)
            ->getId();
    }

    /**
     * @param array<string, int|string> $parameters Internal Wishlist Share URL parameters.
     */
    private function getWishlistShareUrl(array $parameters = []): string
    {
        return \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->get(\Magento\Framework\UrlInterface::class)
            ->getUrl('wishlist/index/share', $parameters);
    }

    private function scrubWishlistAuthenticationState(): void
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $action = (new \ReflectionClass(\Magento\Wishlist\Controller\Index\Send::class))
            ->newInstanceWithoutConstructor();
        $objectManager->get(ScrubAuthenticationState::class)->afterDispatch(
            $action,
            $this->getResponse(),
            $this->getRequest()
        );
    }

    private function getSharedCount(): int
    {
        return (int) \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->create(Wishlist::class)
            ->loadByCustomerId(1)
            ->getShared();
    }

    private function replaceDependencies(WishlistShareProtectionTestVerifier $verifier): void
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

    private function restoreNativeRecaptchaDependencies(): void
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $objectManager->removeSharedInstance(ShareWishlistObserver::class, true);
        $objectManager->removeSharedInstance(RequestHandlerInterface::class, true);
        $objectManager->removeSharedInstance(RecaptchaValidatorInterface::class, true);
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

final class WishlistShareProtectionTestVerifier implements VerifierInterface
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
