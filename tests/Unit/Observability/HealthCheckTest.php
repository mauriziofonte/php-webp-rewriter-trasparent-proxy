<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Observability;

use Mfonte\ProxyCdn\Observability\HealthCheck;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Observability\HealthCheck
 */
#[CoversClass(HealthCheck::class)]
final class HealthCheckTest extends TestCase
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

    public function testExistingWritableDirectoriesAreHealthy(): void
    {
        mkdir($this->directory->path('public/_cache'), 0755, true);
        mkdir($this->directory->path('var'), 0755, true);

        self::assertSame(
            ['status' => 'ok', 'checks' => ['cache' => 'ok', 'state' => 'ok']],
            (new HealthCheck($this->directory->path('public/_cache'), $this->directory->path('var')))->check()
        );
    }

    /**
     * A fresh installation, before the first miss created them.
     */
    public function testMissingDirectoriesBelowAWritableOneAreHealthy(): void
    {
        $check = new HealthCheck($this->directory->path('public/_cache'), $this->directory->path('var'));

        self::assertSame('ok', $check->check()['status']);
    }

    public function testAFileInTheWayIsUnhealthy(): void
    {
        $this->directory->write('var', 'not a directory');

        $report = (new HealthCheck($this->directory->path('public/_cache'), $this->directory->path('var/state')))->check();

        self::assertSame('unavailable', $report['status']);
        self::assertSame(['cache' => 'ok', 'state' => 'unwritable'], $report['checks']);
    }

    public function testAReadOnlyDirectoryIsUnhealthy(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root writes to read-only directories');
        }
        mkdir($this->directory->path('public'));
        mkdir($this->directory->path('public/_cache'), 0555);

        $report = (new HealthCheck($this->directory->path('public/_cache'), $this->directory->path('var')))->check();
        chmod($this->directory->path('public/_cache'), 0755);

        self::assertSame(['cache' => 'unwritable', 'state' => 'ok'], $report['checks']);
    }
}
