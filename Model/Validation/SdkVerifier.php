<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Model\Validation;

use PrivateCaptcha\Exceptions\PrivateCaptchaException;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use Psr\Log\LoggerInterface;

class SdkVerifier implements VerifierInterface
{
    public const MAX_SOLUTION_BYTES = 262144;

    private const MAX_BACKOFF_SECONDS = 1;
    private const ATTEMPTS = 2;
    private const MAX_REQUEST_ID_LENGTH = 128;

    public function __construct(
        private readonly SdkClientFactory $clientFactory,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isValid(string $solution, int $storeId, string $form): bool
    {
        if ($solution === '' || strlen($solution) > self::MAX_SOLUTION_BYTES) {
            return false;
        }

        $apiKey = $this->config->getApiKey($storeId);
        $siteKey = $this->config->getSiteKey($storeId);
        if (trim($apiKey) === '' || trim($siteKey) === '') {
            return false;
        }

        try {
            $result = $this->clientFactory->create($apiKey, $this->config->getVerificationDomain($storeId))
                ->verify($solution, self::MAX_BACKOFF_SECONDS, self::ATTEMPTS, $siteKey);

            if ($result->isOK()) {
                return true;
            }

            $this->logRejectedResult($form, (string) $result, $result->getRequestId(), $result->getAttempt());
        } catch (PrivateCaptchaException $exception) {
            $this->logException('warning', 'Private Captcha verification failed.', $form, $exception);
        } catch (\Throwable $exception) {
            $this->logException('error', 'Private Captcha returned an unexpected response.', $form, $exception);
        }

        return false;
    }

    private function logRejectedResult(string $form, string $code, ?string $requestId, ?int $attempt): void
    {
        if (!$this->config->isDebugMode()) {
            return;
        }

        $this->logger->debug(
            'Private Captcha verification was rejected.',
            [
                'form' => $this->sanitizeForm($form),
                'code' => $code,
                'request_id' => $this->sanitizeRequestId($requestId),
                'attempt' => $attempt,
            ]
        );
    }

    private function logException(string $level, string $message, string $form, \Throwable $exception): void
    {
        if (!$this->config->isDebugMode()) {
            return;
        }

        $this->logger->{$level}($message, ['form' => $this->sanitizeForm($form), 'exception' => $exception::class]);
    }

    private function sanitizeRequestId(?string $requestId): ?string
    {
        if ($requestId === null) {
            return null;
        }

        $requestId = preg_replace('/[^A-Za-z0-9._-]/', '', $requestId);
        if ($requestId === null || $requestId === '') {
            return null;
        }

        return substr($requestId, 0, self::MAX_REQUEST_ID_LENGTH);
    }

    private function sanitizeForm(string $form): string
    {
        return isset(Config::FORM_PATHS[$form]) ? $form : 'unknown';
    }
}
