<?php

declare(strict_types=1);

namespace PrivateCaptcha\PrivateCaptcha\Test\Unit\Model\Provider;

use Laminas\Stdlib\Parameters;
use Magento\Framework\App\Request\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\PrivateCaptcha\Model\Provider\AjaxSolutionProvider;
use PrivateCaptcha\PrivateCaptcha\Model\Provider\DefaultSolutionProvider;
use PrivateCaptcha\PrivateCaptcha\Model\Provider\SolutionProviderInterface;

final class SolutionProviderTest extends TestCase
{
    public function testDefaultProviderReturnsTheExactNonEmptyPostString(): void
    {
        $solution = "  solution\x00with-whitespace  ";
        $request = $this->createMock(Http::class);
        $request->method('isPost')->willReturn(true);
        $request->expects(self::once())
            ->method('getPostValue')
            ->with(SolutionProviderInterface::SOLUTION_FIELD)
            ->willReturn($solution);
        $request->expects(self::never())->method('getParam');

        self::assertSame($solution, (new DefaultSolutionProvider($request))->getSolution());
    }

    public function testDefaultProviderDoesNotReadQueryParametersOrNonPostRequests(): void
    {
        $request = $this->createMock(Http::class);
        $request->method('isPost')->willReturn(false);
        $request->expects(self::never())->method('getPostValue');
        $request->expects(self::never())->method('getParam');

        self::assertNull((new DefaultSolutionProvider($request))->getSolution());
    }

    public function testDefaultProviderAcceptsTheExactSolutionLimit(): void
    {
        $solution = str_repeat('a', SolutionProviderInterface::MAX_SOLUTION_BYTES);
        $request = $this->createStub(Http::class);
        $request->method('isPost')->willReturn(true);
        $request->method('getPostValue')->willReturn($solution);

        self::assertSame($solution, (new DefaultSolutionProvider($request))->getSolution());
    }

    public function testDefaultProviderRemovesTheSolutionFromEveryRequestParameterSource(): void
    {
        $solution = 'solution';
        $post = new Parameters([SolutionProviderInterface::SOLUTION_FIELD => $solution]);
        $query = new Parameters([SolutionProviderInterface::SOLUTION_FIELD => 'query-solution']);
        $request = $this->createMock(Http::class);
        $request->method('isPost')->willReturn(true);
        $request->method('getPostValue')->willReturn($solution);
        $request->method('getPost')->willReturn($post);
        $request->method('getQuery')->willReturn($query);
        $request->expects(self::once())
            ->method('setParam')
            ->with(SolutionProviderInterface::SOLUTION_FIELD, null);

        self::assertSame($solution, (new DefaultSolutionProvider($request))->getSolution());
        self::assertFalse($post->offsetExists(SolutionProviderInterface::SOLUTION_FIELD));
        self::assertFalse($query->offsetExists(SolutionProviderInterface::SOLUTION_FIELD));
    }

    #[DataProvider('invalidSolutionProvider')]
    public function testDefaultProviderRejectsInvalidPostValues(mixed $value): void
    {
        $request = $this->createStub(Http::class);
        $request->method('isPost')->willReturn(true);
        $request->method('getPostValue')->willReturn($value);

        self::assertNull((new DefaultSolutionProvider($request))->getSolution());
    }

    public function testAjaxProviderReturnsTheExactTopLevelJsonString(): void
    {
        $solution = "  solution\nwith-whitespace  ";
        $request = $this->createMock(Http::class);
        $request->method('getContent')->willReturn(json_encode([
            SolutionProviderInterface::SOLUTION_FIELD => $solution,
        ], JSON_THROW_ON_ERROR));
        $request->expects(self::never())->method('getPostValue');
        $request->expects(self::never())->method('getParam');

        self::assertSame($solution, (new AjaxSolutionProvider($request))->getSolution());
    }

    public function testAjaxProviderAcceptsTheExactSolutionLimit(): void
    {
        $solution = str_repeat('a', SolutionProviderInterface::MAX_SOLUTION_BYTES);
        $request = $this->createStub(Http::class);
        $request->method('getContent')->willReturn(json_encode([
            SolutionProviderInterface::SOLUTION_FIELD => $solution,
        ], JSON_THROW_ON_ERROR));

        self::assertSame($solution, (new AjaxSolutionProvider($request))->getSolution());
    }

