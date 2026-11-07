<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Concurrency;

use Mfonte\ProxyCdn\Cache\RequestCoalescer;
use Mfonte\ProxyCdn\Http\ProxyKernel;
use Mfonte\ProxyCdn\Image\SignedUrlVerifier;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * A burst that lasts longer than the fetch of its leader: half of the
     * requests reach the proxy after the leader has answered and released
     * the lock, as they do when the workers are slow to start (it took a
     * loaded machine to show it with simultaneous requests). They answer
     * with the outcome the leader recorded instead of leading a fetch of
     * their own, which used to erase that outcome and refetch.
     *
     * @dataProvider provideLeaderOutcomes
     */
    #[DataProvider('provideLeaderOutcomes')]
    public function testTheLateRequestsOfABurstShareTheOutcomeOfItsLeaderP24(string $mode, int $status): void
    {
        $url = 'https://cdn.example.test/css/late-' . $mode . '.css';
        $results = $this->burst($url, $mode, 2 * self::ORIGIN_DELAY_MS / 1000);

        $fetches = file($this->directory->path('fetches.log'), FILE_IGNORE_NEW_LINES) ?: [];
        self::assertSame(['https://origin.example.test/css/late-' . $mode . '.css'], $fetches, 'one origin fetch for the whole burst');
        foreach ($results as $result) {
            self::assertSame($status, $result['status']);
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function provideLeaderOutcomes(): array
    {
        return [
            'stored' => ['ok', 200],
            'server error' => ['fail', 503],
            'transport error' => ['transport', 503],
            'redirect' => ['redirect', 302],
            'not found' => ['notfound', 404],
        ];
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
     * A legacy "X.webp" URL whose raster sibling is stored, expired, in the
     * tier PHP revalidates: the waiters do not take the expired sibling as
     * ready (the leader fetches it again), they wait for the fresh one the
     * leader stores, and every request gets it; the siblings are fetched
     * once. They used to wake at once, find nothing they could serve and
     * answer 503.
     */
    public function testALegacyWebpBurstOnAnExpiredSiblingWaitsForTheLeaderP24(): void
    {
        $this->burst('https://cdn.example.test/img/b.webp', 'legacy-short', 0.0, 1);
        $metadata = $this->directory->path('_cache/cdn.example.test/.m/.meta/img/b.jpg.json');
        $state = json_decode((string) file_get_contents($metadata), true);
        self::assertIsArray($state, 'the sibling is in the tier PHP revalidates');
        self::assertIsInt($state['validated_at'] ?? null);
        $state['validated_at'] -= 500;
        file_put_contents($metadata, json_encode($state));
        unlink($this->directory->path('fetches.log'));

        $results = $this->burst('https://cdn.example.test/img/b.webp', 'legacy-short');

        $fetches = file($this->directory->path('fetches.log'), FILE_IGNORE_NEW_LINES) ?: [];
        self::assertSame(['https://origin.example.test/img/b.webp', 'https://origin.example.test/img/b.jpg'], $fetches);
        $hash = hash('sha256', str_repeat("body { color: red; }\n", 100));
        foreach ($results as $result) {
            self::assertSame(200, $result['status']);
            self::assertSame($hash, $result['hash']);
        }
    }

    /**
     * Review F2: when the converter cannot resize now (here it hangs for
     * 3 s, then gives up), the leader streams the image without storing
     * it and tells its waiters to do the same, in parallel: none of them
     * resizes in turn after another, which made the last ones wait past
     * the coalescing timeout (32 s here) and answer 503.
     */
    public function testAResizeBurstWhileTheConverterHangsStreamsInParallel(): void
    {
        $url = 'https://cdn.example.test' . (new SignedUrlVerifier('resize-hang-key-0123456789abcdef0123', [8]))->pathOf('cdn.example.test', 8, '/img/photo.png', '');
        $started = microtime(true);

        $results = $this->burst($url, 'resize-hang', 0.0, 12);

        $elapsed = microtime(true) - $started;
        $png = hash('sha256', (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Image/files/image.png'));
        foreach ($results as $result) {
            self::assertSame(200, $result['status'], 'no request waited past the coalescing timeout');
            self::assertSame('BYPASS', $result['cache']);
            self::assertSame($png, $result['hash']);
        }
        self::assertLessThan(20.0, $elapsed, 'two conversion attempts in a row at most, not one per request');
        // Review G1: the waiters stream without resizing, even all at once.
        self::assertCount(1, $this->conversions(), 'one conversion attempt for the burst');
    }

    /**
     * Review G1: once a resize was unavailable, a second burst within the
     * window streams the image without any conversion, and does not renew
     * the window; a third one after it makes exactly one attempt, the one
     * of the request that holds the lock, while the others wait for it and
     * stream.
     */
    public function testAnUnavailableResizeIsTriedOncePerWindow(): void
    {
        $url = 'https://cdn.example.test' . (new SignedUrlVerifier('resize-hang-key-0123456789abcdef0123', [8]))->pathOf('cdn.example.test', 8, '/img/photo.png', '');
        $png = hash('sha256', (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Image/files/image.png'));
        $waves = [$this->burst($url, 'resize-fail', 0.0, 4)];
        self::assertCount(1, $this->conversions());
        $slots = glob($this->directory->path('.var/coalescing/*.outcome')) ?: [];
        self::assertCount(1, $slots);
        clearstatcache();
        $recordedAt = (int) filemtime($slots[0]);

        $waves[] = $this->burst($url, 'resize-fail', 0.0, 10, 0.75);
        self::assertLessThan($recordedAt + RequestCoalescer::OUTCOME_WINDOW, time(), 'the second burst ran within the window');
        self::assertCount(1, $this->conversions(), 'no conversion within the window');
        clearstatcache();
        self::assertSame($recordedAt, filemtime($slots[0]), 'the window is not renewed without an attempt');

        $wait = $recordedAt + RequestCoalescer::OUTCOME_WINDOW + 1 - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }
        $waves[] = $this->burst($url, 'resize-fail', 0.0, 10, 0.75);
        self::assertCount(2, $this->conversions(), 'one attempt after the window');

        foreach ($waves as $results) {
            foreach ($results as $result) {
                self::assertSame(200, $result['status']);
                self::assertSame('BYPASS', $result['cache']);
                self::assertSame($png, $result['hash']);
            }
        }
    }

    /**
     * The conversion attempts the converter of coalesce-child.php logged.
     *
     * @return list<string>
     */
    private function conversions(): array
    {
        $file = $this->directory->path('conversions.log');

        return is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
    }

    /**
     * Runs PROCESSES requests for $url at the same moment, each in its own
     * PHP process, and returns what each printed.
     *
     * @param string $mode      what the origin answers (see coalesce-child.php)
     * @param float  $spread    seconds over which the starts of the requests are spread evenly (0: all at once)
     * @param int    $processes how many requests
     * @param float  $lead      seconds from now to the first start, for the processes to load
     *
     * @return list<array{status: int, cache: string, hash: string, location: string|null}>
     */
    private function burst(string $url, string $mode = 'ok', float $spread = 0.0, int $processes = self::PROCESSES, float $lead = 1.5): array
    {
        $script = dirname(__DIR__, 2) . '/Fixtures/Concurrency/coalesce-child.php';
        $first = microtime(true) + $lead;
        $running = [];
        for ($i = 0; $i < $processes; ++$i) {
            $start = sprintf('%.6F', $first + $spread * $i / $processes);
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
            $running[] = [$process, $pipes];
        }

        $results = [];
        foreach ($running as $entry) {
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
