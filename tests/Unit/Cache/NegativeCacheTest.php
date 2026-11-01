<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Cache;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Cache\NegativeCache;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
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
        $this->cache = new NegativeCache(new FilesystemStorage(), $this->directory->path('state'), 300);
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testNothingIsRememberedAtFirst(): void
    {
        self::assertNull($this->cache->lookup($this->directory->path('public/a.png')));
        self::assertFalse($this->cache->isFresh($this->directory->path('public/a.png')));
    }

    /**
     * Remembering writes one slot file in the state directory and nothing
     * next to the entry (finding P-5: 2.x created the directories of every
     * path that does not exist, and a marker in them).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testRememberCreatesNoDirectoryNextToTheEntryP5(): void
    {
        $file = $this->directory->path('public/x/y/a.png');

        $this->cache->remember($file);

        self::assertTrue($this->cache->isFresh($file));
        self::assertSame(404, $this->cache->lookup($file));
        self::assertDirectoryDoesNotExist($this->directory->path('public'));
        self::assertSame([self::slotOf($file)], self::filesIn($this->directory->path('state')));
    }

    /**
     * However many paths are asked for, at most 4096 slot files exist.
     *
     * @group regression
     */
    #[Group('regression')]
    public function testTheNumberOfMarkersIsBoundedP5(): void
    {
        for ($index = 0; $index < 5000; ++$index) {
            $this->cache->remember($this->directory->path('public/missing-' . $index . '.png'));
        }

        $files = self::filesIn($this->directory->path('state'));
        self::assertLessThanOrEqual(4096, count($files));
        self::assertGreaterThan(2500, count($files));
    }

    public function testA410IsRemembered(): void
    {
        $file = $this->directory->path('public/a.png');

        $this->cache->remember($file, 410);

        self::assertSame(410, $this->cache->lookup($file));
    }

    public function testOtherStatusesAreNotRemembered(): void
    {
        $file = $this->directory->path('public/a.png');

        $this->cache->remember($file, 500);

        self::assertNull($this->cache->lookup($file));
        self::assertDirectoryDoesNotExist($this->directory->path('state'));
    }

    public function testMarkerExpiresAfterTheTtl(): void
    {
        $file = $this->directory->path('public/a.png');
        $this->cache->remember($file);
        $slot = $this->directory->path('state/' . self::slotOf($file));

        touch($slot, time() - 299);
        clearstatcache();
        self::assertTrue($this->cache->isFresh($file));

        touch($slot, time() - 300);
        clearstatcache();
        self::assertFalse($this->cache->isFresh($file));
    }

    public function testForget(): void
    {
        $file = $this->directory->path('public/a.png');
        $this->cache->remember($file);

        $this->cache->forget($file);
        $this->cache->forget($file);

        self::assertFalse($this->cache->isFresh($file));
        self::assertSame([], self::filesIn($this->directory->path('state')));
    }

    public function testAnotherEntryInTheSlotIsNeitherAnsweredNorForgotten(): void
    {
        $file = $this->directory->path('public/a.png');
        $other = $this->directory->path('public/b.png');
        $this->cache->remember($file);
        $slot = $this->directory->path('state/' . self::slotOf($file));
        // The slot of $file holding the marker of $other.
        file_put_contents($slot, '404 ' . hash('sha256', $other) . "\n");

        self::assertNull($this->cache->lookup($file));
        $this->cache->forget($file);
        self::assertFileExists($slot);
    }

    public function testACorruptSlotIsNotAMarker(): void
    {
        $file = $this->directory->path('public/a.png');
        $this->cache->remember($file);
        file_put_contents($this->directory->path('state/' . self::slotOf($file)), 'garbage');

        self::assertNull($this->cache->lookup($file));
    }

    public function testZeroTtlNeverAnswersFromTheMarker(): void
    {
        $cache = new NegativeCache(new FilesystemStorage(), $this->directory->path('state'), 0);
        $file = $this->directory->path('public/a.png');
        $cache->remember($file);

        self::assertFalse($cache->isFresh($file));
    }

    private static function slotOf(string $file): string
    {
        return substr(hash('sha256', $file), 0, 3) . '.neg';
    }

    /**
     * @return list<string>
     */
    private static function filesIn(string $directory): array
    {
        $entries = scandir($directory);
        self::assertIsArray($entries);

        return array_values(array_diff($entries, ['.', '..']));
    }
}
