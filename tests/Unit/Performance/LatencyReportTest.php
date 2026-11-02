<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Performance;

use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Performance\LatencyReport;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Tests\Performance\LatencyReport
 */
#[CoversClass(LatencyReport::class)]
final class LatencyReportTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory('proxy-cdn-latency-');
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testPercentilesUseTheNearestRank(): void
    {
        $values = [5.0, 1.0, 4.0, 2.0, 3.0, 6.0, 7.0, 8.0, 9.0, 10.0];

        self::assertSame(5.0, LatencyReport::percentile($values, 50));
        self::assertSame(10.0, LatencyReport::percentile($values, 99));
        self::assertSame(1.0, LatencyReport::percentile($values, 0));
        self::assertSame(5.5, LatencyReport::median($values));
        self::assertSame(2.0, LatencyReport::median([3.0, 1.0, 2.0]));
    }

    public function testFpmDurationsAreReadFromTheAccessLog(): void
    {
        $log = "02/Nov/2026:02:19:54 +0000 \"GET /images/a.png?v=1&zz=1\" 200 1.204ms\n"
            . "02/Nov/2026:02:19:54 +0000 \"GET /images/a.png?v=1&zz=1\" 200 12ms\n"
            . "not a log line\n";

        self::assertSame([1.204, 12.0], LatencyReport::fpmDurations($log));
    }

    public function testARunWithinTheTargetsPasses(): void
    {
        $this->writeRun(['hitP50' => 0.32, 'staticP50' => 0.30, 'hitRps' => 9500.0, 'staticRps' => 10000.0, 'fetches' => 1]);

        $report = new LatencyReport($this->directory->path(), ['*' => 1.10], ['*' => 1.10], [], true);

        self::assertTrue($report->passed(), $report->markdown());
        self::assertStringContainsString('| nginx | web-server hit | p50 hit / static file (best of 1 round) | 1.067 |', $report->markdown());
        self::assertStringContainsString('| nginx | cold miss | p50 overhead (p50 54.000 ms - p50 of the origin 51.000 ms) | 3.000 ms | < 5 ms | PASS |', $report->markdown());
        self::assertStringContainsString('| nginx | PHP hit | server-side p50 | 1.000 ms | < 2 ms | PASS |', $report->markdown());
    }

    public function testASlowHitFailsTheGate(): void
    {
        $this->writeRun(['hitP50' => 0.60, 'staticP50' => 0.30, 'hitRps' => 5000.0, 'staticRps' => 10000.0, 'fetches' => 1]);

        $report = new LatencyReport($this->directory->path(), ['*' => 1.25], ['*' => 1.50], ['*' => 0.75], false);

        self::assertFalse($report->passed());
        self::assertStringContainsString('| 2.000 | <= 1.10 (gate 1.25) | FAIL |', $report->markdown());
        self::assertStringContainsString('| 0.500 | >= 0.90 (gate 0.75) | FAIL |', $report->markdown());
    }

    public function testAWiderGateTakesNoiseButReportsTheMissedTarget(): void
    {
        $this->writeRun(['hitP50' => 0.36, 'staticP50' => 0.30, 'hitRps' => 9000.0, 'staticRps' => 10000.0, 'fetches' => 1]);

        $report = new LatencyReport($this->directory->path(), ['*' => 1.25], ['*' => 1.50], ['*' => 0.75], false);

        self::assertTrue($report->passed(), $report->markdown());
        self::assertStringContainsString('| 1.200 | <= 1.10 (gate 1.25) | PASS (gate only) |', $report->markdown());
    }

    public function testAStackMayHaveALimitOfItsOwn(): void
    {
        $this->writeRun(['hitP50' => 0.39, 'staticP50' => 0.30, 'hitRps' => 8000.0, 'staticRps' => 10000.0, 'fetches' => 1]);

        self::assertFalse((new LatencyReport($this->directory->path(), ['*' => 1.25], ['*' => 1.50], ['*' => 0.75], false))->passed());
        $report = new LatencyReport($this->directory->path(), ['*' => 1.25, 'nginx' => 1.40], ['*' => 1.50], ['*' => 0.75], false);
        self::assertTrue($report->passed(), $report->markdown());
        self::assertStringContainsString('| 1.300 | <= 1.10 (gate 1.40) | PASS (gate only) |', $report->markdown());
    }

    public function testTheRequestsPerSecondHaveALimitOfTheirOwn(): void
    {
        $this->writeRun(['hitP50' => 0.30, 'staticP50' => 0.30, 'hitRps' => 7000.0, 'staticRps' => 10000.0, 'fetches' => 1]);

        self::assertFalse((new LatencyReport($this->directory->path(), [], [], ['*' => 0.75], false))->passed());
        $report = new LatencyReport($this->directory->path(), [], [], ['*' => 0.75, 'nginx' => 0.65], false);
        self::assertTrue($report->passed(), $report->markdown());
        self::assertStringContainsString('| 0.700 | >= 0.90 (gate 0.65) | PASS (gate only) |', $report->markdown());
    }

    public function testTheGateTakesTheBestRound(): void
    {
        // Round 2 is noisy (the hit slowed down, or the static file sped
        // up); round 3 is the best one.
        $this->writeRun(['hitP50' => 0.33, 'staticP50' => 0.30, 'hitRps' => 9000.0, 'staticRps' => 10000.0, 'fetches' => 1, 'rounds' => [
            [0.36, 0.30, 8000.0, 10000.0],
            [0.60, 0.30, 4000.0, 10000.0],
        ]]);

        $report = new LatencyReport($this->directory->path(), ['*' => 1.40], ['*' => 2.00], ['*' => 0.75], false);

        self::assertTrue($report->passed(), $report->markdown());
        self::assertStringContainsString('| nginx | web-server hit | p50 hit / static file (best of 3 rounds) | 1.100 | <= 1.10 (gate 1.40) | PASS |', $report->markdown());
        self::assertStringContainsString('| requests per second hit / static file (best of 3 rounds) | 0.900 | >= 0.90 (gate 0.75) | PASS |', $report->markdown());
        self::assertStringContainsString('(median of rounds) | 1.200 / 1.200 / 0.800 |', $report->markdown());
    }

    public function testASlowerHitInEveryRoundFailsTheGate(): void
    {
        $this->writeRun(['hitP50' => 0.48, 'staticP50' => 0.30, 'hitRps' => 6000.0, 'staticRps' => 10000.0, 'fetches' => 1, 'rounds' => [
            [0.51, 0.30, 5500.0, 10000.0],
            [0.54, 0.30, 5000.0, 10000.0],
        ]]);

        $report = new LatencyReport($this->directory->path(), ['*' => 1.40], ['*' => 2.00], ['*' => 0.75], false);

        self::assertFalse($report->passed());
        self::assertStringContainsString('| 1.600 | <= 1.10 (gate 1.40) | FAIL |', $report->markdown());
        self::assertStringContainsString('| 0.600 | >= 0.90 (gate 0.75) | FAIL |', $report->markdown());
    }

    public function testMoreThanOneOriginFetchForTheHerdFails(): void
    {
        $this->writeRun(['hitP50' => 0.30, 'staticP50' => 0.30, 'hitRps' => 10000.0, 'staticRps' => 10000.0, 'fetches' => 2]);

        $report = new LatencyReport($this->directory->path(), ['*' => 1.10], ['*' => 1.10], [], false);

        self::assertFalse($report->passed());
        self::assertStringContainsString('| nginx | thundering herd | origin fetches for 500 concurrent requests | 2 | 1 | FAIL |', $report->markdown());
    }

    public function testAbsoluteTargetsGateOnlyInStrictMode(): void
    {
        $this->writeRun(['hitP50' => 0.30, 'staticP50' => 0.30, 'hitRps' => 10000.0, 'staticRps' => 10000.0, 'fetches' => 1, 'phpMs' => 3.0]);

        self::assertTrue((new LatencyReport($this->directory->path(), ['*' => 1.10], ['*' => 1.10], [], false))->passed());
        self::assertFalse((new LatencyReport($this->directory->path(), ['*' => 1.10], ['*' => 1.10], [], true))->passed());
    }

    public function testFailedChecksInvalidateTheRun(): void
    {
        $this->writeRun(['hitP50' => 0.30, 'staticP50' => 0.30, 'hitRps' => 10000.0, 'staticRps' => 10000.0, 'fetches' => 1, 'failedChecks' => 3]);

        self::assertFalse((new LatencyReport($this->directory->path(), ['*' => 1.10], ['*' => 1.10], [], false))->passed());
    }

    public function testMissingResultsAreAnError(): void
    {
        $this->directory->write('run.json', '{"stacks": "nginx", "rounds": 1}');

        $this->expectException(\RuntimeException::class);

        new LatencyReport($this->directory->path(), ['*' => 1.10], ['*' => 1.10], [], false);
    }

    /**
     * Writes the files of a run of the nginx stack: one round from the
     * values, after one round for each entry of "rounds" (hit p50, static
     * p50, hit and static requests per second).
     *
     * @param array{hitP50: float, staticP50: float, hitRps: float, staticRps: float, fetches: int, phpMs?: float, failedChecks?: int, rounds?: list<array{0: float, 1: float, 2: float, 3: float}>} $values
     */
    private function writeRun(array $values): void
    {
        $rounds = $values['rounds'] ?? [];
        $rounds[] = [$values['hitP50'], $values['staticP50'], $values['hitRps'], $values['staticRps']];
        $this->directory->write('run.json', (string) json_encode([
            'php' => '8.5', 'stacks' => 'nginx', 'rounds' => count($rounds), 'phpRate' => 200, 'originDelayMs' => 50,
            'autoloader' => 'classmap-authoritative', 'preloading' => 'on', 'machine' => 'test', 'k6' => 'test',
        ]));
        $failed = $values['failedChecks'] ?? 0;
        foreach ($rounds as $index => $round) {
            $this->writeMeasurement('nginx-static-rate-' . ($index + 1), $round[1], 1000.0, 0);
            $this->writeMeasurement('nginx-hit-rate-' . ($index + 1), $round[0], 1000.0, $failed);
            $this->writeMeasurement('nginx-static-loop-' . ($index + 1), 1.0, $round[3], 0);
            $this->writeMeasurement('nginx-hit-loop-' . ($index + 1), 1.0, $round[2], 0);
        }
        $this->writeMeasurement('nginx-php-hit-rate', 2.0, 200.0, 0);
        $this->writeMeasurement('nginx-php-hit-loop', 5.0, 2000.0, 0);
        $this->writeMeasurement('nginx-origin-direct', 51.0, 19.0, 0);
        $this->writeMeasurement('nginx-miss', 54.0, 18.0, 0);
        $this->writeMeasurement('nginx-herd', 1000.0, 400.0, 0);
        $this->directory->write('nginx-herd.origin.json', (string) json_encode(['requests' => 500, 'originFetches' => $values['fetches']]));
        $phpMs = $values['phpMs'] ?? 1.0;
        $this->directory->write('nginx-php-hit-rate.fpm.log', sprintf("x \"GET /a?zz=1\" 200 %.3fms\nx \"GET /a?zz=1\" 200 %.3fms\n", $phpMs, $phpMs));
    }

    private function writeMeasurement(string $name, float $p50, float $rps, int $failedChecks): void
    {
        $this->directory->write($name . '.json', (string) json_encode([
            'name' => $name, 'requests' => 1000, 'rps' => $rps, 'p50' => $p50, 'p99' => $p50 * 2,
            'failedChecks' => $failedChecks, 'droppedIterations' => 0,
        ]));
    }
}
