<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Plugin\Captcha;

use Magento\Captcha\Observer\CheckUserCreateObserver;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Event\Observer;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\Form\StateManager;
use PrivateCaptcha\PrivateCaptcha\Model\Validation\ProtectedActionResolver;

class ScrubRegistrationState
{
    /**
     * @param ProtectedActionResolver $protectedActionResolver Resolves eligible Registration actions.
     * @param StateManager $stateManager Filters customer state written by native CAPTCHA validation.
     */
    public function __construct(
        private readonly ProtectedActionResolver $protectedActionResolver,
        private readonly StateManager $stateManager
    ) {
    }

    /**
     * Removes transient credentials when native CAPTCHA stops Registration before postdispatch.
     *
     * @param CheckUserCreateObserver $subject Native Registration CAPTCHA observer.
     * @param mixed $result Native observer result.
     * @param Observer $observer Native CAPTCHA event data.
     * @return mixed Native observer result.
     */
    public function afterExecute(CheckUserCreateObserver $subject, mixed $result, Observer $observer): mixed
    {
        $action = $observer->getData('controller_action');
        $request = $observer->getData('request');
        if (is_object($action) &&
            $request instanceof Http &&
            $this->protectedActionResolver->resolve($action, $request) === Config::FORM_CUSTOMER_REGISTRATION) {
            $this->stateManager->scrub(Config::FORM_CUSTOMER_REGISTRATION);
        }

        return $result;
    }
}
