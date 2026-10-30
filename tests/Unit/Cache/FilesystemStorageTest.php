<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Cache;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Cache\FilesystemStorage
 */
#[CoversClass(FilesystemStorage::class)]
final class FilesystemStorageTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var FilesystemStorage
     */
    private $storage;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->storage = new FilesystemStorage();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testWriteCreatesTheDirectoriesAndTheFile(): void
    {
        $file = $this->directory->path('a/b/c.css');

        self::assertTrue($this->storage->write($file, 'body {}'));

        self::assertStringEqualsFile($file, 'body {}');
        self::assertSame(0644, fileperms($file) & 0777);
        self::assertSame(0755, fileperms($this->directory->path('a/b')) & 0777);
    }

    public function testWriteReplacesTheFileAndLeavesNoTemporaryFile(): void
    {
        $file = $this->directory->write('a.css', 'old');

        self::assertTrue($this->storage->write($file, 'new'));

        self::assertStringEqualsFile($file, 'new');
        self::assertSame(['.', '..', 'a.css'], scandir($this->directory->path()));
    }

    public function testWriteFailsWhenTheTargetIsADirectoryAndCleansUp(): void
    {
        mkdir($this->directory->path('a.css'));

        self::assertFalse($this->storage->write($this->directory->path('a.css'), 'body {}'));

        self::assertSame(['.', '..', 'a.css'], scandir($this->directory->path()));
    }

    public function testWriteFailsWhenAParentIsAFile(): void
    {
        $this->directory->write('blocker', 'file');

        self::assertFalse($this->storage->write($this->directory->path('blocker/a.css'), 'body {}'));
    }

    public function testWriteOfAnEmptyString(): void
    {
        $file = $this->directory->path('empty.css');

        self::assertTrue($this->storage->write($file, ''));
        self::assertSame('', $this->storage->read($file));
    }

    public function testReadSizeAndModificationTime(): void
    {
        $file = $this->directory->write('a.css', 'abc');
        touch($file, 1000000000);

        self::assertTrue($this->storage->isFile($file));
        self::assertSame('abc', $this->storage->read($file));
        self::assertSame(3, $this->storage->size($file));
        self::assertSame(1000000000, $this->storage->modifiedAt($file));
    }

    public function testMissingFile(): void
    {
        $file = $this->directory->path('missing.css');

        self::assertFalse($this->storage->isFile($file));
        self::assertNull($this->storage->read($file));
        self::assertNull($this->storage->size($file));
        self::assertNull($this->storage->modifiedAt($file));
    }

    public function testDirectoryIsNotAFile(): void
    {
        self::assertFalse($this->storage->isFile($this->directory->path()));
        self::assertNull($this->storage->read($this->directory->path()));
    }

    public function testEnsureDirectory(): void
    {
        self::assertTrue($this->storage->ensureDirectory($this->directory->path('x/y')));
        self::assertTrue($this->storage->ensureDirectory($this->directory->path('x/y')));
        self::assertDirectoryExists($this->directory->path('x/y'));

        $this->directory->write('file', '');
        self::assertFalse($this->storage->ensureDirectory($this->directory->path('file/z')));
    }

    public function testTouchCreatesAnEmptyFileAndUpdatesItsTime(): void
    {
        $file = $this->directory->path('a.css.404');

        self::assertTrue($this->storage->touch($file));
        self::assertSame('', file_get_contents($file));

        touch($file, 1000000000);
        clearstatcache();
        self::assertTrue($this->storage->touch($file));
        clearstatcache();
        self::assertGreaterThan(1000000000, filemtime($file));
    }

    public function testTouchFailsWithoutTheParentDirectory(): void
    {
        self::assertFalse($this->storage->touch($this->directory->path('missing/a.css.404')));
    }

    public function testDelete(): void
    {
        $file = $this->directory->write('a.css', 'x');

        $this->storage->delete($file);
        $this->storage->delete($file);
        $this->storage->delete($this->directory->path());

        self::assertFileDoesNotExist($file);
        self::assertDirectoryExists($this->directory->path());
    }
}
