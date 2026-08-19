<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Observer;

use Magento\Framework\App\Request\Http;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use PrivateCaptcha\PrivateCaptcha\Model\Form\StateManager;
use PrivateCaptcha\PrivateCaptcha\Model\Validation\ProtectedActionResolver;

class SanitizePersistedState implements ObserverInterface
{
    /**
     * @param ProtectedActionResolver $protectedActionResolver Resolves enabled protected forms.
     * @param StateManager $stateManager Filters native state after controller dispatch.
     */
    public function __construct(
        private readonly ProtectedActionResolver $protectedActionResolver,
        private readonly StateManager $stateManager
    ) {
    }

    /**
     * Removes secrets from state written by a native controller after verification succeeds.
     *
     * @param Observer $observer Generic controller postdispatch event observer.
     */
    public function execute(Observer $observer): void
    {
        $action = $observer->getData('controller_action');
        $request = $observer->getData('request');
        if (!is_object($action) || !$request instanceof Http) {
            return;
        }

        $form = $this->protectedActionResolver->resolve($action, $request);
        if ($form === null) {
            return;
        }

        $this->stateManager->scrub($form);
    }
}
