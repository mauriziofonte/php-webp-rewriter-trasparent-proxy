<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Cache;

use Mfonte\ProxyCdn\Cache\CacheUsage;
use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Cache\CacheUsage
 */
#[CoversClass(CacheUsage::class)]
final class CacheUsageTest extends TestCase
{
    public function testItRoundTrips(): void
    {
        $directory = new TemporaryDirectory();
        try {
            $storage = new FilesystemStorage();
            $file = $directory->path('var/' . CacheUsage::FILE_NAME);

            self::assertNull(CacheUsage::read($storage, $file));
            self::assertTrue((new CacheUsage(10, 2, 1700000000))->write($storage, $file));
            $usage = CacheUsage::read($storage, $file);

            self::assertNotNull($usage);
            self::assertSame([10, 2, 1700000000], [$usage->getBytes(), $usage->getEntries(), $usage->getScannedAt()]);
            file_put_contents($file, '{"bytes":"10"}');
            self::assertNull(CacheUsage::read($storage, $file));
        } finally {
            $directory->remove();
        }
    }

    public function testNegativeValuesAreClamped(): void
    {
        $usage = new CacheUsage(-1, -2, -3);

        self::assertSame([0, 0, 0], [$usage->getBytes(), $usage->getEntries(), $usage->getScannedAt()]);
    }
}
