<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Unit\ViewModel;

use PHPUnit\Framework\TestCase;
use PrivateCaptcha\PrivateCaptcha\Model\Config;
use PrivateCaptcha\PrivateCaptcha\ViewModel\WidgetConfig;

final class WidgetConfigTest extends TestCase
{
    public function testTemplateEscapesUntrustedAttributesWithoutRenderingSecrets(): void
    {
        $config = $this->createWidgetConfig(
            'site-key"<',
            'https://api.example.test/puzzle?<',
            'color: teal; "'
        );

        $html = $this->renderTemplate(new WidgetConfig($config));

        self::assertStringContainsString('data-sitekey="site-key&quot;&lt;"', $html);
        self::assertStringContainsString('data-puzzle-endpoint="https://api.example.test/puzzle?&lt;"', $html);
        self::assertStringContainsString('data-theme="dark"', $html);
        self::assertStringContainsString('data-styles="color: teal; &quot;"', $html);
        self::assertStringNotContainsString('site-key"<', $html);
        self::assertStringNotContainsString('https://api.example.test/puzzle?<', $html);
        self::assertStringNotContainsString('color: teal; "', $html);
    }

    private function createWidgetConfig(
        string $siteKey,
        ?string $puzzleEndpoint,
        string $styles
    ): Config {
        $config = $this->createMock(Config::class);
        $config->expects(self::never())->method('getApiKey');
        $config->method('isFormEnabled')->willReturn(true);
        $config->method('getSiteKey')->willReturn($siteKey);
        $config->method('getScriptUrl')->willReturn('https://cdn.example.test/widget/js/privatecaptcha.js');
        $config->method('getTheme')->willReturn('dark');
        $config->method('getLanguage')->willReturn('auto');
        $config->method('getStartMode')->willReturn('auto');
        $config->method('isEuIsolation')->willReturn(false);
        $config->method('isDebugMode')->willReturn(false);
        $config->method('getPuzzleEndpoint')->willReturn($puzzleEndpoint);
        $config->method('getCustomStyles')->willReturn($styles);

        return $config;
    }

    private function renderTemplate(WidgetConfig $viewModel): string
    {
        $block = new class($viewModel) {
            /** @var array<string, mixed> */
            private array $data;

            public function __construct(WidgetConfig $viewModel)
            {
                $this->data = [
                    'view_model' => $viewModel,
                    'private_captcha_form' => Config::FORM_CONTACT,
                    'private_captcha_instance_id' => 'contact-form',
                    'private_captcha_store_id' => 3,
                ];
            }

            public function getData(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function hasData(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }

        };
        $escaper = new class {
            public function escapeHtmlAttr(string $value): string
            {
                return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        };

        ob_start();
        require dirname(__DIR__, 3) . '/view/frontend/templates/widget.phtml';

        return (string) ob_get_clean();
    }
}
