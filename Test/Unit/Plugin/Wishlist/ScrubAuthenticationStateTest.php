<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Unit\Plugin\Wishlist;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Request\Http;
use Magento\Framework\UrlInterface;
use Magento\Wishlist\Controller\Index\Send;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\Form\SensitiveDataFilter;
use PrivateCaptcha\PrivateCaptcha\Plugin\Wishlist\ScrubAuthenticationState;

final class ScrubAuthenticationStateTest extends TestCase
{
    public function testDropsAnInvalidWishlistIdFromAuthenticationState(): void
    {
        $config = $this->enabledConfig();
        $session = new ScrubAuthenticationStateTestSession([
            'emails' => 'charles@example.test',
            'wishlist_id' => '042',
            'private-captcha-solution' => 'solution',
        ]);
        $request = $this->createMock(Http::class);
        $request->expects(self::once())->method('isPost')->willReturn(true);
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->expects(self::once())
            ->method('getUrl')
            ->with('wishlist/index/share', [])
            ->willReturn('https://store.example.test/wishlist/index/share');
        $subject = (new \ReflectionClass(Send::class))->newInstanceWithoutConstructor();
        $result = new \stdClass();

        (new ScrubAuthenticationState($session, $config, new SensitiveDataFilter(), $urlBuilder))->afterDispatch(
            $subject,
            $result,
            $request
        );

        self::assertSame([
            ['getData', ['before_wishlist_request']],
            ['setData', ['before_wishlist_request', ['emails' => 'charles@example.test']]],
            ['setData', ['before_request_params', ['emails' => 'charles@example.test']]],
            ['setBeforeWishlistUrl', ['https://store.example.test/wishlist/index/share']],
        ], $session->calls);
    }

    private function enabledConfig(): Config
    {
        $config = $this->createMock(Config::class);
        $config->expects(self::once())
            ->method('isFormEnabled')
            ->with(Config::FORM_WISHLIST_SHARE)
            ->willReturn(true);

        return $config;
    }
}

final class ScrubAuthenticationStateTestSession extends CustomerSession
{
    /** @var array<int, array{string, array}> */
    public array $calls = [];

    /**
     * @param array<string, mixed> $savedRequest
     */
    public function __construct(
        private readonly array $savedRequest
    ) {
    }

    public function isLoggedIn()
    {
        return false;
    }

    public function __call($method, $arguments): mixed
    {
        $this->calls[] = [$method, $arguments];

        return $method === 'getData' && ($arguments[0] ?? null) === 'before_wishlist_request'
            ? $this->savedRequest
            : null;
    }
}
