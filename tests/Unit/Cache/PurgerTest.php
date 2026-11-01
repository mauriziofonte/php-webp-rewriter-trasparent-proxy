<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Cache;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
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
     * @var NegativeCache
     */
    private $negativeCache;

    /**
     * @var Purger
     */
    private $purger;

    /**
     * @var CacheKeyResolver
     */
    private $resolver;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $storage = new FilesystemStorage();
        $this->negativeCache = new NegativeCache($storage, $this->directory->path('state'), 300);
        $this->purger = new Purger($storage, $this->negativeCache);
        $this->resolver = new CacheKeyResolver($this->directory->path('public'));
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testPurgeRemovesTheEntryAndItsNegativeMarker(): void
    {
        $location = $this->resolver->resolve('h', '/img/a.css', '');
        $file = $this->directory->write('public/h/img/a.css', 'css');
        $this->directory->write('public/h/img/a.webp', 'an unrelated asset');
        $this->negativeCache->remember($file);

        self::assertSame([$file], $this->purger->purge($location));
        self::assertSame(['.', '..', 'a.webp'], scandir($this->directory->path('public/h/img')));
        self::assertNull($this->negativeCache->lookup($file));
    }

    public function testPurgeRemovesTheVariantsAndEncodings(): void
    {
        $location = $this->resolver->resolve('h', '/img/a.png', 'v=1');
        foreach ($location->getFiles() as $file) {
            $this->directory->write(substr($file, strlen($this->directory->path()) + 1), 'x');
        }
        $other = $this->directory->write('public/h/.q/v=1/img/a.jpg', 'another URL');

        $removed = $this->purger->purge($location);

        self::assertSame($location->getFiles(), $removed);
        foreach ($location->getFiles() as $file) {
            self::assertFileDoesNotExist($file);
        }
        self::assertFileExists($other);
    }

    public function testPurgeOfAMissingEntryRemovesNothing(): void
    {
        $location = $this->resolver->resolve('h', '/img/missing.png', '');
        $this->negativeCache->remember($location->getFile());

        self::assertSame([], $this->purger->purge($location));
        self::assertNull($this->negativeCache->lookup($location->getFile()));
        self::assertDirectoryDoesNotExist($this->directory->path('public/h'));
    }
}
