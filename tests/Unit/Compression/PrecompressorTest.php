<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Compression;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Compression\Precompressor;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Compression\Precompressor
 */
#[CoversClass(Precompressor::class)]
final class PrecompressorTest extends TestCase
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

    public function testAGzipSiblingIsWritten(): void
    {
        if (!function_exists('gzencode')) {
            self::markTestSkipped('ext-zlib is not loaded');
        }
        $css = str_repeat("body { color: red; }\n", 100);
        $location = (new CacheKeyResolver($this->directory->path()))->resolve('h', '/css/a.css', '');
        $this->directory->write('h/css/a.css', $css);

        $written = (new Precompressor(new FilesystemStorage()))->compress($location, 'css');

        self::assertContains('gz', $written);
        self::assertSame($css, gzdecode((string) file_get_contents($location->getEncodedFile('gz'))));
        self::assertSame(function_exists('brotli_compress'), in_array('br', $written, true));
    }

    public function testSmallIncompressibleAndDisabledEntriesAreLeftAlone(): void
    {
        $resolver = new CacheKeyResolver($this->directory->path());
        $small = $resolver->resolve('h', '/a.css', '');
        $this->directory->write('h/a.css', 'small');
        $image = $resolver->resolve('h', '/a.png', '');
        $this->directory->write('h/a.png', str_repeat('x', 1000));
        $random = $resolver->resolve('h', '/b.css', '');
        $this->directory->write('h/b.css', random_bytes(1000));
        $disabled = $resolver->resolve('h', '/c.css', '');
        $this->directory->write('h/c.css', str_repeat('x', 1000));

        $precompressor = new Precompressor(new FilesystemStorage());

        self::assertSame([], $precompressor->compress($small, 'css'));
        self::assertSame([], $precompressor->compress($image, 'png'));
        self::assertSame([], $precompressor->compress($random, 'css'));
        self::assertSame([], (new Precompressor(new FilesystemStorage(), false))->compress($disabled, 'css'));
        self::assertSame([], $precompressor->compress($resolver->resolve('h', '/missing.css', ''), 'css'));
        self::assertDirectoryDoesNotExist($this->directory->path('h/.gz'));
    }

    public function testCompressibleExtensions(): void
    {
        self::assertTrue(Precompressor::isCompressible('svg'));
        self::assertTrue(Precompressor::isCompressible('js'));
        self::assertFalse(Precompressor::isCompressible('woff2'));
        self::assertFalse(Precompressor::isCompressible('jpg'));
    }
}
