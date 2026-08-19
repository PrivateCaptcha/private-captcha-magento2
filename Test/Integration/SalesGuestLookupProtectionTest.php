<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Integration;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Sales\Api\Data\OrderInterfaceFactory;
use Magento\Sales\Block\Widget\Guest\Form as SalesGuestForm;
use Magento\Sales\Helper\Guest;
use Magento\TestFramework\TestCase\AbstractController;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\Validation\VerifierInterface;
use PrivateCaptcha\PrivateCaptcha\Observer\ValidatePredispatch;

final class SalesGuestLookupProtectionTest extends AbstractController
{
    private ActionFlag $actionFlag;

    private CookieManagerInterface $cookieManager;

    private CustomerSession $customerSession;

    private Registry $registry;

    private OrderInterfaceFactory $orderFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->actionFlag = $objectManager->get(ActionFlag::class);
        $this->cookieManager = $objectManager->get(CookieManagerInterface::class);
        $this->customerSession = $objectManager->get(CustomerSession::class);
        $this->registry = $objectManager->get(Registry::class);
        $this->orderFactory = $objectManager->get(OrderInterfaceFactory::class);
        $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, false);
        $this->cookieManager->deleteCookie(Guest::COOKIE_NAME);
        $this->registry->unregister('current_order');
        if ($this->customerSession->isLoggedIn()) {
            $this->customerSession->logout();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->cookieManager)) {
            $this->cookieManager->deleteCookie(Guest::COOKIE_NAME);
        }
        if (isset($this->registry)) {
            $this->registry->unregister('current_order');
        }
        if (isset($this->customerSession) && $this->customerSession->isLoggedIn()) {
            $this->customerSession->logout();
        }
        $this->restoreDependencies();
        parent::tearDown();
        $this->removeIgnitionHandlers();
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/orders_returns 0
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testDisabledLookupRemainsNativeAndDoesNotVerify(): void
    {
        $verifier = new SalesGuestLookupProtectionTestVerifier(false);
        $this->replaceDependencies($verifier);

        try {
            $this->submit($this->requestPost(false));

            self::assertSame([], $verifier->calls);
            self::assertStringContainsString('Order # 100000001', $this->getResponse()->getBody());
            self::assertNotNull($this->registry->registry('current_order'));
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/orders_returns 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testMissingSolutionPreventsLookupDisclosureAndCookieCreation(): void
    {
        $verifier = new SalesGuestLookupProtectionTestVerifier(true);
        $this->replaceDependencies($verifier);

        try {
            $this->submit($this->requestPost(false));

            self::assertSame([], $verifier->calls);
            $this->assertFailedLookup();
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/orders_returns 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testInvalidSolutionPreventsLookupDisclosureAndCookieCreation(): void
    {
        $verifier = new SalesGuestLookupProtectionTestVerifier(false);
        $this->replaceDependencies($verifier);

        try {
            $this->submit($this->requestPost(true));

            self::assertSame([['solution', Config::FORM_ORDERS_RETURNS]], $verifier->calls);
            $this->assertFailedLookup();
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/orders_returns 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testValidSolutionReachesTheNativeLookupOnce(): void
    {
        $verifier = new SalesGuestLookupProtectionTestVerifier(true);
        $this->replaceDependencies($verifier);

        try {
            $this->submit($this->requestPost(true));

            self::assertSame([['solution', Config::FORM_ORDERS_RETURNS]], $verifier->calls);
            self::assertStringContainsString('Order # 100000001', $this->getResponse()->getBody());
            self::assertNotNull($this->registry->registry('current_order'));
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/orders_returns 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testEnabledStandalonePageRendersOneAdjacentPublicWidget(): void
    {
        $this->getRequest()->setMethod(Http::METHOD_GET);
        $this->dispatch('sales/guest/form');

        $body = $this->getResponse()->getBody();
        $formEnd = strpos($body, '</form>');
        $widget = strpos($body, 'class="private-captcha"');

        self::assertNotFalse($formEnd);
        self::assertNotFalse($widget);
        self::assertSame(1, substr_count($body, 'class="private-captcha"'));
        self::assertGreaterThan($formEnd, $widget);
        self::assertStringContainsString('data-sitekey="public-site-key"', $body);
        self::assertStringContainsString('detachedTarget', $body);
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/orders_returns 0
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testDisabledStandalonePageRemainsNative(): void
    {
        $this->getRequest()->setMethod(Http::METHOD_GET);
        $this->dispatch('sales/guest/form');

        $body = $this->getResponse()->getBody();

        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        self::assertStringContainsString('name="oar_order_id"', $body);
        self::assertSame(0, substr_count($body, 'class="private-captcha"'));
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/orders_returns 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testEachCmsWidgetBlockReceivesAnIndependentWidgetIdentity(): void
    {
        $layout = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(LayoutInterface::class);
        $first = $layout->createBlock(SalesGuestForm::class, 'cms.orders.returns.first')
            ->setTemplate('Magento_Sales::widget/guest/form.phtml')
            ->toHtml();
        $second = $layout->createBlock(SalesGuestForm::class, 'cms.orders.returns.second')
            ->setTemplate('Magento_Sales::widget/guest/form.phtml')
            ->toHtml();

        self::assertSame(1, substr_count($first, 'class="private-captcha"'));
        self::assertSame(1, substr_count($second, 'class="private-captcha"'));
        self::assertNotSame($this->getWidgetId($first), $this->getWidgetId($second));
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoConfigFixture base_website private_captcha/protected_forms/orders_returns 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testGetWithoutCookieRemainsNativeWithoutVerification(): void
    {
        $verifier = new SalesGuestLookupProtectionTestVerifier(false);
        $this->replaceDependencies($verifier);

        try {
            $this->dispatchGet();

            self::assertSame([], $verifier->calls);
            self::assertTrue($this->getResponse()->isRedirect());
            self::assertStringContainsString(
                'sales/guest/form',
                $this->getResponse()->getHeader('Location')->getFieldValue()
            );
            self::assertNull($this->registry->registry('current_order'));
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/orders_returns 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testGetWithValidCookieRemainsNativeWithoutVerification(): void
    {
        $verifier = new SalesGuestLookupProtectionTestVerifier(false);
        $this->replaceDependencies($verifier);
        $this->setValidGuestCookie();

        try {
            $this->dispatchGet();

            self::assertSame([], $verifier->calls);
            self::assertStringContainsString('Order # 100000001', $this->getResponse()->getBody());
            self::assertNotNull($this->registry->registry('current_order'));
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/orders_returns 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testInvalidGuestCookieDoesNotDiscloseAnOrder(): void
    {
        $verifier = new SalesGuestLookupProtectionTestVerifier(false);
        $this->replaceDependencies($verifier);
        $this->cookieManager->setPublicCookie(Guest::COOKIE_NAME, base64_encode('invalid:100000001'));

        try {
            $this->dispatchGet();

            self::assertSame([], $verifier->calls);
            self::assertTrue($this->getResponse()->isRedirect());
            self::assertStringContainsString(
                'sales/guest/form',
                $this->getResponse()->getHeader('Location')->getFieldValue()
            );
            self::assertNull($this->registry->registry('current_order'));
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture base_website private_captcha/protected_forms/orders_returns 1
     * @magentoConfigFixture base_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture base_website private_captcha/credentials/api_key private-api-key
     */
    public function testLoggedInGetAndPostRemainNativeWithoutVerification(): void
    {
        $verifier = new SalesGuestLookupProtectionTestVerifier(false);
        $this->replaceDependencies($verifier);
        $this->customerSession->setCustomerId(1);

        try {
            $this->dispatchGet();

            self::assertSame([], $verifier->calls);
            self::assertTrue($this->getResponse()->isRedirect());
            self::assertStringContainsString(
                'sales/order/history',
                $this->getResponse()->getHeader('Location')->getFieldValue()
            );

            $this->removeIgnitionHandlers();
            $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, false);
            $this->getResponse()->getHeaders()->clearHeaders();
            $this->getResponse()->setBody('');
            $this->getResponse()->setHttpResponseCode(200);
            $this->submit($this->requestPost(true));

            self::assertSame([], $verifier->calls);
            self::assertTrue($this->getResponse()->isRedirect());
            self::assertStringContainsString(
                'sales/order/history',
                $this->getResponse()->getHeader('Location')->getFieldValue()
            );
        } finally {
            $this->restoreDependencies();
        }
    }

    /**
     * @return array<string, string>
     */
    private function requestPost(bool $withSolution): array
    {
        $post = [
            'oar_order_id' => '100000001',
            'oar_billing_lastname' => 'lastname',
            'oar_type' => 'email',
            'oar_email' => 'customer@example.com',
            'oar_zip' => '',
            'form_key' => (string) \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
                ->get(FormKey::class)
                ->getFormKey(),
        ];
        if ($withSolution) {
            $post['private-captcha-solution'] = 'solution';
        }

        return $post;
    }

    /**
     * @param array<string, string> $post Native Orders and Returns form data.
     */
    private function submit(array $post): void
    {
        $this->getRequest()->setMethod(Http::METHOD_POST)->setPostValue($post);
        $this->dispatch('sales/guest/view');
    }

    private function dispatchGet(): void
    {
        $this->getRequest()->setMethod(Http::METHOD_GET);
        $this->dispatch('sales/guest/view');
    }

    private function setValidGuestCookie(): void
    {
        $order = $this->orderFactory->create()->loadByIncrementId('100000001');
        $this->cookieManager->setPublicCookie(
            Guest::COOKIE_NAME,
            base64_encode($order->getProtectCode() . ':' . $order->getIncrementId())
        );
    }

    private function getWidgetId(string $html): string
    {
        preg_match('/id="(private-captcha-[a-f0-9]+)"/', $html, $matches);
        $widgetId = (string) ($matches[1] ?? '');
        self::assertNotSame('', $widgetId);

        return $widgetId;
    }

    private function assertFailedLookup(): void
    {
        self::assertTrue($this->getResponse()->isRedirect());
        self::assertStringContainsString(
            'sales/guest/form',
            $this->getResponse()->getHeader('Location')->getFieldValue()
        );
        self::assertStringNotContainsString('Order # 100000001', $this->getResponse()->getBody());
        self::assertNull($this->registry->registry('current_order'));
        self::assertNull($this->cookieManager->getCookie(Guest::COOKIE_NAME));
    }

    private function replaceDependencies(SalesGuestLookupProtectionTestVerifier $verifier): void
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

final class SalesGuestLookupProtectionTestVerifier implements VerifierInterface
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
