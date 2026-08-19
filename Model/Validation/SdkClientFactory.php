<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Validation;

use PrivateCaptcha\Client;

class SdkClientFactory
{
    private const TIMEOUT_SECONDS = 5;

    public function create(string $apiKey, ?string $domain): Client
    {
        return new Client($apiKey, $domain, Client::DEFAULT_FORM_FIELD, self::TIMEOUT_SECONDS);
    }
}
