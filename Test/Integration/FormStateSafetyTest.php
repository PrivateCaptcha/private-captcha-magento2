<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Integration;

use Magento\Contact\Controller\Index\Post as ContactPost;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Event\ManagerInterface;
use PHPUnit\Framework\TestCase;

final class FormStateSafetyTest extends TestCase
{
    /**
     * @magentoAppArea frontend
     */
    public function testProductionInactiveMappingsDoNotAlterNativeState(): void
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $persistor = $objectManager->get(DataPersistorInterface::class);
        $persistor->set('contact_us', [
            'name' => 'Ada Lovelace',
            'private-captcha-solution' => 'solution',
        ]);

        try {
            $objectManager->get(ManagerInterface::class)->dispatch('controller_action_postdispatch', [
                'controller_action' => $objectManager->create(ContactPost::class),
                'request' => $objectManager->get(Http::class),
            ]);

            self::assertSame([
                'name' => 'Ada Lovelace',
                'private-captcha-solution' => 'solution',
            ], $persistor->get('contact_us'));
        } finally {
            $persistor->clear('contact_us');
        }
    }

}
