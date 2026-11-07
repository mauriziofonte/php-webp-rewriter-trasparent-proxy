<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Functional\Console;

use Mfonte\ProxyCdn\Cache\CacheUsage;
use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Config\ConfigLoader;
use Mfonte\ProxyCdn\Console\CacheEvictCommand;
use Mfonte\ProxyCdn\Console\CacheEvictor;
use Mfonte\ProxyCdn\Console\CacheTree;
use Mfonte\ProxyCdn\Console\EvictionReport;
use Mfonte\ProxyCdn\Console\Installation;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * cache:evict on a real cache tree: least recently used sets first,
 * every set whole, symbolic links never followed.
 *
 * @covers \Mfonte\ProxyCdn\Console\CacheEvictCommand
 * @covers \Mfonte\ProxyCdn\Console\CacheEvictor
 * @covers \Mfonte\ProxyCdn\Console\CacheTree
 * @covers \Mfonte\ProxyCdn\Console\EvictionReport
 * @covers \Mfonte\ProxyCdn\Console\Installation
 */
#[CoversClass(CacheEvictCommand::class)]
#[CoversClass(CacheEvictor::class)]
#[CoversClass(CacheTree::class)]
#[CoversClass(EvictionReport::class)]
#[CoversClass(Installation::class)]
final class CacheEvictCommandTest extends TestCase
{
    private const ENV = "SOURCE_ORIGIN=\"https://origin.example.test\"\nPROXY_HOSTS=\"cdn.example.test\"\nCACHEABLE_EXTENSIONS=\"css,js,jpg\"\nUPSTREAM_VERIFY_TLS=true\nLOG_FILE=stderr\nLOG_LEVEL=emergency\n";

    private const HOST = 'public/_cache/cdn.example.test';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->directory->write('.env', self::ENV);
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    /**
     * The oldest sets go first, each with its variants, encodings and
     * metadata; the newest stays whole; the size left is recorded for
     * /metrics.
     */
    public function testTheLeastRecentlyUsedSetsAreEvictedWhole(): void
    {
        $old = $this->set('', 'images/old.jpg', 1000000, ['.webp/images/old.jpg.webp', '.avif/images/old.jpg.avif']);
        $middle = $this->set('/.m', 'css/middle.css', 2000000, ['.br/css/middle.css', '.gz/css/middle.css', '.meta/css/middle.css.json']);
        $new = $this->set('/.q/v=1', 'js/new.js', 3000000, ['.gz/js/new.js']);

        list($exitCode, $display) = $this->evict(['--max-size' => '115']);

        self::assertSame(0, $exitCode, $display);
        self::assertStringContainsString('Cache: 3 entries, ', $display);
        self::assertStringContainsString('Evicted 2 entries', $display);
        foreach (array_merge($old, $middle) as $file) {
            self::assertFileDoesNotExist($file);
        }
        foreach ($new as $file) {
            self::assertFileExists($file);
        }
        $usage = CacheUsage::read(new FilesystemStorage(), $this->directory->path('var/' . CacheUsage::FILE_NAME));
        self::assertNotNull($usage);
        self::assertSame(1, $usage->getEntries());
        self::assertSame(self::bytes($new), $usage->getBytes());
    }

    /**
     * With --by=mtime the last write orders the sets, not the last read.
     */
    public function testTheOrderCanBeTheModificationTime(): void
    {
        $readLately = $this->set('', 'a.css', 3000000, [], 1000000);
        $writtenLately = $this->set('', 'b.css', 1500000, [], 2000000);

        $this->evict(['--max-size' => '120', '--by' => 'mtime']);

        self::assertFileDoesNotExist($readLately[0]);
        self::assertFileExists($writtenLately[0]);
    }

    /**
     * A sibling whose entry is gone is a set of its own, evicted whole.
     */
    public function testAnOrphanSiblingIsASet(): void
    {
        $orphan = $this->directory->write(self::HOST . '/.webp/gone.jpg.webp', str_repeat('w', 100));
        touch($orphan, 1000000, 1000000);
        $kept = $this->set('', 'kept.css', 2000000);

        $this->evict(['--max-size' => '100']);

        self::assertFileDoesNotExist($orphan);
        self::assertFileExists($kept[0]);
    }

    /**
     * An image waiting for its variants (the pending tier of PRX-8) is a
     * set with the variants staged for it: measured, and evicted whole.
     */
    public function testAPendingImageIsASetWithItsStagedVariants(): void
    {
        $pending = $this->set('/.pending', 'images/new.jpg', 1000000, ['.webp/images/new.jpg.webp', '.avif/images/new.jpg.avif']);
        $kept = $this->set('', 'kept.css', 2000000);

        list($exitCode, $display) = $this->evict(['--max-size' => '100']);

        self::assertSame(0, $exitCode, $display);
        self::assertStringContainsString('Cache: 2 entries, 220 bytes', $display);
        foreach ($pending as $file) {
            self::assertFileDoesNotExist($file);
        }
        self::assertFileExists($kept[0]);
        $usage = CacheUsage::read(new FilesystemStorage(), $this->directory->path('var/' . CacheUsage::FILE_NAME));
        self::assertNotNull($usage);
        self::assertSame(0, $usage->getPendingEntries(), 'the pending image was evicted');

        $this->set('/.pending', 'images/other.jpg', 3000000);
        $this->evict([]);
        $usage = CacheUsage::read(new FilesystemStorage(), $this->directory->path('var/' . CacheUsage::FILE_NAME));
        self::assertNotNull($usage);
        self::assertSame([2, 1], [$usage->getEntries(), $usage->getPendingEntries()], 'for /metrics');
    }

