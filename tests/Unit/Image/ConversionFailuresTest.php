<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Image;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Image\ConversionFailures;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Image\ConversionFailures
 */
#[CoversClass(ConversionFailures::class)]
final class ConversionFailuresTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var ConversionFailures
     */
    private $failures;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->failures = new ConversionFailures(new FilesystemStorage(), $this->directory->path('failures'));
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testAFailureIsRememberedUntilItExpires(): void
    {
        self::assertFalse($this->failures->isRemembered('/cache/.webp/a.png.webp'));

        $this->failures->remember('/cache/.webp/a.png.webp', 60);

        self::assertTrue($this->failures->isRemembered('/cache/.webp/a.png.webp'));
        self::assertFalse($this->failures->isRemembered('/cache/.avif/a.png.avif'));
        $slots = glob($this->directory->path('failures/*.failed'));
        self::assertIsArray($slots);
        self::assertCount(1, $slots);
        file_put_contents($slots[0], (time() - 1) . ' ' . hash('sha256', '/cache/.webp/a.png.webp') . "\n");
        self::assertFalse($this->failures->isRemembered('/cache/.webp/a.png.webp'));
    }

    public function testOnlyAFinalFailureIsFinal(): void
    {
        $this->failures->remember('/cache/.webp/a.png.webp', 60);
        $this->failures->remember('/cache/.avif/a.png.avif', 60, true);

        self::assertFalse($this->failures->isFinal('/cache/.webp/a.png.webp'));
        self::assertTrue($this->failures->isFinal('/cache/.avif/a.png.avif'));
        self::assertTrue($this->failures->isRemembered('/cache/.avif/a.png.avif'));
        self::assertFalse($this->failures->isFinal('/cache/.avif/b.png.avif'));
        $slots = glob($this->directory->path('failures/*.failed'));
        self::assertIsArray($slots);
        foreach ($slots as $slot) {
            if (strpos((string) file_get_contents($slot), 'final') !== false) {
                file_put_contents($slot, (time() - 1) . ' ' . hash('sha256', '/cache/.avif/a.png.avif') . " final\n");
            }
        }
        self::assertFalse($this->failures->isFinal('/cache/.avif/a.png.avif'), 'an expired marker is no longer final');
    }

    public function testAZeroTtlRemembersNothing(): void
    {
        $this->failures->remember('/cache/.webp/a.png.webp', 0);

        self::assertFalse($this->failures->isRemembered('/cache/.webp/a.png.webp'));
        self::assertDirectoryDoesNotExist($this->directory->path('failures'));
    }

    public function testForgetKeepsTheMarkerOfAnotherVariant(): void
    {
        $this->failures->remember('/cache/.webp/a.png.webp', 60);

        $this->failures->forget('/cache/.webp/b.png.webp');
        self::assertTrue($this->failures->isRemembered('/cache/.webp/a.png.webp'));

        $this->failures->forget('/cache/.webp/a.png.webp');
        self::assertFalse($this->failures->isRemembered('/cache/.webp/a.png.webp'));
    }

    public function testAMalformedMarkerCountsAsNone(): void
    {
        $this->failures->remember('/cache/.webp/a.png.webp', 60);
        $slots = glob($this->directory->path('failures/*.failed'));
        self::assertIsArray($slots);
        file_put_contents($slots[0], 'garbage');

        self::assertFalse($this->failures->isRemembered('/cache/.webp/a.png.webp'));
    }
}
