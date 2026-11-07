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
    public function testPathWithoutQueryStringIsKeyedOnHostAndPath(): void
    {
        $location = (new CacheKeyResolver('/srv/public/_cache/'))->resolve('cdn.example.test', '/images/a.png', '');

        self::assertSame('/srv/public/_cache/cdn.example.test/images/a.png', $location->getFile());
        self::assertSame('/srv/public/_cache/cdn.example.test/images/', $location->getDirectory());
        self::assertSame('/srv/public/_cache/cdn.example.test', $location->getHostDirectory());
        self::assertSame('', $location->getCanonicalQuery());
        self::assertFalse($location->isQueryStringBucket());
    }

    /**
     * A canonical query string of plain characters names its bucket
     * itself, so that Apache and nginx find the entry (finding S-4).
     */
    public function testAPlainQueryStringNamesItsBucket(): void
    {
        $location = (new CacheKeyResolver('/srv/c'))->resolve('cdn.example.test', '/css/a.css', 'v=1.2&ver=abc_9');

        self::assertSame('/srv/c/cdn.example.test/.q/v=1.2&ver=abc_9/css/a.css', $location->getFile());
        self::assertSame('.q/v=1.2&ver=abc_9', $location->getBucket());
        self::assertSame('v=1.2&ver=abc_9', $location->getCanonicalQuery());
        self::assertTrue($location->isQueryStringBucket());
    }

    /**
     * @dataProvider provideHashedQueries
     */
    #[DataProvider('provideHashedQueries')]
    public function testOtherQueryStringsAreHashed(string $query): void
    {
        $location = (new CacheKeyResolver('/srv/c'))->resolve('h', '/a.css', $query);

        self::assertSame('/srv/c/h/.qh/' . substr(hash('sha256', $query), 0, 16) . '/a.css', $location->getFile());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideHashedQueries(): array
    {
        return [
            'escape' => ['v=a%2Fb'],
            'slash' => ['v=a/b'],
            'plus' => ['v=a+b'],
            'leading dot' => ['..'],
            'too long' => ['v=' . str_repeat('a', 199)],
        ];
    }

    public function testTheLongestPlainQueryStringIsKept(): void
    {
        $query = 'v=' . str_repeat('a', 198);

        self::assertSame('.q/' . $query, CacheKeyResolver::bucketOf($query));
    }

    /**
     * The file has the name of the decoded path, the one the web server
     * looks for (finding P-8).
     */
    public function testTheDecodedPathNamesTheFileP8(): void
    {
        $location = (new CacheKeyResolver('/srv/c'))->resolve('h', '/images/my file.png', '');

        self::assertSame('/srv/c/h/images/my file.png', $location->getFile());
    }

    public function testSiblingsLiveInDotDirectories(): void
    {
        $location = (new CacheKeyResolver('/srv/c'))->resolve('h', '/img/a.png', 'v=1');

        self::assertSame('/srv/c/h/.q/v=1/.webp/img/a.png.webp', $location->getVariantFile('webp'));
        self::assertSame('/srv/c/h/.q/v=1/.avif/img/a.png.avif', $location->getVariantFile('avif'));
        self::assertSame('/srv/c/h/.q/v=1/.br/img/a.png', $location->getEncodedFile('br'));
        self::assertSame('/srv/c/h/.q/v=1/.gz/img/a.png', $location->getEncodedFile('gz'));
        self::assertSame('/srv/c/h/.q/v=1/.pending/img/a.png', $location->getPendingFile());
        self::assertSame('/srv/c/h/.q/v=1/.pending/.webp/img/a.png.webp', $location->getPendingVariantFile('webp'));
        self::assertSame([
            '/srv/c/h/.q/v=1/img/a.png',
            '/srv/c/h/.q/v=1/.meta/img/a.png.json',
            '/srv/c/h/.q/v=1/.pending/img/a.png',
            '/srv/c/h/.q/v=1/.avif/img/a.png.avif',
            '/srv/c/h/.q/v=1/.pending/.avif/img/a.png.avif',
            '/srv/c/h/.q/v=1/.webp/img/a.png.webp',
            '/srv/c/h/.q/v=1/.pending/.webp/img/a.png.webp',
            '/srv/c/h/.q/v=1/.br/img/a.png',
            '/srv/c/h/.q/v=1/.gz/img/a.png',
        ], $location->getFiles());
        self::assertSame($location->getFile(), $location->getKey());
        self::assertSame('img/a.png', $location->getRelativePath());
    }

    /**
     * The entries PHP revalidates live below "<host>/.m/", where the web
     * server never looks, under the same lock key (finding P-15).
     */
    public function testTheManagedTierMirrorsTheLayoutBelowItsDirectory(): void
    {
        $location = (new CacheKeyResolver('/srv/c'))->resolve('h', '/img/a.png', 'v=1');
        $managed = $location->managed();

        self::assertTrue($managed->isManaged());
        self::assertFalse($location->isManaged());
        self::assertSame('/srv/c/h/.m/.q/v=1/img/a.png', $managed->getFile());
        self::assertSame('/srv/c/h/.m/.q/v=1/.meta/img/a.png.json', $managed->getMetadataFile());
        self::assertSame('/srv/c/h/.m/.q/v=1/.webp/img/a.png.webp', $managed->getVariantFile('webp'));
        self::assertSame($location->getKey(), $managed->getKey());
        self::assertSame($location->getFile(), $managed->unmanaged()->getFile());
        self::assertSame('/srv/c/h/.qh/abc/img/a.png', $location->withBucket('.qh/abc', '')->getFile());
    }

    public function testTheCacheDirectory(): void
    {
        $resolver = new CacheKeyResolver('/srv/c/');

        self::assertSame('/srv/c', $resolver->getCacheDirectory());
        self::assertSame('/srv/c/h', $resolver->hostDirectory('h'));
    }
}
