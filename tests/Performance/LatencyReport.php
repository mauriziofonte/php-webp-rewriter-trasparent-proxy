<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Performance;

/**
 * The results of one run of tests/Performance/run.sh against the latency
 * targets of plan section 10, as a Markdown report and a list of checks.
 *
 * Two kinds of checks:
 * - the gate, which fails the run: every measurement valid, exactly one
 *   origin fetch for the herd, and the hit served by the web server within
 *   the given ratios of the static file served by the same server in the
 *   same run (the ratios survive noisy machines, absolute numbers do not);
 * - the absolute targets (PHP hit, cold-miss overhead), which are reported,
 *   and gate only in strict mode.
 *
 * Each round measures the hit and then the static file. On a shared
 * machine a busy neighbour slows one measurement or the other: within one
 * run, the ratios of single rounds spread by up to 0.7 (p50), 4.0 (p99)
 * and 0.6 (requests per second), so the gate uses neither the ratio of
 * one round nor the best or the median one (the median is reported):
 * a round whose static file was slowed flatters the hit. It compares
 * the best value of each side over the rounds instead, which is that
 * side on the quietest machine the run saw: contention only adds latency
 * and only takes throughput away. The lowest p50 and p99 of the hit are
 * divided by the lowest p50 and p99 of the static file, the highest
 * requests per second of the hit by the highest of the static file.
 * A slower hit path makes every value of the hit worse, so the gate still
 * sees it, while one noisy measurement no longer fails the run.
 *
 * The overhead of a cold miss is its latency minus the origin time, which
 * is measured: the latency of the same requests sent to the origin
 * directly, from the same network, with the same fixed latency.
 *
 * It reads the files run.sh writes: run.json, one summary of k6 per
 * measurement (<stack>-<measurement>.json), the PHP-FPM access log lines of
 * the PHP hits and the origin fetches of the herd.
 */
final class LatencyReport
{
    /** Target: a hit served by PHP, server side, p50 in milliseconds. */
    public const PHP_HIT_P50_MS = 2.0;

    /** Target: a hit served by PHP, server side, p99 in milliseconds. */
    public const PHP_HIT_P99_MS = 10.0;

    /** Target: the p50 of a cold miss minus the p50 of the origin, in milliseconds. */
    public const MISS_OVERHEAD_P50_MS = 5.0;

    /** Target: how much worse than the static file a hit may be (10%). */
    public const HIT_RATIO = 1.10;

    /**
     * @var string
     */
    private $directory;

    /**
     * @var array<string, float>
     */
    private $maxRatio;

    /**
     * @var array<string, float>
     */
    private $maxP99Ratio;

    /**
     * @var array<string, float>
     */
    private $minRpsRatio;

    /**
     * @var bool
     */
    private $strict;

    /**
     * @var array<string, mixed>
     */
    private $run;

    /**
     * @var list<array{stack: string, path: string, metric: string, measured: string, target: string, status: string, gate: bool}>
     */
    private $rows = [];

    /**
     * @param string               $directory   the results directory of run.sh
     * @param array<string, float> $maxRatio    gate, per stack name or "*" for any other stack: highest p50 ratio of
     *                                          hit to static file (default 1.10, the target)
     * @param array<string, float> $maxP99Ratio gate, per stack name or "*": highest p99 ratio of hit to static file
     *                                          (default 1.10)
     * @param array<string, float> $minRpsRatio gate, per stack name or "*": lowest ratio of the requests per second of
     *                                          the hit to those of the static file (default 0.90)
     * @param bool                 $strict      whether the absolute targets gate too
     *
     * @throws \RuntimeException when run.json is missing or invalid
     */
    public function __construct(string $directory, array $maxRatio, array $maxP99Ratio, array $minRpsRatio, bool $strict)
    {
        $this->directory = rtrim($directory, '/');
        $this->maxRatio = $maxRatio;
        $this->maxP99Ratio = $maxP99Ratio;
        $this->minRpsRatio = $minRpsRatio;
        $this->strict = $strict;
        $this->run = $this->readJson('run.json');
        foreach ($this->stacks() as $stack) {
            $this->evaluate($stack);
        }
        if (is_file($this->directory . '/micro.json')) {
            $micro = $this->readJson('micro.json');
            $medians = is_array($micro['medianMicroseconds'] ?? null) ? $micro['medianMicroseconds'] : [];
            foreach ($medians as $case => $median) {
                $this->info('PHP', 'microbenchmark', (string) $case, sprintf('%.1f us (median)', self::floatOf($median)));
            }
        }
    }

    /**
     * Whether every check that gates passed.
     */
    public function passed(): bool
    {
        foreach ($this->rows as $row) {
            if ($row['gate'] && $row['status'] === 'FAIL') {
                return false;
            }
        }

        return true;
    }

