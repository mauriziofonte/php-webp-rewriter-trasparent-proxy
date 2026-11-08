<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Origin;

use Mfonte\ProxyCdn\Origin\ResponseBody;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Origin\ResponseBody
 */
#[CoversClass(ResponseBody::class)]
final class ResponseBodyTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testTheFileIsDeletedWithTheObject(): void
    {
        $path = $this->directory->write('body', 'abc');
        $body = new ResponseBody($path, 3);

        self::assertSame($path, $body->getPath());
        self::assertSame(3, $body->getSize());
        self::assertSame('abc', $body->getContents());

        unset($body);

        self::assertFileDoesNotExist($path);
    }

    public function testAMovedFileIsKept(): void
    {
        $path = $this->directory->write('body', 'abc');
        $target = $this->directory->path('cache/a.css');
        mkdir(dirname($target));
        $body = new ResponseBody($path, 3);

        self::assertTrue($body->moveTo($target));
        self::assertSame($target, $body->getPath());
        self::assertFalse($body->moveTo($this->directory->path('again')), 'only the temporary file is moved');
        $body->delete();
        unset($body);

        self::assertFileDoesNotExist($path);
        self::assertStringEqualsFile($target, 'abc');
    }

    public function testAFailedMoveKeepsTheTemporaryFile(): void
    {
        $path = $this->directory->write('body', 'abc');
        $body = new ResponseBody($path, 3);

        self::assertFalse($body->moveTo($this->directory->path('missing/dir/a.css')));
        self::assertFileExists($path);

        $body->delete();

        self::assertFileDoesNotExist($path);
        self::assertNull($body->getContents());
    }
}
