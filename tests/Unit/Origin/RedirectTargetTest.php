<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Origin;

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
            'no host' => ['http:///c.png', null],
        ];
    }
}