    public function testAjaxProviderRemovesTheSolutionFromTheBodyBeforeNativeDispatch(): void
    {
        $solution = 'solution';
        $request = $this->createMock(Http::class);
        $post = new Parameters([SolutionProviderInterface::SOLUTION_FIELD => 'post-solution']);
        $query = new Parameters([SolutionProviderInterface::SOLUTION_FIELD => 'query-solution']);
        $request->method('getContent')->willReturn(json_encode([
            'username' => 'customer@example.test',
            'password' => 'password',
            SolutionProviderInterface::SOLUTION_FIELD => $solution,
        ], JSON_THROW_ON_ERROR));
        $request->method('getPost')->willReturn($post);
        $request->method('getQuery')->willReturn($query);
        $request->expects(self::once())
            ->method('setParam')
            ->with(SolutionProviderInterface::SOLUTION_FIELD, null);
        $request->expects(self::once())
            ->method('setContent')
            ->with(self::callback(static function (string $content): bool {
                return json_decode($content, true, 512, JSON_THROW_ON_ERROR) === [
                    'username' => 'customer@example.test',
                    'password' => 'password',
                ];
            }));

        self::assertSame($solution, (new AjaxSolutionProvider($request))->getSolution());
        self::assertFalse($post->offsetExists(SolutionProviderInterface::SOLUTION_FIELD));
        self::assertFalse($query->offsetExists(SolutionProviderInterface::SOLUTION_FIELD));
    }

    public function testAjaxProviderClearsEverySourceWhenAValidPayloadCannotBeReencoded(): void
    {
        $post = new Parameters([SolutionProviderInterface::SOLUTION_FIELD => 'post-solution']);
        $query = new Parameters([SolutionProviderInterface::SOLUTION_FIELD => 'query-solution']);
        $request = $this->createMock(Http::class);
        $request->method('getContent')->willReturn(
            '{"private-captcha-solution":"solution","number":1e9999}'
        );
        $request->method('getPost')->willReturn($post);
        $request->method('getQuery')->willReturn($query);
        $request->expects(self::once())
            ->method('setParam')
            ->with(SolutionProviderInterface::SOLUTION_FIELD, null);
        $request->expects(self::once())->method('setContent')->with('{}');

        self::assertNull((new AjaxSolutionProvider($request))->getSolution());
        self::assertFalse($post->offsetExists(SolutionProviderInterface::SOLUTION_FIELD));
        self::assertFalse($query->offsetExists(SolutionProviderInterface::SOLUTION_FIELD));
    }

    #[DataProvider('invalidAjaxContentProvider')]
    public function testAjaxProviderRejectsInvalidJsonWithoutFallingBackToParameters(string $content): void
    {
        $request = $this->createMock(Http::class);
        $request->method('getContent')->willReturn($content);
        $request->expects(self::never())->method('getPostValue');
        $request->expects(self::never())->method('getParam');

        self::assertNull((new AjaxSolutionProvider($request))->getSolution());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidSolutionProvider(): array
    {
        return [
            'missing' => [null],
            'empty string' => [''],
            'integer' => [1],
            'boolean' => [true],
            'array' => [['solution']],
            'oversized string' => [str_repeat('a', SolutionProviderInterface::MAX_SOLUTION_BYTES + 1)],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAjaxContentProvider(): array
    {
        return [
            'malformed JSON' => ['{"private-captcha-solution":'],
            'top-level string' => ['"solution"'],
            'nested solution' => ['{"credentials":{"private-captcha-solution":"solution"}}'],
            'missing solution' => ['{"username":"customer@example.test"}'],
            'empty solution' => ['{"private-captcha-solution":""}'],
            'integer solution' => ['{"private-captcha-solution":1}'],
            'boolean solution' => ['{"private-captcha-solution":true}'],
            'array solution' => ['{"private-captcha-solution":["solution"]}'],
            'oversized solution' => [json_encode([
                SolutionProviderInterface::SOLUTION_FIELD => str_repeat('a', SolutionProviderInterface::MAX_SOLUTION_BYTES + 1),
            ], JSON_THROW_ON_ERROR)],
        ];
    }
}