    /**
     * A link in the cache, even one that stands for a sibling directory,
     * is never followed: what it points to is neither measured nor
     * removed.
     */
    public function testSymbolicLinksAreNeverFollowed(): void
    {
        $outside = $this->directory->write('outside/old.jpg', str_repeat('o', 5000));
        $this->directory->write('outside/deep/x.css', 'x');
        touch($outside, 1000, 1000);
        $entry = $this->set('', 'old.jpg', 2000);
        mkdir($this->directory->path(self::HOST . '/linked'));
        symlink($this->directory->path('outside'), $this->directory->path(self::HOST . '/linked/dir'));
        symlink($this->directory->path('outside'), $this->directory->path(self::HOST . '/.br'));
        symlink($outside, $this->directory->path(self::HOST . '/file-link.jpg'));
        symlink($this->directory->path('outside'), $this->directory->path('public/_cache/link.example.test'));

        list($exitCode, $display) = $this->evict(['--max-size' => '1']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Cache: 1 entries, 100 bytes.', $display);
        self::assertStringContainsString('symbolic links in the cache were left alone.', $display);
        self::assertFileDoesNotExist($entry[0]);
        self::assertFileExists($outside);
        self::assertFileExists($this->directory->path('outside/deep/x.css'));
        self::assertTrue(is_link($this->directory->path(self::HOST . '/file-link.jpg')));
    }

    public function testADryRunRemovesNothing(): void
    {
        $entry = $this->set('', 'a.css', 1000000);
        $this->set('', 'b.css', 2000000);

        list($exitCode, $display) = $this->evict(['--max-size' => '100', '--dry-run' => true]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Would evict 1 entries, 100 bytes, down to 100 bytes:', $display);
        self::assertStringContainsString('a.css', $display);
        self::assertFileExists($entry[0]);
        self::assertFileDoesNotExist($this->directory->path('var/' . CacheUsage::FILE_NAME));
    }

    /**
     * CACHE_MAX_SIZE is the default cap; without one the cache is only
     * measured.
     */
    public function testCacheMaxSizeIsTheDefaultCap(): void
    {
        $old = $this->set('', 'a.css', 1000000);
        $this->set('', 'b.css', 2000000);

        list(, $display) = $this->evict([]);
        self::assertStringContainsString('No size cap', $display);
        self::assertFileExists($old[0]);
        self::assertNotNull(CacheUsage::read(new FilesystemStorage(), $this->directory->path('var/' . CacheUsage::FILE_NAME)));

        $this->directory->write('.env', self::ENV . "CACHE_MAX_SIZE=150\n");
        $this->evict([]);
        self::assertFileDoesNotExist($old[0]);
    }

    public function testInvalidOptionsAreRefused(): void
    {
        self::assertSame(1, $this->evict(['--max-size' => 'lots'])[0]);
        self::assertSame(1, $this->evict(['--by' => 'ctime'])[0]);
        $this->directory->write('.env', "SOURCE_ORIGIN=\"origin\"\n");
        self::assertSame(1, $this->evict([])[0]);
    }

    /**
     * An entry of $bytes 100 bytes and its siblings, last read at $atime
     * and written at $mtime (default: $atime).
     *
     * @param list<string> $siblings paths relative to the bucket directory
     *
     * @return list<string> the files of the set
     */
    private function set(string $bucket, string $path, int $atime, array $siblings = [], ?int $mtime = null): array
    {
        $directory = self::HOST . $bucket;
        $files = [$this->directory->write($directory . '/' . $path, str_repeat('e', 100))];
        foreach ($siblings as $sibling) {
            $files[] = $this->directory->write($directory . '/' . $sibling, str_repeat('s', 10));
        }
        foreach ($files as $file) {
            touch($file, $mtime ?? $atime, $atime);
        }

        return $files;
    }

    /**
     * @param list<string> $files
     */
    private static function bytes(array $files): int
    {
        $bytes = 0;
        foreach ($files as $file) {
            $bytes += (int) filesize($file);
        }

        return $bytes;
    }

    /**
     * @param array<string, string|bool> $input
     *
     * @return array{int, string}
     */
    private function evict(array $input): array
    {
        $installation = new Installation(new ConfigLoader($this->directory->path('.env'), null, static function (string $name): ?string {
            return null;
        }), $this->directory->path());
        $tester = new CommandTester(new CacheEvictCommand($installation));
        $exitCode = $tester->execute($input);

        return [$exitCode, $tester->getDisplay()];
    }
}
