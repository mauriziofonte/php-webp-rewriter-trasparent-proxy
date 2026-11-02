<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Origin;

use Mfonte\ProxyCdn\Exception\ExceptionInterface;
use Mfonte\ProxyCdn\Exception\InvalidArgumentException;
use Mfonte\ProxyCdn\Origin\HttpRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Origin\HttpRequest
 */
#[CoversClass(HttpRequest::class)]
final class HttpRequestTest extends TestCase
{
    public function testAGetWithHeadersKeyedInLowerCase(): void
    {
        $request = new HttpRequest('https://origin.example.test/a.css?v=1', ['Referer' => 'https://cdn.example.test', 'X-Trace' => ' a b ']);

        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://origin.example.test/a.css?v=1', $request->getUrl());
        self::assertSame(['referer' => 'https://cdn.example.test', 'x-trace' => 'a b'], $request->getHeaders());
        self::assertSame('https://cdn.example.test', $request->getHeader('REFERER'));
        self::assertNull($request->getHeader('accept'));
    }

    public function testAHead(): void
    {
        self::assertSame('HEAD', (new HttpRequest('http://origin.example.test/', [], HttpRequest::METHOD_HEAD))->getMethod());
    }

    public function testAPurge(): void
    {
        self::assertSame('PURGE', (new HttpRequest('https://peer.example.test/a.css', [], HttpRequest::METHOD_PURGE))->getMethod());
    }

    public function testWithHeaderReplacesCaseInsensitivelyAndKeepsTheOriginal(): void
    {
        $request = new HttpRequest('http://origin.example.test/', ['Accept' => 'text/css']);

        $copy = $request->withHeader('ACCEPT', 'image/webp')->withHeader('X-New', 'yes');

        self::assertSame(['accept' => 'image/webp', 'x-new' => 'yes'], $copy->getHeaders());
        self::assertSame(['accept' => 'text/css'], $request->getHeaders());
        self::assertSame([], $copy->withoutHeader('Accept')->withoutHeader('x-new')->getHeaders());
    }

    public function testWithUrlKeepsTheMethodAndTheFields(): void
    {
        $request = new HttpRequest('http://origin.example.test/a.css', ['Accept' => 'text/css'], HttpRequest::METHOD_HEAD);

        $copy = $request->withUrl('https://other.example.test/b.css');

        self::assertSame('https://other.example.test/b.css', $copy->getUrl());
        self::assertSame('HEAD', $copy->getMethod());
        self::assertSame(['accept' => 'text/css'], $copy->getHeaders());
        self::assertSame('http://origin.example.test/a.css', $request->getUrl());

        $this->expectException(InvalidArgumentException::class);
        $request->withUrl('ftp://origin.example.test/a.css');
    }

    public function testWithConditionsSetsAndRemovesTheValidators(): void
    {
        $request = new HttpRequest('http://origin.example.test/a.png');

        $conditional = $request->withConditions('W/"abc"', 'Tue, 01 Sep 2026 10:00:00 GMT');

        self::assertSame('W/"abc"', $conditional->getHeader('If-None-Match'));
        self::assertSame('Tue, 01 Sep 2026 10:00:00 GMT', $conditional->getHeader('If-Modified-Since'));
        self::assertSame([], $conditional->withConditions(null, null)->getHeaders());
        self::assertSame(['if-none-match' => '"x"'], $request->withConditions('"x"', null)->getHeaders());
    }

    /**
     * @dataProvider provideInvalidRequests
     *
     * @param array<string, string> $headers
     */
    #[DataProvider('provideInvalidRequests')]
    public function testInvalidRequestsAreRefused(string $url, array $headers, string $method): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpRequest($url, $headers, $method);
    }

    /**
     * @return array<string, array{string, array<string, string>, string}>
     */
    public static function provideInvalidRequests(): array
    {
        return [
            'relative URL' => ['/a.css', [], 'GET'],
            'ftp URL' => ['ftp://origin.example.test/a.css', [], 'GET'],
            'file URL' => ['file:///etc/passwd', [], 'GET'],
            'URL without host' => ['http:///a.css', [], 'GET'],
            'URL with a line feed' => ["http://origin.example.test/a.css\nHost: evil", [], 'GET'],
            'URL with a space' => ['http://origin.example.test/a b.css', [], 'GET'],
            'URL with a tab' => ["http://origin.example.test/a\tb.css", [], 'GET'],
            'URL with a vertical tab' => ["http://origin.example.test/a\x0Bb.css", [], 'GET'],
            'URL with a form feed' => ["http://origin.example.test/a\x0Cb.css", [], 'GET'],
            'URL with an escape' => ["http://origin.example.test/a\x1Bb.css", [], 'GET'],
            'URL with DEL' => ["http://origin.example.test/a\x7Fb.css", [], 'GET'],
            'URL with a carriage return' => ["http://origin.example.test/a\rb.css", [], 'GET'],
            'URL with NUL' => ["http://origin.example.test/a\0b.css", [], 'GET'],
            'POST' => ['http://origin.example.test/', [], 'POST'],
            'header value with CRLF' => ['http://origin.example.test/', ['Referer' => "x\r\nX-Injected: 1"], 'GET'],
            'header value with NUL' => ['http://origin.example.test/', ['Referer' => "x\0"], 'GET'],
            'header name with a colon' => ['http://origin.example.test/', ['Bad:Name' => 'x'], 'GET'],
            'empty header name' => ['http://origin.example.test/', ['' => 'x'], 'GET'],
        ];
    }

    public function testWithHeaderRefusesInjection(): void
    {
        $this->expectException(ExceptionInterface::class);

        (new HttpRequest('http://origin.example.test/'))->withHeader('X-Test', "a\nb");
    }
}
