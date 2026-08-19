<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Block\Customer;

use Magento\Checkout\Block\Checkout\LayoutProcessorInterface;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\ViewModel\WidgetConfig;

class LoginLayoutProcessor implements LayoutProcessorInterface
{
    /**
     * @param WidgetConfig $widgetConfig Supplies public, form-local widget configuration.
     */
    public function __construct(
        private readonly WidgetConfig $widgetConfig
    ) {
    }

    /**
     * Adds one component to each native AJAX login region when customer login is enabled.
     *
     * @param array $jsLayout Native popup or checkout UI component tree.
     * @return array Updated UI component tree.
     */
    public function process($jsLayout): array
    {
        if (!is_array($jsLayout)) {
            return [];
        }

        $this->addComponent($jsLayout, ['components', 'authenticationPopup', 'children'], 'popup');
        $this->addComponent(
            $jsLayout,
            ['components', 'checkout', 'children', 'authentication', 'children'],
            'checkout'
        );
        $this->addComponent($jsLayout, [
            'components',
            'checkout',
            'children',
            'steps',
            'children',
            'shipping-step',
            'children',
            'shippingAddress',
            'children',
            'customer-email',
            'children',
            'additional-login-form-fields',
            'children',
        ], 'shipping');
        $this->addComponent($jsLayout, [
            'components',
            'checkout',
            'children',
            'steps',
            'children',
            'billing-step',
            'children',
            'payment',
            'children',
            'customer-email',
            'children',
            'additional-login-form-fields',
            'children',
        ], 'billing');

        return $jsLayout;
    }

    /**
     * Adds a widget only when the native component region is available.
     *
     * @param array $jsLayout Native popup or checkout UI component tree.
     * @param array $path Component-tree path to the target children array.
     * @param string $surface Stable native login surface identifier.
     */
    private function addComponent(array &$jsLayout, array $path, string $surface): void
    {
        $target = &$jsLayout;
        foreach ($path as $part) {
            if (!isset($target[$part]) || !is_array($target[$part])) {
                return;
            }

            $target = &$target[$part];
        }

        $widget = $this->widgetConfig->getWidgetConfig(
            Config::FORM_CUSTOMER_LOGIN,
            'customer-login-' . $surface
        );
        if ($widget === null) {
            return;
        }

        $target['private-captcha'] = [
            'component' => 'PrivateCaptcha_PrivateCaptcha/js/view/ajax-login-widget',
            'displayArea' => 'additional-login-form-fields',
            'widget' => $widget,
            'markerField' => 'privateCaptchaMarker',
            'requestMarker' => 'private-captcha-login-' . $surface,
        ];
    }
}
