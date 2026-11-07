<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Origin;

use Mfonte\ProxyCdn\Origin\HttpRequest;
use Mfonte\ProxyCdn\Origin\RedirectTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Origin\RedirectTarget
 */
#[CoversClass(RedirectTarget::class)]
final class RedirectTargetTest extends TestCase
{
    /**
     * @dataProvider provideLocations
     */
    #[DataProvider('provideLocations')]
    public function testResolve(string $location, ?string $expected): void
    {
        self::assertSame($expected, RedirectTarget::resolve('https://origin.test:8443/img/a/b.png?x=1', $location));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function provideLocations(): array
    {
        return [
            'absolute https' => ['https://other.test/c.png', 'https://other.test/c.png'],
            'absolute http, any case' => ['HTTP://other.test/c.png', 'HTTP://other.test/c.png'],
            'scheme-relative' => ['//cdn.test/c.png', 'https://cdn.test/c.png'],
            'absolute path' => ['/c.png?v=2', 'https://origin.test:8443/c.png?v=2'],
            'absolute path with dot segments' => ['/img/../c/./d.png', 'https://origin.test:8443/c/d.png'],
            'relative path' => ['c.png', 'https://origin.test:8443/img/a/c.png'],
            'relative path upwards' => ['../../c.png', 'https://origin.test:8443/c.png'],
            'above the root' => ['../../../../c.png', 'https://origin.test:8443/c.png'],
            'query only' => ['?v=3', 'https://origin.test:8443/img/a/b.png?v=3'],
            'fragment dropped' => ['/c.png#top', 'https://origin.test:8443/c.png'],
            'file scheme' => ['file:///etc/passwd', null],
            'gopher scheme' => ['gopher://origin.test/', null],
            'javascript scheme' => ['javascript:alert(1)', null],
            'empty' => ['', null],
            'line feed' => ["/c.png\nX: y", null],
            'tab' => ["/c\t.png", null],
            'DEL' => ["/c\x7F.png", null],
            'no host' => ['http:///c.png', null],
        ];
    }

    /**
     * @dataProvider provideFollowedLocations
     */
    #[DataProvider('provideFollowedLocations')]
    public function testFollowKeepsTheOriginBoundFieldsOnTheSameOriginOnly(string $location, bool $kept): void
    {
        $request = new HttpRequest('https://origin.test/a.png', [
            'Authorization' => 'Bearer s3cr3t',
            'Cookie' => 'session=abc',
            'Proxy-Authorization' => 'Basic cHJveHk=',
            'Host' => 'origin.test',
            'Referer' => 'https://cdn.test/',
        ], HttpRequest::METHOD_HEAD);

        $next = RedirectTarget::follow($request, $location);

        self::assertNotNull($next);
        self::assertSame(HttpRequest::METHOD_HEAD, $next->getMethod());
        self::assertSame('https://cdn.test/', $next->getHeader('referer'));
        $bound = array_intersect_key($next->getHeaders(), array_flip(RedirectTarget::ORIGIN_BOUND_HEADERS));
        self::assertCount($kept ? 4 : 0, $bound);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function provideFollowedLocations(): array
    {
        return [
            'relative' => ['b.png', true],
            'same origin with the default port' => ['https://ORIGIN.test:443/b.png', true],
            'another host' => ['https://cdn.origin.test/b.png', false],
            'another port' => ['https://origin.test:8443/b.png', false],
            'another scheme' => ['http://origin.test/b.png', false],
            'another scheme on the same port' => ['http://origin.test:443/b.png', false],
        ];
    }

    public function testFollowRefusesWhatIsNotAnHttpUrl(): void
    {
        $request = new HttpRequest('https://origin.test/a.png');

        self::assertNull(RedirectTarget::follow($request, 'file:///etc/passwd'));
        self::assertNull(RedirectTarget::follow($request, ''));
        $next = RedirectTarget::follow($request, '/b.png');
        self::assertNotNull($next);
        self::assertSame('https://origin.test/b.png', $next->getUrl());
    }
}
