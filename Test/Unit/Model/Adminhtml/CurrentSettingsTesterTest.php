<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Unit\Model\Adminhtml;

use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\Client;
use PrivateCaptcha\Enums\VerifyCode;
use PrivateCaptcha\Models\VerifyOutput;
use PrivateCaptcha\PrivateCaptcha\Model\Adminhtml\CurrentSettingsTester;
use PrivateCaptcha\PrivateCaptcha\Model\Validation\SdkClientFactory;

final class CurrentSettingsTesterTest extends TestCase
{
    private const TEST_SITE_KEY = 'aaaaaaaabbbbccccddddeeeeeeeeeeee';

    public function testAcceptsOnlyTheTestPropertyVerificationResult(): void
    {
        $httpClient = $this->createMock(Curl::class);
        $httpClient->expects(self::once())
            ->method('get')
            ->with('https://api.privatecaptcha.test/puzzle?sitekey=' . self::TEST_SITE_KEY);
        $httpClient->method('getStatus')->willReturn(200);
        $httpClient->method('getBody')->willReturn('test-puzzle');

        $client = $this->createMock(Client::class);
        $client->method('getDomain')->willReturn('api.privatecaptcha.test');
        $client->expects(self::once())
            ->method('verify')
            ->with(
                base64_encode(str_repeat("\0", 16 * 8)) . '.test-puzzle',
                self::anything(),
                self::anything(),
                self::TEST_SITE_KEY
            )
            ->willReturn(new VerifyOutput(true, VerifyCode::TEST_PROPERTY_ERROR));

        $clientFactory = $this->createMock(SdkClientFactory::class);
        $clientFactory->expects(self::once())
            ->method('create')
            ->with('api-key', 'api.privatecaptcha.test')
            ->willReturn($client);

        $tester = new CurrentSettingsTester($clientFactory, $httpClient);

        self::assertTrue($tester->test('api-key', 'api.privatecaptcha.test'));
    }

    public function testRejectsAnUnavailablePuzzleEndpointWithoutVerification(): void
    {
        $httpClient = $this->createMock(Curl::class);
        $httpClient->expects(self::once())
            ->method('get')
            ->with('https://api.privatecaptcha.test/puzzle?sitekey=' . self::TEST_SITE_KEY);
        $httpClient->method('getStatus')->willReturn(503);

        $client = $this->createMock(Client::class);
        $client->method('getDomain')->willReturn('api.privatecaptcha.test');
        $client->expects(self::never())->method('verify');

        $clientFactory = $this->createStub(SdkClientFactory::class);
        $clientFactory->method('create')->willReturn($client);

        $tester = new CurrentSettingsTester($clientFactory, $httpClient);

        self::assertFalse($tester->test('api-key', 'api.privatecaptcha.test'));
    }

    public function testUsesTheFinalResponseFromASameHostRedirect(): void
    {
        $requestedUrls = [];
        $statuses = [302, 200];
        $httpClient = $this->createStub(Curl::class);
        $httpClient->method('get')
            ->willReturnCallback(static function (string $url) use (&$requestedUrls): void {
                $requestedUrls[] = $url;
            });
        $httpClient->method('getStatus')->willReturnCallback(
            static function () use (&$statuses): int {
                return array_shift($statuses) ?? 500;
            }
        );
        $httpClient->method('getBody')->willReturnOnConsecutiveCalls('', 'test-puzzle');
        $httpClient->method('getHeaders')->willReturn(['Location' => '/test-puzzle']);

        $client = $this->createStub(Client::class);
        $client->method('getDomain')->willReturn('api.privatecaptcha.test');
        $client->method('verify')->willReturn(new VerifyOutput(true, VerifyCode::TEST_PROPERTY_ERROR));
        $clientFactory = $this->createStub(SdkClientFactory::class);
        $clientFactory->method('create')->willReturn($client);

        $tester = new CurrentSettingsTester($clientFactory, $httpClient);

        self::assertTrue($tester->test('api-key', 'api.privatecaptcha.test'));
        self::assertSame([
            'https://api.privatecaptcha.test/puzzle?sitekey=' . self::TEST_SITE_KEY,
            'https://api.privatecaptcha.test/test-puzzle',
        ], $requestedUrls);
    }
}
