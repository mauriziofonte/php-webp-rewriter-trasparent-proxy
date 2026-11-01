<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Cache;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Cache\CacheKeyResolver
 */
#[CoversClass(CacheKeyResolver::class)]
final class CacheKeyResolverTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideQueryStrings(): array
    {
        return [
            'empty' => ['', ''],
            'one parameter' => ['v=1', 'v=1'],
            'sorted by name' => ['b=2&a=1', 'a=1&b=2'],
            'purge only' => ['purge=1', ''],
            'purge and purge_all only' => ['purge_all=true&purge=1', ''],
            'purge removed' => ['v=1&purge=1', 'v=1'],
            'upper-case purge is a parameter' => ['PURGE=1', 'PURGE=1'],
            'dots become underscores (P-9)' => ['a.b=1', 'a_b=1'],
            'bare key gains "=" (P-9)' => ['v', 'v='],
            'encoding is normalised' => ['q=a%20b', 'q=a+b'],
            'arrays' => ['a[]=1&a[]=2', 'a%5B0%5D=1&a%5B1%5D=2'],
        ];
    }

    /**
     * @dataProvider provideQueryStrings
     */
    #[DataProvider('provideQueryStrings')]
    public function testCanonicalQueryString(string $raw, string $canonical): void
    {
        self::assertSame($canonical, (new CacheKeyResolver('/srv/public'))->canonicalQueryString($raw));
    }

    public function testPathWithoutQueryStringIsKeyedOnThePath(): void
    {
        $location = (new CacheKeyResolver('/srv/public/'))->resolve('/images/a.png', '');

        self::assertSame('/srv/public/images/a.png', $location->getFile());
        self::assertSame('/srv/public/images/', $location->getDirectory());
        self::assertSame('/srv/public/images/a.webp', $location->getWebpFile());
        self::assertSame('', $location->getCanonicalQuery());
        self::assertFalse($location->isQueryStringBucket());
    }

    public function testQueryStringSelectsABucket(): void
    {
        $location = (new CacheKeyResolver('/srv/public'))->resolve('/images/a.png', 'v=1');
        $bucket = '/srv/public/_qs/' . substr(hash('sha256', 'v=1'), 0, 16) . '/';

        self::assertSame('a798de8ee75aeb55', substr(hash('sha256', 'v=1'), 0, 16));
        self::assertSame($bucket . 'images/a.png', $location->getFile());
        self::assertSame($bucket . 'images/', $location->getDirectory());
        self::assertSame($bucket . 'images/a.webp', $location->getWebpFile());
        self::assertSame('v=1', $location->getCanonicalQuery());
        self::assertTrue($location->isQueryStringBucket());
    }

    /**
     * The file has the name of the decoded path, the one the web server
     * looks for (finding P-8).
     */
    public function testTheDecodedPathNamesTheFileP8(): void
    {
        $location = (new CacheKeyResolver('/srv/public'))->resolve('/images/my file.png', '');

        self::assertSame('/srv/public/images/my file.png', $location->getFile());
    }

    public function testFileInTheRootAndFileWithoutExtension(): void
    {
        $resolver = new CacheKeyResolver('/srv/public');

        self::assertSame('/srv/public/', $resolver->resolve('/favicon.ico', '')->getDirectory());
        self::assertSame('/srv/public/LICENSE.webp', $resolver->resolve('/LICENSE', '')->getWebpFile());
        self::assertSame('/srv/public/a.b.webp', $resolver->resolve('/a.b.png', '')->getWebpFile());
    }

    public function testSiblingOf(): void
    {
        self::assertSame('/srv/a/b.jpg', CacheKeyResolver::siblingOf('/srv/a/b.png', 'jpg'));
        self::assertSame('/srv/a/b.webp', CacheKeyResolver::siblingOf('/srv/a/b', 'webp'));
    }
}
