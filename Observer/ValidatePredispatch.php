<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Observer;

use Magento\Framework\App\Action\AbstractAction;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Review\Controller\Product\Post as ProductReviewPost;
use Magento\SendFriend\Controller\Product\Sendmail as SendFriendSendmail;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Controller\Ajax\Login as AjaxLogin;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\Form\StateManager;
use PrivateCaptcha\PrivateCaptcha\Model\Provider\AjaxSolutionProvider;
use PrivateCaptcha\PrivateCaptcha\Model\Provider\DefaultSolutionProvider;
use PrivateCaptcha\PrivateCaptcha\Model\Provider\Failure\AjaxFailureProvider;
use PrivateCaptcha\PrivateCaptcha\Model\Provider\Failure\NormalFailureProvider;
use PrivateCaptcha\PrivateCaptcha\Model\Validation\ProtectedActionResolver;
use PrivateCaptcha\PrivateCaptcha\Model\Validation\VerifierInterface;

class ValidatePredispatch implements ObserverInterface
{
    /**
     * @param ProtectedActionResolver $protectedActionResolver Resolves eligible protected forms.
     * @param DefaultSolutionProvider $solutionProvider Extracts and removes a normal POST solution.
     * @param AjaxSolutionProvider $ajaxSolutionProvider Extracts and removes an AJAX JSON solution.
     * @param VerifierInterface $verifier Verifies the solution against the current website.
     * @param NormalFailureProvider $failureProvider Stops failed normal form submissions safely.
     * @param AjaxFailureProvider $ajaxFailureProvider Stops failed AJAX submissions with JSON.
     * @param StateManager $stateManager Removes unsafe native-controller inputs after success.
     * @param StoreManagerInterface $storeManager Resolves the active store for verification.
     */
    public function __construct(
        private readonly ProtectedActionResolver $protectedActionResolver,
        private readonly DefaultSolutionProvider $solutionProvider,
        private readonly AjaxSolutionProvider $ajaxSolutionProvider,
        private readonly VerifierInterface $verifier,
        private readonly NormalFailureProvider $failureProvider,
        private readonly AjaxFailureProvider $ajaxFailureProvider,
        private readonly StateManager $stateManager,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Validates an enabled normal protected form before native controller dispatch.
     *
     * @param Observer $observer Generic controller predispatch event observer.
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
            if ($action instanceof ProductReviewPost &&
                $this->protectedActionResolver->shouldSanitizeIneligibleReview($action, $request)) {
                $this->stateManager->sanitizeIneligibleRequest(Config::FORM_PRODUCT_REVIEW, $request);
            } elseif ($action instanceof SendFriendSendmail &&
                $this->protectedActionResolver->shouldSanitizeIneligibleEmailToFriend($action, $request)) {
                $this->stateManager->sanitizeIneligibleRequest(Config::FORM_EMAIL_TO_FRIEND, $request);
            }

            return;
        }

        $isAjax = $action instanceof AjaxLogin;
        $solution = $isAjax ? $this->ajaxSolutionProvider->getSolution() : $this->solutionProvider->getSolution();
        if ($solution !== null && $this->verifier->isValid(
            $solution,
            (int) $this->storeManager->getStore()->getId(),
            $form
        )) {
            $this->stateManager->sanitizeRequest($form, $request);

            return;
        }

        $response = $action instanceof AbstractAction ? $action->getResponse() : null;
        if ($response instanceof HttpResponse) {
            ($isAjax ? $this->ajaxFailureProvider : $this->failureProvider)->fail($response, $form);
        }
    }
}
