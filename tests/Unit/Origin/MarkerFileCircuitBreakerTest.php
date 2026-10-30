<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Origin;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Origin\MarkerFileCircuitBreaker;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Origin\MarkerFileCircuitBreaker
 */
#[CoversClass(MarkerFileCircuitBreaker::class)]
final class MarkerFileCircuitBreakerTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var MarkerFileCircuitBreaker
     */
    private $breaker;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->breaker = new MarkerFileCircuitBreaker(new FilesystemStorage(), 30);
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testItIsClosedAtFirst(): void
    {
        self::assertFalse($this->breaker->isOpen($this->directory->path('a.png')));
    }

    public function testAFailureOpensItAndCreatesTheDirectories(): void
    {
        $key = $this->directory->path('x/y/a.png');

        $this->breaker->recordFailure($key);

        self::assertFileExists($key . '.netfail');
        self::assertTrue($this->breaker->isOpen($key));
        self::assertFalse($this->breaker->isOpen($this->directory->path('x/y/b.png')), 'the breaker is per file (finding P-14)');
    }

    public function testItClosesAfterTheTtl(): void
    {
        $key = $this->directory->path('a.png');
        $this->breaker->recordFailure($key);

        self::assertTrue(touch($key . '.netfail', time() - 30));
        clearstatcache();

        self::assertFalse($this->breaker->isOpen($key));
    }

    public function testAFailureRefreshesAnExpiredMarker(): void
    {
        $key = $this->directory->path('a.png');
        $this->directory->write('a.png.netfail', '');
        self::assertTrue(touch($key . '.netfail', time() - 3600));
        clearstatcache();

        $this->breaker->recordFailure($key);
        clearstatcache();

        self::assertTrue($this->breaker->isOpen($key));
    }

    public function testResetClosesIt(): void
    {
        $key = $this->directory->path('a.png');
        $this->breaker->recordFailure($key);

        $this->breaker->reset($key);

        self::assertFileDoesNotExist($key . '.netfail');
        self::assertFalse($this->breaker->isOpen($key));
    }

    public function testResetWithoutMarkerIsHarmless(): void
    {
        $this->breaker->reset($this->directory->path('a.png'));

        self::assertFalse($this->breaker->isOpen($this->directory->path('a.png')));
    }

    public function testAZeroTtlNeverOpens(): void
    {
        $breaker = new MarkerFileCircuitBreaker(new FilesystemStorage(), 0);
        $key = $this->directory->path('a.png');

        $breaker->recordFailure($key);

        self::assertFalse($breaker->isOpen($key));
    }
}
