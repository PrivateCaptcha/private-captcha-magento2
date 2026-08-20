<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Unit\Model\Validation;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\Client;
use PrivateCaptcha\Exceptions\PrivateCaptchaException;
use PrivateCaptcha\Models\VerifyOutput;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\CustomDomain;
use PrivateCaptcha\PrivateCaptcha\Model\Validation\SdkClientFactory;
use PrivateCaptcha\PrivateCaptcha\Model\Validation\SdkVerifier;
use Psr\Log\LoggerInterface;

final class SdkVerifierTest extends TestCase
{
    private const STORE_ID = 3;
    private const FORM = Config::FORM_CONTACT;

    public function testExactSolutionLimitIsVerifiedWithRequiredArguments(): void
    {
        $solution = str_repeat('a', SdkVerifier::MAX_SOLUTION_BYTES);
        $client = $this->createClient(
            function (string $actualSolution, int $backoff, int $attempts, ?string $siteKey) use ($solution): object {
                self::assertSame($solution, $actualSolution);
                self::assertSame(1, $backoff);
                self::assertSame(2, $attempts);
                self::assertSame('site-key', $siteKey);

                return $this->createOutput(true);
            }
        );
        $factory = new SdkVerifierTestFactory($client);
        $verifier = new SdkVerifier($factory, $this->createConfig(), $this->createStub(LoggerInterface::class));

        self::assertTrue($verifier->isValid($solution, self::STORE_ID, self::FORM));
        self::assertSame([['api-key', 'verify.example.test']], $factory->calls);
    }

    #[DataProvider('invalidSolutionProvider')]
    public function testInvalidSolutionsAreRejectedBeforeClientConstruction(string $solution): void
    {
        $factory = new SdkVerifierTestFactory($this->createStub(Client::class));
        $verifier = new SdkVerifier($factory, $this->createConfig(), $this->createStub(LoggerInterface::class));

        self::assertFalse($verifier->isValid($solution, self::STORE_ID, self::FORM));
        self::assertSame([], $factory->calls);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSolutionProvider(): array
    {
        return [
            'empty' => [''],
            'too large' => [str_repeat('a', SdkVerifier::MAX_SOLUTION_BYTES + 1)],
        ];
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('missingCredentialProvider')]
    public function testMissingCredentialsAreRejectedBeforeClientConstruction(array $values): void
    {
        $factory = new SdkVerifierTestFactory($this->createStub(Client::class));
        $verifier = new SdkVerifier($factory, $this->createConfig($values), $this->createStub(LoggerInterface::class));

        self::assertFalse($verifier->isValid('solution', self::STORE_ID, self::FORM));
        self::assertSame([], $factory->calls);
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function missingCredentialProvider(): array
    {
        return [
            'API Key' => [[Config::PATH_API_KEY => '']],
            'Site Key' => [[Config::PATH_SITE_KEY => '']],
        ];
    }

    public function testNonOkResultFailsClosedAndLogsOnlySanitizedDiagnosticsInDebugMode(): void
    {
        $solution = 'complete-solution-must-not-be-logged';
        $apiKey = 'api-key-must-not-be-logged';
        $siteKey = 'site-key-must-not-be-logged';
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(
                'Private Captcha verification was rejected.',
                self::callback(
                    static function (array $context) use ($solution, $apiKey, $siteKey): bool {
                        self::assertSame('unknown', $context['form']);
                        self::assertSame('solution-invalid', $context['code']);
                        self::assertSame('request-id', $context['request_id']);
                        self::assertSame(2, $context['attempt']);
                        self::assertArrayNotHasKey('solution', $context);
                        self::assertStringNotContainsString($solution, serialize($context));
                        self::assertStringNotContainsString($apiKey, serialize($context));
                        self::assertStringNotContainsString($siteKey, serialize($context));

                        return true;
                    }
                )
            );
        $client = $this->createClient(
            fn (): object => $this->createOutput(false, 'solution-invalid', "request-/id\n", 2)
        );
        $verifier = new SdkVerifier(
            new SdkVerifierTestFactory($client),
            $this->createConfig([
                Config::PATH_API_KEY => $apiKey,
                Config::PATH_SITE_KEY => $siteKey,
            ], [Config::PATH_DEBUG_MODE => true]),
            $logger
        );

        self::assertFalse($verifier->isValid($solution, self::STORE_ID, $solution));
    }

    public function testSdkExceptionsFailClosedAndDoNotLogTheirMessages(): void
    {
        $solution = 'complete-solution-must-not-be-logged';
        $exception = new PrivateCaptchaException($solution);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'Private Captcha verification failed.',
                ['form' => self::FORM, 'exception' => $exception::class]
            );
        $client = $this->createClient(static function () use ($exception): never {
            throw $exception;
        });
        $verifier = new SdkVerifier(
            new SdkVerifierTestFactory($client),
            $this->createConfig([], [Config::PATH_DEBUG_MODE => true]),
            $logger
        );

        self::assertFalse($verifier->isValid($solution, self::STORE_ID, self::FORM));
    }

    public function testUnexpectedThrowablesFailClosedAndDoNotLogTheirMessages(): void
    {
        $solution = 'complete-solution-must-not-be-logged';
        $exception = new \TypeError($solution);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Private Captcha returned an unexpected response.',
                ['form' => self::FORM, 'exception' => $exception::class]
            );
        $client = $this->createClient(static function () use ($exception): never {
            throw $exception;
        });
        $verifier = new SdkVerifier(
            new SdkVerifierTestFactory($client),
            $this->createConfig([], [Config::PATH_DEBUG_MODE => true]),
            $logger
        );

        self::assertFalse($verifier->isValid($solution, self::STORE_ID, self::FORM));
    }

