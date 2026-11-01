<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Cache;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Cache\CacheKeyResolver
 */
#[CoversClass(CacheKeyResolver::class)]
final class CacheKeyResolverTest extends TestCase
{
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
