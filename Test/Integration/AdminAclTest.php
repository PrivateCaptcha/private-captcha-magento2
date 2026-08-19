<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Integration;

use Magento\Framework\Acl\Builder as AclBuilder;
use PHPUnit\Framework\TestCase;

final class AdminAclTest extends TestCase
{
    /**
     * @magentoAppArea adminhtml
     */
    public function testPrivateCaptchaConfigurationResourceCanBeBuilt(): void
    {
        $acl = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(AclBuilder::class)->getAcl();

        self::assertTrue($acl->hasResource('PrivateCaptcha_PrivateCaptcha::config'));
    }
}
