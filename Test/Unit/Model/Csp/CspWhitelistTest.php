<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Unit\Model\Csp;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class CspWhitelistTest extends TestCase
{
    public function testModuleConfigurationDoesNotOverrideTheGlobalCspMode(): void
    {
        $document = new DOMDocument();
        $document->load(dirname(__DIR__, 4) . '/etc/config.xml');

        self::assertSame(0, (new DOMXPath($document))->query('/config/default/csp')->count());
    }
}
