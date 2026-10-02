<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Adminhtml;

use Magento\Framework\HTTP\Client\Curl;
use PrivateCaptcha\Enums\VerifyCode;
use PrivateCaptcha\PrivateCaptcha\Model\Validation\SdkClientFactory;

class CurrentSettingsTester
{
    private const TEST_SITE_KEY = 'aaaaaaaabbbbccccddddeeeeeeeeeeee';
    private const SOLUTIONS_COUNT = 16;
    private const SOLUTION_LENGTH = 8;
    private const TIMEOUT_SECONDS = 10;
    private const MAX_REDIRECTS = 5;

    public function __construct(
        private readonly SdkClientFactory $clientFactory,
        private readonly Curl $httpClient
    ) {
    }

    public function test(string $apiKey, ?string $domain): bool
    {
        if (trim($apiKey) === '') {
            return false;
        }

        try {
            $client = $this->clientFactory->create($apiKey, $domain);
            $this->httpClient->setTimeout(self::TIMEOUT_SECONDS);
            $this->httpClient->addHeader('Origin', 'not.empty');
            $puzzle = $this->fetchTestPuzzle(sprintf(
                'https://%s/puzzle?sitekey=%s',
                $client->getDomain(),
                self::TEST_SITE_KEY
            ));
            if ($puzzle === null) {
                return false;
            }

            $solutions = base64_encode(str_repeat("\0", self::SOLUTIONS_COUNT * self::SOLUTION_LENGTH));
            $output = $client->verify($solutions . '.' . $puzzle, sitekey: self::TEST_SITE_KEY);

            return $output->success && $output->code === VerifyCode::TEST_PROPERTY_ERROR;
        } catch (\Throwable) {
            return false;
        }
    }

    private function fetchTestPuzzle(string $url): ?string
    {
        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $this->httpClient->get($url);
            $status = $this->httpClient->getStatus();
            $body = $this->httpClient->getBody();
            if ($status === 200) {
                return $body === '' ? null : $body;
            }

            if ($status < 300 || $status >= 400 || $redirects === self::MAX_REDIRECTS) {
                return null;
            }

            $url = $this->getRedirectUrl($url, $this->httpClient->getHeaders());
            if ($url === null) {
                return null;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $headers */
    private function getRedirectUrl(string $currentUrl, array $headers): ?string
    {
        $location = null;
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Location') === 0 && is_string($value)) {
                $location = $value;
                break;
            }
        }
        if ($location === null || $location === '') {
            return null;
        }

        $currentHost = parse_url($currentUrl, PHP_URL_HOST);
        if (str_starts_with($location, '/')) {
            return is_string($currentHost) ? 'https://' . $currentHost . $location : null;
        }

        $scheme = parse_url($location, PHP_URL_SCHEME);
        $host = parse_url($location, PHP_URL_HOST);
        $port = parse_url($location, PHP_URL_PORT);
        $currentPort = parse_url($currentUrl, PHP_URL_PORT);

        return $scheme === 'https'
            && is_string($host)
            && is_string($currentHost)
            && strcasecmp($host, $currentHost) === 0
            && $port === $currentPort
            && parse_url($location, PHP_URL_USER) === null
            && parse_url($location, PHP_URL_PASS) === null
                ? $location
                : null;
    }
}
