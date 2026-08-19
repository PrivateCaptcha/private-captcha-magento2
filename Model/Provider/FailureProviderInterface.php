<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Provider;

use Magento\Framework\App\Response\Http;

interface FailureProviderInterface
{
    /**
     * Stops dispatch and writes the failure response for one protected form.
     *
     * @param Http $response Frontend HTTP response.
     * @param string $form Resolved protected form identifier.
     */
    public function fail(Http $response, string $form): void;
}
