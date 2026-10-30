<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Cache;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Cache\NegativeCache;
use Mfonte\ProxyCdn\Cache\Purger;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Cache\Purger
 */
#[CoversClass(Purger::class)]
final class PurgerTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var Purger
     */
    private $purger;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $storage = new FilesystemStorage();
        $this->purger = new Purger($storage, new NegativeCache($storage, 300));
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testPurgeRemovesTheEntryAndItsNotFoundMarker(): void
    {
        $file = $this->directory->write('img/a.png', 'png');
        $this->directory->write('img/a.png.404', '');
        $this->directory->write('img/a.png.netfail', '');
        $this->directory->write('img/a.webp', 'webp');

        $purged = $this->purger->purge($file, false);

        self::assertSame([$file], $purged);
        self::assertSame(['.', '..', 'a.png.netfail', 'a.webp'], scandir($this->directory->path('img')));
    }

    public function testAggressivePurgeRemovesTheSiblingsToo(): void
    {
        $file = $this->directory->write('img/a.png', 'png');
        foreach (['webp', 'jpg', 'jpeg', 'gif', 'bmp'] as $extension) {
            $this->directory->write('img/a.' . $extension, $extension);
            $this->directory->write('img/a.' . $extension . '.404', '');
        }
        $this->directory->write('img/a.css', 'kept');
        $this->directory->write('img/b.webp', 'kept');

        $purged = $this->purger->purge($file, true);

        $siblings = [];
        foreach (['webp', 'jpg', 'jpeg', 'png', 'gif', 'bmp'] as $extension) {
            $siblings[] = $this->directory->path('img/a.' . $extension);
        }
        self::assertSame(array_merge([$file], array_values(array_diff($siblings, [$file]))), $purged);
        self::assertSame(['.', '..', 'a.css', 'b.webp'], scandir($this->directory->path('img')));
    }

    public function testAggressivePurgeOfANameWithoutBaseHasNoSiblings(): void
    {
        $file = $this->directory->write('img/.png', 'png');
        $this->directory->write('img/.webp', 'kept');

        self::assertSame([$file], $this->purger->purge($file, true));
        self::assertFileExists($this->directory->path('img/.webp'));
    }

    public function testPurgeOfAMissingEntryDoesNothing(): void
    {
        $file = $this->directory->path('img/missing.png');

        self::assertSame([$file], $this->purger->purge($file, false));
        self::assertDirectoryDoesNotExist($this->directory->path('img'));
    }
}
