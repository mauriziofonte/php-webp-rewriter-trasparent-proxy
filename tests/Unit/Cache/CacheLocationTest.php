<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Cache;

use Mfonte\ProxyCdn\Cache\CacheLocation;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Cache\CacheLocation
 */
#[CoversClass(CacheLocation::class)]
final class CacheLocationTest extends TestCase
{
    public function testEntryKeyedOnThePath(): void
    {
        $location = new CacheLocation('/srv/public/a/b.png', '/srv/public/a/', '/srv/public/a/b.webp', '');

        self::assertSame('/srv/public/a/b.png', $location->getFile());
        self::assertSame('/srv/public/a/', $location->getDirectory());
        self::assertSame('/srv/public/a/b.webp', $location->getWebpFile());
        self::assertSame('', $location->getCanonicalQuery());
        self::assertFalse($location->isQueryStringBucket());
    }

    public function testEntryKeyedOnAQueryString(): void
    {
        $location = new CacheLocation('/srv/public/_qs/x/b.png', '/srv/public/_qs/x/', '/srv/public/_qs/x/b.webp', 'v=1');

        self::assertSame('v=1', $location->getCanonicalQuery());
        self::assertTrue($location->isQueryStringBucket());
    }
}
