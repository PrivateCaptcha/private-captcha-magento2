<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Integration;

use Magento\Checkout\Block\Onepage;
use Magento\Customer\Block\Account\AuthenticationPopup;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\PrivateCaptcha\Block\Customer\LoginLayoutProcessor;
use PrivateCaptcha\PrivateCaptcha\Model\Config;

final class CustomerLoginWidgetLayoutProcessorTest extends TestCase
{
    /**
     * @magentoAppArea frontend
     * @magentoConfigFixture current_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture current_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_website private_captcha/protected_forms/customer_login 1
     */
    public function testEnabledLoginAddsOneUniquePublicWidgetToEveryAjaxSurface(): void
    {
        $processor = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(LoginLayoutProcessor::class);
        $layout = $processor->process($this->loginLayout());
        $components = [
            $layout['components']['authenticationPopup']['children']['private-captcha'],
            $layout['components']['checkout']['children']['authentication']['children']['private-captcha'],
            $layout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
                ['shippingAddress']['children']['customer-email']['children']['additional-login-form-fields']['children']
                ['private-captcha'],
            $layout['components']['checkout']['children']['steps']['children']['billing-step']['children']
                ['payment']['children']['customer-email']['children']['additional-login-form-fields']['children']
                ['private-captcha'],
        ];

        self::assertSame([
            'private-captcha-login-popup',
            'private-captcha-login-checkout',
            'private-captcha-login-shipping',
            'private-captcha-login-billing',
        ], array_column($components, 'requestMarker'));
        self::assertCount(4, array_unique(array_column(array_column($components, 'widget'), 'id')));
        foreach ($components as $component) {
            self::assertSame(
                'PrivateCaptcha_PrivateCaptcha/js/view/ajax-login-widget',
                $component['component']
            );
            self::assertSame('additional-login-form-fields', $component['displayArea']);
            self::assertSame('privateCaptchaMarker', $component['markerField']);
            self::assertSame('public-site-key', $component['widget']['site_key']);
            self::assertSame(Config::SOLUTION_FIELD, $component['widget']['solution_field']);
            self::assertSame(Config::DEFAULT_SCRIPT_URL, $component['widget']['script_url']);
            self::assertArrayNotHasKey('api_key', $component['widget']);
            self::assertArrayNotHasKey('solution', $component['widget']);
        }
    }

    /**
     * @magentoAppArea frontend
     * @magentoConfigFixture current_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture current_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_website private_captcha/protected_forms/customer_login 0
     */
    public function testDisabledLoginLeavesEveryAjaxSurfaceUnchanged(): void
    {
        $processor = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(LoginLayoutProcessor::class);

        self::assertSame($this->loginLayout(), $processor->process($this->loginLayout()));
    }

    /**
     * @magentoAppArea frontend
     * @magentoConfigFixture current_website private_captcha/credentials/site_key public-site-key
     * @magentoConfigFixture current_website private_captcha/credentials/api_key private-api-key
     * @magentoConfigFixture current_website private_captcha/protected_forms/customer_login 1
     */
    public function testMagentoLoginBlocksApplyTheConfiguredProcessor(): void
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $popup = $objectManager->create(AuthenticationPopup::class, ['data' => ['jsLayout' => $this->loginLayout()]]);
        $checkout = $objectManager->create(Onepage::class, ['data' => ['jsLayout' => $this->loginLayout()]]);
        $popupLayout = json_decode((string) $popup->getJsLayout(), true, 512, JSON_THROW_ON_ERROR);
        $checkoutLayout = json_decode((string) $checkout->getJsLayout(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            'private-captcha-login-popup',
            $popupLayout['components']['authenticationPopup']['children']['private-captcha']['requestMarker']
        );
        self::assertSame(
            'private-captcha-login-checkout',
            $checkoutLayout['components']['checkout']['children']['authentication']['children']['private-captcha']
                ['requestMarker']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function loginLayout(): array
    {
        return [
            'components' => [
                'authenticationPopup' => ['children' => []],
                'checkout' => [
                    'children' => [
                        'authentication' => ['children' => []],
                        'sidebar' => [
                            'children' => [
                                'summary' => ['children' => ['totals' => ['children' => []]]],
                            ],
                        ],
                        'steps' => [
                            'children' => [
                                'shipping-step' => [
                                    'children' => [
                                        'shippingAddress' => [
                                            'children' => [
                                                'customer-email' => [
                                                    'children' => [
                                                        'additional-login-form-fields' => ['children' => []],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'billing-step' => [
                                    'children' => [
                                        'payment' => [
                                            'children' => [
                                                'renders' => ['children' => []],
                                                'payments-list' => ['children' => []],
                                                'afterMethods' => ['children' => []],
                                                'customer-email' => [
                                                    'children' => [
                                                        'additional-login-form-fields' => ['children' => []],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
