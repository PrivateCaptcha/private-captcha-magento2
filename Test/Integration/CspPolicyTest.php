<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Integration;

use Magento\Csp\Api\ModeConfigManagerInterface;
use Magento\Framework\App\Http;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\PageCache\Model\Cache\Type as PageCache;
use Magento\PageCache\Model\Config as PageCacheConfig;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManager;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Laminas\Stdlib\Parameters;
use PHPUnit\Framework\TestCase;

final class CspPolicyTest extends TestCase
{
    /**
     * @magentoAppArea frontend
     * @magentoCache full_page enabled
     * @magentoDbIsolation disabled
     * @magentoDataFixture Magento/Store/_files/second_website_with_two_stores.php
     * @magentoConfigFixture default system/full_page_cache/caching_application 1
     * @magentoConfigFixture current_website private_captcha/advanced/custom_domain website-a.example.test
     * @magentoConfigFixture test_website private_captcha/advanced/custom_domain website-b.example.test
     */
    public function testResponseHeadersRemainWebsiteScopedOnColdAndWarmBuiltinPageCacheResponses(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $pageCache = $objectManager->get(PageCache::class);
        $storeManager = $objectManager->get(StoreManagerInterface::class);
        $originalStore = $storeManager->getStore();
        $reportOnlyBefore = $objectManager->get(ModeConfigManagerInterface::class)->getConfigured()->isReportOnly();
        self::assertSame(PageCacheConfig::BUILT_IN, $objectManager->get(PageCacheConfig::class)->getType());
        $pageCache->clean();

        try {
            $websiteACold = $this->dispatchCacheableCmsPage('default');
            self::assertSame('MISS', $this->getCacheDebugHeader($websiteACold));
            $this->assertWebsiteCsp($websiteACold, 'website-a.example.test', $reportOnlyBefore);

            $websiteAWarm = $this->dispatchCacheableCmsPage('default');
            self::assertSame('HIT', $this->getCacheDebugHeader($websiteAWarm));
            $this->assertWebsiteCsp($websiteAWarm, 'website-a.example.test', $reportOnlyBefore);

            $websiteBCold = $this->dispatchCacheableCmsPage('fixture_second_store');
            self::assertSame('MISS', $this->getCacheDebugHeader($websiteBCold));
            $this->assertWebsiteCsp($websiteBCold, 'website-b.example.test', $reportOnlyBefore);

            $websiteBWarm = $this->dispatchCacheableCmsPage('fixture_second_store');
            self::assertSame('HIT', $this->getCacheDebugHeader($websiteBWarm));
            $this->assertWebsiteCsp($websiteBWarm, 'website-b.example.test', $reportOnlyBefore);

            self::assertSame(
                $reportOnlyBefore,
                Bootstrap::getObjectManager()->get(ModeConfigManagerInterface::class)->getConfigured()->isReportOnly()
            );
        } finally {
            $storeManager->setCurrentStore($originalStore->getId());
            Bootstrap::getObjectManager()->get(PageCache::class)->clean();
        }
    }

    private function dispatchCacheableCmsPage(string $storeCode): HttpResponse
    {
        $objectManager = Bootstrap::getObjectManager();
        $objectManager->get(StoreManagerInterface::class)->setCurrentStore($storeCode);
        $response = $objectManager->get(HttpResponse::class);
        $response->getHeaders()->clearHeaders();
        $response->setBody('');
        $response->setHttpResponseCode(200);

        $request = $objectManager->get(HttpRequest::class);
        $request->setMethod(HttpRequest::METHOD_GET);
        $request->setDispatched(false);
        $request->setPathInfo('/cms/page/view/page_id/2');
        $request->setRequestUri('/cms/page/view/page_id/2');
        $request->setUri('/cms/page/view/page_id/2');
        // Keep each simulated browser session on one stable FPC vary key.
        $request->setParam(HttpResponse::COOKIE_VARY_STRING, 'private-captcha-fpc-' . $storeCode);
        $request->setServer(new Parameters(array_merge($_SERVER, [
            StoreManager::PARAM_RUN_TYPE => ScopeInterface::SCOPE_STORE,
            StoreManager::PARAM_RUN_CODE => $storeCode,
        ])));

        try {
            return $objectManager->create(Http::class)->launch();
        } finally {
            $this->removeIgnitionHandlers();
        }
    }

    private function removeIgnitionHandlers(): void
    {
        $errorHandler = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        if (is_array($errorHandler) && $errorHandler[0] instanceof \Swissup\Ignition\Model\Ignition) {
            restore_error_handler();
        }

        $exceptionHandler = set_exception_handler(static function (): void {
        });
        restore_exception_handler();
        if (is_array($exceptionHandler) && $exceptionHandler[0] instanceof \Swissup\Ignition\Model\Ignition) {
            restore_exception_handler();
        }
    }

    private function getCacheDebugHeader(HttpResponse $response): string
    {
        $header = $response->getHeader('X-Magento-Cache-Debug');
        self::assertNotFalse(
            $header,
            sprintf(
                'Expected a cacheable response, received HTTP %d with headers: %s',
                $response->getHttpResponseCode(),
                $response->getHeaders()->toString()
            )
        );

        return $header->getFieldValue();
    }

    private function assertWebsiteCsp(
        HttpResponse $response,
        string $expectedRoot,
        bool $reportOnly
    ): void {
        $headerName = $reportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        $header = $response->getHeader($headerName);
        self::assertNotFalse($header);
        self::assertFalse($response->getHeader(
            $reportOnly ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only'
        ));

        foreach (['script-src', 'frame-src', 'style-src', 'connect-src'] as $directive) {
            $sources = $this->getDirectiveSources($header->getFieldValue(), $directive);
            self::assertContains('https://privatecaptcha.com', $sources);
            self::assertContains('https://*.privatecaptcha.com', $sources);
            self::assertSame(
                ['https://cdn.' . $expectedRoot, 'https://api.' . $expectedRoot],
                array_values(array_filter(
                    $sources,
                    static fn (string $source): bool => str_ends_with($source, '.example.test')
                ))
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function getDirectiveSources(string $headerValue, string $expectedDirective): array
    {
        $matchingSources = [];
        foreach (explode(';', $headerValue) as $policy) {
            $sources = preg_split('/\s+/', trim($policy), 2);
            if (($sources[0] ?? '') === $expectedDirective) {
                $matchingSources[] = isset($sources[1]) ? explode(' ', $sources[1]) : [];
            }
        }

        if (count($matchingSources) !== 1) {
            self::fail(sprintf(
                'Expected exactly one %s directive in the CSP response header; found %d.',
                $expectedDirective,
                count($matchingSources)
            ));
        }

        return $matchingSources[0];
    }
}
