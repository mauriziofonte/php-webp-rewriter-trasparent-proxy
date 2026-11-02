<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Concurrency;

use Mfonte\ProxyCdn\Cache\RequestCoalescer;
use Mfonte\ProxyCdn\Http\ProxyKernel;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Request coalescing across processes, as PHP-FPM workers run (finding
 * P-24): concurrent requests that miss the same entry cause exactly one
 * origin fetch, and every one of them gets the asset. When the leader
 * fails, the waiters share its failure: the origin still sees one fetch
 * (one per RequestCoalescer::OUTCOME_WINDOW while it keeps failing). When
 * the origin forbids storing, the waiters fetch in parallel instead of in
 * turn. A redirect or a 404 is shared as a failure is: one fetch, and no
 * waiter answers after another. The harness of tests/Integration/docker proves the first with 500
 * requests through Apache and nginx.
 *
 * @covers \Mfonte\ProxyCdn\Cache\RequestCoalescer
 * @covers \Mfonte\ProxyCdn\Http\ProxyKernel
 *
 * @group regression
 */
#[CoversClass(RequestCoalescer::class)]
#[CoversClass(ProxyKernel::class)]
#[Group('regression')]
final class RequestCoalescingTest extends TestCase
{
    private const PROCESSES = 40;

    private const ORIGIN_DELAY_MS = 500;

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open() is not available.');
        }
        $this->directory = new TemporaryDirectory();
    }

    protected function tear_down(): void
    {
        if ($this->directory !== null) {
            $this->directory->remove();
        }
        parent::tear_down();
    }

    public function testConcurrentMissesOfOneEntryCauseOneOriginFetchP24(): void
    {
        $results = $this->burst('https://cdn.example.test/css/burst.css');

        $fetches = file($this->directory->path('fetches.log'), FILE_IGNORE_NEW_LINES) ?: [];
        self::assertSame(['https://origin.example.test/css/burst.css'], $fetches, 'one origin fetch for the whole burst');

        $hash = hash('sha256', str_repeat("body { color: red; }\n", 100));
        $statuses = [];
        foreach ($results as $result) {
            self::assertSame(200, $result['status']);
            self::assertSame($hash, $result['hash']);
            $statuses[] = $result['cache'];
        }
        $counts = array_count_values($statuses);
        self::assertSame(1, $counts['MISS'] ?? 0, 'the leader');
        self::assertSame(self::PROCESSES - 1, $counts['HIT'] ?? 0, 'the requests that waited for it');
    }

    public function testTheWaitersOfAFailedLeaderShareItsFailureP24(): void
    {
        $results = $this->burst('https://cdn.example.test/css/failing.css', 'fail');

        $fetches = file($this->directory->path('fetches.log'), FILE_IGNORE_NEW_LINES) ?: [];
        self::assertSame(['https://origin.example.test/css/failing.css'], $fetches, 'one origin fetch: no herd on the failure path');
        foreach ($results as $result) {
            self::assertSame(503, $result['status']);
        }
    }

    public function testTheWaitersOfAnUncacheableResponseFetchInParallelP24(): void
    {
        $started = microtime(true);
        $results = $this->burst('https://cdn.example.test/css/private.css', 'private');
        $elapsed = microtime(true) - $started;

        $hash = hash('sha256', str_repeat("body { color: red; }\n", 100));
        foreach ($results as $result) {
            self::assertSame(200, $result['status']);
            self::assertSame('BYPASS', $result['cache']);
            self::assertSame($hash, $result['hash']);
        }
        self::assertCount(self::PROCESSES, file($this->directory->path('fetches.log'), FILE_IGNORE_NEW_LINES) ?: [], 'each its own response');
        // In turn, the fetches alone would take PROCESSES * ORIGIN_DELAY_MS.
        self::assertLessThan(self::PROCESSES * self::ORIGIN_DELAY_MS / 2000, $elapsed, 'not one after the other');
    }

    /**
     * A key the origin redirects: one fetch, and every request passes on
     * the same redirect at once, instead of each taking the lock in turn
     * and fetching again (which took PROCESSES * ORIGIN_DELAY_MS).
     */
    public function testTheWaitersOfARedirectedLeaderShareItsRedirectP24(): void
    {
        $started = microtime(true);
        $results = $this->burst('https://cdn.example.test/css/redirected.css', 'redirect');
        $elapsed = microtime(true) - $started;

        $fetches = file($this->directory->path('fetches.log'), FILE_IGNORE_NEW_LINES) ?: [];
        self::assertSame(['https://origin.example.test/css/redirected.css'], $fetches, 'one origin fetch');
        foreach ($results as $result) {
            self::assertSame(302, $result['status']);
            self::assertSame('/css/moved.css', $result['location']);
        }
        self::assertLessThan(self::PROCESSES * self::ORIGIN_DELAY_MS / 2000, $elapsed, 'not one after the other');
    }

    /**
     * A key the origin does not have, without the negative cache
     * (NEGATIVE_CACHE_TTL 0): the 404 is shared by the coalescer alone.
     */
    public function testTheWaitersOfANotFoundLeaderShareIts404P24(): void
    {
        $started = microtime(true);
        $results = $this->burst('https://cdn.example.test/css/missing.css', 'notfound');
        $elapsed = microtime(true) - $started;

        $fetches = file($this->directory->path('fetches.log'), FILE_IGNORE_NEW_LINES) ?: [];
        self::assertSame(['https://origin.example.test/css/missing.css'], $fetches, 'one origin fetch');
        foreach ($results as $result) {
            self::assertSame(404, $result['status']);
        }
        self::assertLessThan(self::PROCESSES * self::ORIGIN_DELAY_MS / 2000, $elapsed, 'not one after the other');
    }

    /**
     * A legacy "X.webp" URL: the fetch of "X.webp" and of its raster
     * siblings happen once for the burst, under the lock of "X.webp".
     */
    public function testALegacyWebpBurstFetchesTheSiblingOnceP24(): void
    {
        $results = $this->burst('https://cdn.example.test/img/a.webp', 'legacy');

        $fetches = file($this->directory->path('fetches.log'), FILE_IGNORE_NEW_LINES) ?: [];
        self::assertSame(['https://origin.example.test/img/a.webp', 'https://origin.example.test/img/a.jpg'], $fetches);
        $hash = hash('sha256', str_repeat("body { color: red; }\n", 100));
        foreach ($results as $result) {
            self::assertSame(200, $result['status']);
            self::assertSame($hash, $result['hash']);
        }
    }

    /**
     * Runs PROCESSES requests for $url at the same moment, each in its own
     * PHP process, and returns what each printed.
     *
     * @param string $mode what the origin answers (see coalesce-child.php)
     *
     * @return list<array{status: int, cache: string, hash: string, location: string|null}>
     */
    private function burst(string $url, string $mode = 'ok'): array
    {
        $script = dirname(__DIR__, 2) . '/Fixtures/Concurrency/coalesce-child.php';
        $start = sprintf('%.6F', microtime(true) + 1.5);
        $processes = [];
        for ($i = 0; $i < self::PROCESSES; ++$i) {
            $command = implode(' ', array_map('escapeshellarg', [
                PHP_BINARY,
                $script,
                $this->directory->path(),
                $this->directory->path('fetches.log'),
                $url,
                (string) self::ORIGIN_DELAY_MS,
                $start,
                $mode,
            ]));
            $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        $results = [];
        foreach ($processes as $entry) {
            list($process, $pipes) = $entry;
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
            $result = json_decode(trim($output), true);
            self::assertIsArray($result, $output . $errors);
            self::assertIsInt($result['status'] ?? null);
            self::assertIsString($result['hash'] ?? null);
            $results[] = [
                'status' => $result['status'],
                'cache' => isset($result['cache']) && is_string($result['cache']) ? $result['cache'] : 'none',
                'hash' => $result['hash'],
                'location' => isset($result['location']) && is_string($result['location']) ? $result['location'] : null,
            ];
        }

        return $results;
    }
}
