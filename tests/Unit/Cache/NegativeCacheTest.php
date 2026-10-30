<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Cache;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Cache\NegativeCache;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Cache\NegativeCache
 */
#[CoversClass(NegativeCache::class)]
final class NegativeCacheTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var NegativeCache
     */
    private $cache;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->cache = new NegativeCache(new FilesystemStorage(), 300);
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testNothingIsRememberedAtFirst(): void
    {
        self::assertFalse($this->cache->isFresh($this->directory->path('a.png')));
    }

    /**
     * Remembering creates the missing directories (finding P-5, resolved by
     * PRX-5).
     */
    public function testRememberCreatesTheMarkerAndItsDirectoriesP5(): void
    {
        $file = $this->directory->path('x/y/a.png');

        $this->cache->remember($file);

        self::assertFileExists($file . '.404');
        self::assertTrue($this->cache->isFresh($file));
    }

    public function testMarkerExpiresAfterTheTtl(): void
    {
        $file = $this->directory->path('a.png');
        $this->cache->remember($file);

        touch($file . '.404', time() - 299);
        clearstatcache();
        self::assertTrue($this->cache->isFresh($file));

        touch($file . '.404', time() - 300);
        clearstatcache();
        self::assertFalse($this->cache->isFresh($file));
    }

    public function testForget(): void
    {
        $file = $this->directory->path('a.png');
        $this->cache->remember($file);

        $this->cache->forget($file);
        $this->cache->forget($file);

        self::assertFileDoesNotExist($file . '.404');
        self::assertFalse($this->cache->isFresh($file));
    }

    public function testDirectoryNamedLikeAMarkerIsNotAMarker(): void
    {
        mkdir($this->directory->path('a.png.404'));

        self::assertFalse($this->cache->isFresh($this->directory->path('a.png')));
    }

    public function testZeroTtlNeverAnswersFromTheMarker(): void
    {
        $cache = new NegativeCache(new FilesystemStorage(), 0);
        $file = $this->directory->path('a.png');
        $cache->remember($file);

        self::assertFalse($cache->isFresh($file));
    }
}
