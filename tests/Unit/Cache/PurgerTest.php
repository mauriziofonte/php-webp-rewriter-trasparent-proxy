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
        $location = $this->resolver->resolve('/img/a.css', '');
        $file = $this->directory->write('public/img/a.css', 'css');
        $this->directory->write('public/img/a.webp', 'an unrelated asset');
        $this->negativeCache->remember($file);

        self::assertSame([$file], $this->purger->purge($location, false));
        self::assertSame(['.', '..', 'a.webp'], scandir($this->directory->path('public/img')));
        self::assertNull($this->negativeCache->lookup($file));
    }

    public function testPurgeOfARasterRemovesItsWebpVariant(): void
    {
        $location = $this->resolver->resolve('/img/a.png', 'v=1');
        $this->directory->write(substr($location->getFile(), strlen($this->directory->path()) + 1), 'png');
        $this->directory->write(substr($location->getWebpFile(), strlen($this->directory->path()) + 1), 'webp');
        $other = $this->directory->write('public/img/a.jpg', 'another URL');

        $removed = $this->purger->purge($location, true);

        self::assertSame([$location->getFile(), $location->getWebpFile()], $removed);
        self::assertFileDoesNotExist($location->getWebpFile());
        self::assertFileExists($other);
    }

    public function testPurgeOfAMissingEntryRemovesNothing(): void
    {
        $location = $this->resolver->resolve('/img/missing.png', '');
        $this->negativeCache->remember($location->getFile());

        self::assertSame([], $this->purger->purge($location, true));
        self::assertNull($this->negativeCache->lookup($location->getFile()));
        self::assertDirectoryDoesNotExist($this->directory->path('public/img'));
    }
}
