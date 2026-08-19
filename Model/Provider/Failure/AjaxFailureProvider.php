<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Provider\Failure;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Response\Http;
use Magento\Framework\Serialize\Serializer\Json;
use PrivateCaptcha\PrivateCaptcha\Model\Provider\FailureProviderInterface;

class AjaxFailureProvider implements FailureProviderInterface
{
    /**
     * @param ActionFlag $actionFlag Prevents the native AJAX login action.
     * @param Json $json Serializes the Magento-compatible JSON response.
     */
    public function __construct(
        private readonly ActionFlag $actionFlag,
        private readonly Json $json
    ) {
    }

    /**
     * Stops the native AJAX login action and writes its JSON error contract.
     *
     * @param Http $response Frontend HTTP response.
     * @param string $form Resolved protected form identifier.
     */
    public function fail(Http $response, string $form): void
    {
        $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, 'true');
        $response->representJson($this->json->serialize([
            'errors' => true,
            'message' => (string) __('Private Captcha verification failed. Please try again.'),
        ]));
    }
}