    public function testDiagnosticsAreDisabledOutsideDebugMode(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('debug');
        $client = $this->createClient(fn (): object => $this->createOutput(false));
        $verifier = new SdkVerifier(
            new SdkVerifierTestFactory($client),
            $this->createConfig(),
            $logger
        );

        self::assertFalse($verifier->isValid('solution', self::STORE_ID, self::FORM));
    }

    /**
     * @param callable(string, int, int, ?string): object $verify
     */
    private function createClient(callable $verify): Client
    {
        $client = $this->createStub(Client::class);
        $client->method('verify')->willReturnCallback($verify);

        return $client;
    }

    private function createOutput(
        bool $isOk,
        string $code = 'solution-invalid',
        ?string $requestId = null,
        ?int $attempt = null
    ): VerifyOutput {
        $output = $this->createStub(VerifyOutput::class);
        $output->method('isOK')->willReturn($isOk);
        $output->method('__toString')->willReturn($code);
        $output->method('getRequestId')->willReturn($requestId);
        $output->method('getAttempt')->willReturn($attempt);

        return $output;
    }

    /**
     * @param array<string, string> $values
     * @param array<string, bool> $flags
     */
    private function createConfig(array $values = [], array $flags = []): Config
    {
        $values += [
            Config::PATH_API_KEY => 'api-key',
            Config::PATH_SITE_KEY => 'site-key',
            Config::PATH_CUSTOM_DOMAIN => 'verify.example.test',
        ];

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path, string $scope, string $websiteCode) use ($values): string {
                self::assertSame(ScopeInterface::SCOPE_WEBSITE, $scope);
                self::assertSame('website-a', $websiteCode);

                return $values[$path] ?? '';
            }
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static function (string $path, string $scope, string $websiteCode) use ($flags): bool {
                self::assertSame(ScopeInterface::SCOPE_WEBSITE, $scope);
                self::assertSame('website-a', $websiteCode);

                return $flags[$path] ?? false;
            }
        );

        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getCode')->willReturn('website-a');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeManager->method('getWebsite')->willReturn($website);

        return new Config($scopeConfig, $storeManager, new CustomDomain());
    }
}

final class SdkVerifierTestFactory extends SdkClientFactory
{
    /**
     * @var list<array{string, ?string}>
     */
    public array $calls = [];

    public function __construct(
        private readonly Client $client
    ) {
    }

    public function create(string $apiKey, ?string $domain): Client
    {
        $this->calls[] = [$apiKey, $domain];

        return $this->client;
    }
}
