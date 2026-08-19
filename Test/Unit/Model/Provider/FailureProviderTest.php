<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Unit\Model\Provider;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http;
use Magento\Framework\App\Response\RedirectInterface;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\Provider\Failure\InternalRedirect;

require_once dirname(__DIR__, 4) . '/Model/Provider/Failure/InternalRedirect.php';

final class FailureProviderTest extends TestCase
{
    public function testInternalRedirectRejectsExternalAndRequestLikeRouteValues(): void
    {
        $response = $this->createStub(Http::class);
        $redirect = $this->createMock(RedirectInterface::class);
        $redirect->expects(self::never())->method('redirect');
        $internalRedirect = new InternalRedirect($redirect, [
            Config::FORM_CONTACT => 'https://attacker.example.test',
            Config::FORM_CUSTOMER_LOGIN => 'customer/account/login?return=https://attacker.example.test',
        ]);

        self::assertFalse($internalRedirect->redirect($response, Config::FORM_CONTACT));
        self::assertFalse($internalRedirect->redirect($response, Config::FORM_CUSTOMER_LOGIN));
    }

    public function testInternalRedirectFallsBackWhenAProductIdIsInvalid(): void
    {
        $response = $this->createStub(Http::class);
        $request = $this->createMock(HttpRequest::class);
        $request->expects(self::once())->method('getParam')->with('id')->willReturn('0');
        $redirect = $this->createMock(RedirectInterface::class);
        $redirect->expects(self::once())
            ->method('redirect')
            ->with($response, 'cms/index/index');
        $internalRedirect = new InternalRedirect($redirect, [
            Config::FORM_PRODUCT_REVIEW => [
                'route' => 'catalog/product/view',
                'parameters' => ['id' => 'positive_int'],
                'fallback' => 'cms/index/index',
            ],
        ], $request);

        self::assertTrue($internalRedirect->redirect($response, Config::FORM_PRODUCT_REVIEW));
    }

    public function testInternalRedirectOmitsAnInvalidOptionalCategoryId(): void
    {
        $response = $this->createStub(Http::class);
        $request = $this->createMock(HttpRequest::class);
        $request->expects(self::exactly(2))
            ->method('getParam')
            ->willReturnMap([
                ['id', 42],
                ['cat_id', '0'],
            ]);
        $redirect = $this->createMock(RedirectInterface::class);
        $redirect->expects(self::once())
            ->method('redirect')
            ->with($response, 'sendfriend/product/send', ['id' => 42]);
        $internalRedirect = new InternalRedirect($redirect, [
            Config::FORM_EMAIL_TO_FRIEND => [
                'route' => 'sendfriend/product/send',
                'parameters' => [
                    'id' => 'positive_int',
                    'cat_id' => 'optional_positive_int',
                ],
                'fallback' => 'cms/index/index',
            ],
        ], $request);

        self::assertTrue($internalRedirect->redirect($response, Config::FORM_EMAIL_TO_FRIEND));
    }
}