    /**
     * The report as Markdown.
     */
    public function markdown(): string
    {
        $text = sprintf(
            "### Performance suite: PHP %s, %s autoloader, preloading %s, Apache rules %s\n\nMachine: %s. Load generator: k6 %s. "
            . "Absolute targets %s.\n\n",
            self::stringOf($this->run['php'] ?? '?'),
            self::stringOf($this->run['autoloader'] ?? '?'),
            self::stringOf($this->run['preloading'] ?? '?'),
            self::stringOf($this->run['apacheRules'] ?? '?'),
            self::stringOf($this->run['machine'] ?? '?'),
            self::stringOf($this->run['k6'] ?? '?'),
            $this->strict ? 'gate (strict)' : 'reported, not gating'
        );
        $text .= "| Stack | Path | Metric | Measured | Target | Status |\n| --- | --- | --- | --- | --- | --- |\n";
        foreach ($this->rows as $row) {
            $text .= sprintf(
                "| %s | %s | %s | %s | %s | %s%s |\n",
                $row['stack'],
                $row['path'],
                $row['metric'],
                $row['measured'],
                $row['target'],
                $row['status'],
                $row['gate'] || $row['status'] === 'info' ? '' : ' (not gating)'
            );
        }

        return $text . sprintf("\nResult: %s\n", $this->passed() ? 'PASS' : 'FAIL');
    }

