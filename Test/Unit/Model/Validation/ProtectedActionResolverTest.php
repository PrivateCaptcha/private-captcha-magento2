<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Unit\Model\Validation;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Request\Http;
use Magento\SendFriend\Helper\Data as SendFriendHelper;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\Model\Validation\ProtectedActionResolver;

require_once dirname(__DIR__, 4) . '/Model/Validation/ProtectedActionResolver.php';

final class ProtectedActionResolverTest extends TestCase
{
    public function testResolvesAnInterceptorSubclassWithoutUsingTheRouteName(): void
    {
        $config = $this->createMock(Config::class);
        $config->expects(self::once())
            ->method('isFormEnabled')
            ->with(Config::FORM_CONTACT)
            ->willReturn(true);
        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects(self::never())->method('isLoggedIn');
        $request = $this->createMock(Http::class);
        $request->expects(self::once())->method('getMethod')->willReturn('pOsT');
        $request->expects(self::never())->method('getFullActionName');
        $resolver = new ProtectedActionResolver($config, $customerSession, [
            Config::FORM_CONTACT => [
                'controller' => ProtectedActionResolverTestAction::class,
                'method' => 'POST',
            ],
        ]);

        self::assertSame(
            Config::FORM_CONTACT,
            $resolver->resolve(new ProtectedActionResolverTestInterceptor(), $request)
        );
    }

    public function testSkipsEmailToFriendWithoutAPositiveProductIdBeforeReadingNativeEligibility(): void
    {
        $config = $this->createMock(Config::class);
        $config->expects(self::once())
            ->method('isFormEnabled')
            ->with(Config::FORM_EMAIL_TO_FRIEND)
            ->willReturn(true);
        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects(self::never())->method('isLoggedIn');
        $sendFriendHelper = $this->createMock(SendFriendHelper::class);
        $sendFriendHelper->expects(self::never())->method('isEnabled');
        $request = $this->createMock(Http::class);
        $request->expects(self::once())->method('getMethod')->willReturn('POST');
        $request->expects(self::once())->method('getParam')->with('id')->willReturn('0');
        $resolver = new ProtectedActionResolver(
            $config,
            $customerSession,
            [
                Config::FORM_EMAIL_TO_FRIEND => [
                    'controller' => ProtectedActionResolverTestAction::class,
                    'method' => 'POST',
                    'sendfriend_eligible' => true,
                ],
            ],
            null,
            null,
            $sendFriendHelper
        );

        self::assertNull($resolver->resolve(new ProtectedActionResolverTestAction(), $request));
    }
}

class ProtectedActionResolverTestAction
{
}

final class ProtectedActionResolverTestInterceptor extends ProtectedActionResolverTestAction
{
}
