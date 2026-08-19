<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Provider\Failure;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http;
use Magento\Framework\Message\ManagerInterface;
use PrivateCaptcha\PrivateCaptcha\Model\Form\StateManager;
use PrivateCaptcha\PrivateCaptcha\Model\Provider\FailureProviderInterface;

class NormalFailureProvider implements FailureProviderInterface
{
    /**
     * @param ActionFlag $actionFlag Prevents the native controller side effect.
     * @param ManagerInterface $messageManager Adds the generic customer-visible error.
     * @param InternalRedirect $internalRedirect Uses only configured internal routes.
     * @param StateManager $stateManager Persists only permitted form data before no-dispatch.
     * @param HttpRequest $request Current frontend request.
     */
    public function __construct(
        private readonly ActionFlag $actionFlag,
        private readonly ManagerInterface $messageManager,
        private readonly InternalRedirect $internalRedirect,
        private readonly StateManager $stateManager,
        private readonly HttpRequest $request
    ) {
    }

    /**
     * Stops a normal form submission, adds one generic message, and redirects internally.
     *
     * @param Http $response Frontend HTTP response.
     * @param string $form Resolved protected form identifier.
     */
    public function fail(Http $response, string $form): void
    {
        $this->stateManager->persistFailure($form, $this->request);
        $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, 'true');
        $this->messageManager->addErrorMessage(__('Private Captcha verification failed. Please try again.'));
        $this->internalRedirect->redirect($response, $form);
    }
}