    /**
     * The run, the rows and the outcome, for summary.json.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run' => $this->run,
            'gate' => [
                'statistic' => 'best value of each side over the rounds',
                'maxRatio' => $this->maxRatio,
                'maxP99Ratio' => $this->maxP99Ratio,
                'minRpsRatio' => $this->minRpsRatio,
                'strict' => $this->strict,
            ],
            'rows' => $this->rows,
            'passed' => $this->passed(),
        ];
    }

    /**
     * The value at $percentile (0 to 100) of $values, by the nearest-rank
     * method.
     *
     * @param list<float> $values
     */
    public static function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            throw new \InvalidArgumentException('No values');
        }
        sort($values);
        $rank = (int) ceil($percentile / 100 * count($values));

        return $values[max(0, min(count($values) - 1, $rank - 1))];
    }

    /**
     * The median of $values.
     *
     * @param list<float> $values
     */
    public static function median(array $values): float
    {
        if ($values === []) {
            throw new \InvalidArgumentException('No values');
        }
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * The request durations of PHP-FPM access log lines in the format of
     * tests/Integration/docker/php-fpm/zz-harness.conf ("... 1.234ms"), in
     * milliseconds.
     *
     * @return list<float>
     */
    public static function fpmDurations(string $log): array
    {
        $durations = [];
        foreach (preg_split('/\R/', $log) ?: [] as $line) {
            if (preg_match('/ ([0-9]+(?:\.[0-9]+)?)ms$/', $line, $match) === 1) {
                $durations[] = (float) $match[1];
            }
        }

        return $durations;
    }

    /**
     * @return list<string>
     */
    private function stacks(): array
    {
        $stacks = preg_split('/\s+/', trim(self::stringOf($this->run['stacks'] ?? ''))) ?: [];

        return array_values(array_filter($stacks, static function (string $stack): bool {
            return $stack !== '';
        }));
    }

    private function evaluate(string $stack): void
    {
        $rounds = self::intOf($this->run['rounds'] ?? 0);
        $p50 = [];
        $hitP50 = [];
        $staticP50 = [];
        $hitP99 = [];
        $staticP99 = [];
        $hitRps = [];
        $staticRps = [];
        $p99 = [];
        $rps = [];
        $valid = true;
        for ($round = 1; $round <= $rounds; ++$round) {
            $staticRate = $this->measurement($stack . '-static-rate-' . $round);
            $hitRate = $this->measurement($stack . '-hit-rate-' . $round);
            $staticLoop = $this->measurement($stack . '-static-loop-' . $round);
            $hitLoop = $this->measurement($stack . '-hit-loop-' . $round);
            $valid = $valid && self::isValid($staticRate) && self::isValid($hitRate) && self::isValid($staticLoop) && self::isValid($hitLoop);
            $p50[] = $hitRate['p50'] / $staticRate['p50'];
            $hitP50[] = $hitRate['p50'];
            $staticP50[] = $staticRate['p50'];
            $hitP99[] = $hitRate['p99'];
            $staticP99[] = $staticRate['p99'];
            $hitRps[] = $hitLoop['rps'];
            $staticRps[] = $staticLoop['rps'];
            $p99[] = $hitRate['p99'] / $staticRate['p99'];
            $rps[] = $hitLoop['rps'] / $staticLoop['rps'];
            $this->info($stack, 'web-server hit, round ' . $round, 'p50 / p99 (static file)', sprintf(
                '%.3f / %.3f ms (%.3f / %.3f ms)',
                $hitRate['p50'],
                $hitRate['p99'],
                $staticRate['p50'],
                $staticRate['p99']
            ));
            $this->info($stack, 'web-server hit, round ' . $round, 'requests per second (static file)', sprintf(
                '%.0f (%.0f)',
                $hitLoop['rps'],
                $staticLoop['rps']
            ));
        }
        $this->check($stack, 'all measurements', 'every response a 200 with the expected X-Cache-Status, under 1% dropped', $valid ? 'yes' : 'no', 'yes', $valid, true);
        if ($rounds > 0) {
            $of = sprintf(' of %d %s each)', $rounds, $rounds === 1 ? 'round' : 'rounds');
            // Lowest values are percentile 0, highest ones percentile 100.
            $this->ratio(
                $stack,
                'p50 hit / static file (lowest' . $of,
                self::percentile($hitP50, 0) / self::percentile($staticP50, 0),
                self::limitFor($this->maxRatio, $stack, self::HIT_RATIO),
                true
            );
            $this->ratio(
                $stack,
                'p99 hit / static file (lowest' . $of,
                self::percentile($hitP99, 0) / self::percentile($staticP99, 0),
                self::limitFor($this->maxP99Ratio, $stack, self::HIT_RATIO),
                true
            );
            $this->ratio(
                $stack,
                'requests per second hit / static file (highest' . $of,
                self::percentile($hitRps, 100) / self::percentile($staticRps, 100),
                self::limitFor($this->minRpsRatio, $stack, 2 - self::HIT_RATIO),
                false
            );
            $this->info($stack, 'web-server hit', 'p50 / p99 / requests per second, hit / static file (median of rounds)', sprintf(
                '%.3f / %.3f / %.3f',
                self::median($p50),
                self::median($p99),
                self::median($rps)
            ));
        }

        $phpRate = $this->measurement($stack . '-php-hit-rate');
        $phpLoop = $this->measurement($stack . '-php-hit-loop');
        $durations = self::fpmDurations($this->readFile($stack . '-php-hit-rate.fpm.log'));
        if ($durations === []) {
            throw new \RuntimeException($stack . ': no PHP-FPM durations for the PHP hits');
        }
        $serverP50 = self::percentile($durations, 50);
        $serverP99 = self::percentile($durations, 99);
        $this->check($stack, 'PHP hit', 'server-side p50', sprintf('%.3f ms', $serverP50), sprintf('< %.0f ms', self::PHP_HIT_P50_MS), $serverP50 < self::PHP_HIT_P50_MS, $this->strict);
        $this->check($stack, 'PHP hit', 'server-side p99', sprintf('%.3f ms', $serverP99), sprintf('< %.0f ms', self::PHP_HIT_P99_MS), $serverP99 < self::PHP_HIT_P99_MS, $this->strict);
        $this->info($stack, 'PHP hit', 'client p50 / p99 at ' . self::intOf($this->run['phpRate'] ?? 0) . ' requests per second', sprintf('%.3f / %.3f ms', $phpRate['p50'], $phpRate['p99']));
        $this->info($stack, 'PHP hit', 'requests per second', sprintf('%.0f', $phpLoop['rps']));
        $this->check($stack, 'PHP hit', 'valid measurement', self::isValid($phpRate) && self::isValid($phpLoop) ? 'yes' : 'no', 'yes', self::isValid($phpRate) && self::isValid($phpLoop), true);

        // Origin time: the same requests sent to the origin directly, from
        // the same network, with the same fixed latency.
        $delay = (float) self::intOf($this->run['originDelayMs'] ?? 0);
        $miss = $this->measurement($stack . '-miss');
        $direct = $this->measurement($stack . '-origin-direct');
        $overhead = $miss['p50'] - $direct['p50'];
        $this->check($stack, 'cold miss', sprintf('p50 overhead (p50 %.3f ms - p50 of the origin %.3f ms)', $miss['p50'], $direct['p50']), sprintf('%.3f ms', $overhead), sprintf('< %.0f ms', self::MISS_OVERHEAD_P50_MS), $overhead < self::MISS_OVERHEAD_P50_MS, $this->strict);
        $this->info($stack, 'cold miss', 'p99 overhead (p99 - p99 of the origin)', sprintf('%.3f ms', $miss['p99'] - $direct['p99']));
        $this->info($stack, 'cold miss', sprintf('p50 - fixed origin latency (%.0f ms)', $delay), sprintf('%.3f ms', $miss['p50'] - $delay));
        $this->check($stack, 'cold miss', 'valid measurement', self::isValid($miss) && self::isValid($direct) ? 'yes' : 'no', 'yes', self::isValid($miss) && self::isValid($direct), true);

        $herd = $this->measurement($stack . '-herd');
        $origin = $this->readJson($stack . '-herd.origin.json');
        $fetches = self::intOf($origin['originFetches'] ?? -1);
        $this->check($stack, 'thundering herd', sprintf('origin fetches for %d concurrent requests', self::intOf($origin['requests'] ?? 0)), (string) $fetches, '1', $fetches === 1 && self::isValid($herd), true);
    }

    /**
     * The limit of $stack in $limits, else the one of "*", else the target.
     *
     * @param array<string, float> $limits
     */
    private static function limitFor(array $limits, string $stack, float $target): float
    {
        return $limits[$stack] ?? $limits['*'] ?? $target;
    }

    private function ratio(string $stack, string $metric, float $ratio, float $limit, bool $atMost): void
    {
        $target = $atMost ? self::HIT_RATIO : 2 - self::HIT_RATIO;
        $meetsTarget = $atMost ? $ratio <= $target : $ratio >= $target;
        $withinGate = $atMost ? $ratio <= $limit : $ratio >= $limit;
        $this->rows[] = [
            'stack' => $stack,
            'path' => 'web-server hit',
            'metric' => $metric,
            'measured' => sprintf('%.3f', $ratio),
            'target' => sprintf('%s %.2f (gate %.2f)', $atMost ? '<=' : '>=', $target, $limit),
            'status' => $withinGate ? ($meetsTarget ? 'PASS' : 'PASS (gate only)') : 'FAIL',
            'gate' => true,
        ];
    }

    private function check(string $stack, string $path, string $metric, string $measured, string $target, bool $passed, bool $gate): void
    {
        $this->rows[] = [
            'stack' => $stack,
            'path' => $path,
            'metric' => $metric,
            'measured' => $measured,
            'target' => $target,
            'status' => $passed ? 'PASS' : 'FAIL',
            'gate' => $gate,
        ];
    }

    private function info(string $stack, string $path, string $metric, string $measured): void
    {
        $this->rows[] = [
            'stack' => $stack,
            'path' => $path,
            'metric' => $metric,
            'measured' => $measured,
            'target' => '',
            'status' => 'info',
            'gate' => false,
        ];
    }

    /**
     * Whether every response passed the checks of k6, and k6 sent at least
     * 99% of the requests it had to: at a fixed rate it drops a request
     * when all its clients are busy, which a stall of the machine causes.
     *
     * @param array{p50: float, p99: float, rps: float, failedChecks: int, droppedIterations: int, requests: int} $measurement
     */
    private static function isValid(array $measurement): bool
    {
        return $measurement['failedChecks'] === 0 && $measurement['requests'] > 0
            && $measurement['droppedIterations'] >= 0 && $measurement['droppedIterations'] * 100 <= $measurement['requests'];
    }

    /**
     * The k6 summary of a measurement.
     *
     * @return array{p50: float, p99: float, rps: float, failedChecks: int, droppedIterations: int, requests: int}
     */
    private function measurement(string $name): array
    {
        $summary = $this->readJson($name . '.json');
        $measurement = [
            'p50' => self::floatOf($summary['p50'] ?? null),
            'p99' => self::floatOf($summary['p99'] ?? null),
            'rps' => self::floatOf($summary['rps'] ?? null),
            'failedChecks' => self::intOf($summary['failedChecks'] ?? -1),
            'droppedIterations' => self::intOf($summary['droppedIterations'] ?? -1),
            'requests' => self::intOf($summary['requests'] ?? 0),
        ];
        if ($measurement['p50'] <= 0.0 || $measurement['p99'] <= 0.0 || $measurement['rps'] <= 0.0) {
            throw new \RuntimeException($name . ': the summary has no latency or rate');
        }

        return $measurement;
    }

    /**
     * @throws \RuntimeException
     *
     * @return array<string, mixed>
     */
    private function readJson(string $name): array
    {
        $decoded = json_decode($this->readFile($name), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException($name . ' is not a JSON object');
        }
        $object = [];
        foreach ($decoded as $key => $value) {
            $object[(string) $key] = $value;
        }

        return $object;
    }

    /**
     * @throws \RuntimeException
     */
    private function readFile(string $name): string
    {
        $contents = @file_get_contents($this->directory . '/' . $name);
        if ($contents === false) {
            throw new \RuntimeException('Missing result file: ' . $this->directory . '/' . $name);
        }

        return $contents;
    }

    /**
     * @param mixed $value
     */
    private static function floatOf($value): float
    {
        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }

    /**
     * @param mixed $value
     */
    private static function intOf($value): int
    {
        return is_int($value) ? $value : (is_float($value) ? (int) $value : (is_string($value) && is_numeric($value) ? (int) $value : -1));
    }

    /**
     * @param mixed $value
     */
    private static function stringOf($value): string
    {
        return is_scalar($value) ? (string) $value : '?';
    }
}
