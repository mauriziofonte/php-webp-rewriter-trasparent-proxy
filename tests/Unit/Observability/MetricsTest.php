<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Observability;

use Mfonte\ProxyCdn\Cache\CacheUsage;
use Mfonte\ProxyCdn\Observability\FileMetricsStore;
use Mfonte\ProxyCdn\Observability\Metrics;
use Mfonte\ProxyCdn\Observability\MetricsStore;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Observability\Metrics
 * @covers \Mfonte\ProxyCdn\Observability\FileMetricsStore
 */
#[CoversClass(Metrics::class)]
#[CoversClass(FileMetricsStore::class)]
final class MetricsTest extends TestCase
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

    public function testTheExpositionFormat(): void
    {
        $metrics = new Metrics($this->store());
        $metrics->recordRequest('GET', 'MISS', 200, 0.003);
        $metrics->recordRequest('GET', 'MISS', 200, 0.2);
        $metrics->recordRequest('PURGE', null, 401, 0.0005);
        $metrics->recordOriginFetch('success');
        $metrics->recordPurge(Metrics::RESULT_REFUSED);
        $metrics->recordPeerPurge(false);
        $metrics->recordRateLimited();
        $metrics->recordRateLimitUnavailable();
        $metrics->recordEarlyHints();
        self::assertTrue($metrics->flush());

        $text = (new Metrics($this->store()))->render();

        self::assertStringContainsString(
            "# HELP proxy_cdn_requests_total Requests answered by PHP, by method, X-Cache-Status and HTTP status.\n"
            . "# TYPE proxy_cdn_requests_total counter\n"
            . "proxy_cdn_requests_total{method=\"GET\",cache=\"MISS\",status=\"200\"} 2\n"
            . "proxy_cdn_requests_total{method=\"PURGE\",cache=\"none\",status=\"401\"} 1\n",
            $text
        );
        self::assertStringContainsString(
            "proxy_cdn_request_duration_seconds_bucket{cache=\"MISS\",le=\"0.001\"} 0\n"
            . "proxy_cdn_request_duration_seconds_bucket{cache=\"MISS\",le=\"0.0025\"} 0\n"
            . "proxy_cdn_request_duration_seconds_bucket{cache=\"MISS\",le=\"0.005\"} 1\n",
            $text
        );
        self::assertStringContainsString("proxy_cdn_request_duration_seconds_bucket{cache=\"MISS\",le=\"0.25\"} 2\n", $text);
        self::assertStringContainsString("proxy_cdn_request_duration_seconds_bucket{cache=\"MISS\",le=\"+Inf\"} 2\n", $text);
        self::assertStringContainsString("proxy_cdn_request_duration_seconds_sum{cache=\"MISS\"} 0.203\n", $text);
        self::assertStringContainsString("proxy_cdn_request_duration_seconds_count{cache=\"MISS\"} 2\n", $text);
        self::assertStringContainsString("proxy_cdn_origin_fetches_total{outcome=\"success\"} 1\n", $text);
        self::assertStringContainsString("proxy_cdn_purges_total{result=\"refused\"} 1\n", $text);
        self::assertStringContainsString("proxy_cdn_peer_purges_total{result=\"failed\"} 1\n", $text);
        self::assertStringContainsString("# TYPE proxy_cdn_miss_rate_limited_total counter\nproxy_cdn_miss_rate_limited_total 1\n", $text);
        self::assertStringContainsString("# TYPE proxy_cdn_miss_rate_limit_unavailable_total counter\nproxy_cdn_miss_rate_limit_unavailable_total 1\n", $text);
        self::assertStringContainsString("proxy_cdn_early_hints_total 1\n", $text);
        self::assertStringEndsWith("\n", $text);
    }

    /**
     * Label values come from fixed sets: whatever a client sends, the
     * number of series stays bounded.
     */
    public function testLabelValuesAreBounded(): void
    {
        $metrics = new Metrics($this->store());
        for ($i = 0; $i < 50; ++$i) {
            $metrics->recordRequest('M' . $i, 'X-' . $i, 1000 + $i, 0.0);
            $metrics->recordOriginFetch('reason-' . $i);
            $metrics->recordPurge('result-' . $i);
        }
        $metrics->recordRequest('GET', 'HIT', 99, NAN);
        $metrics->flush();

        $text = (new Metrics($this->store()))->render();

        self::assertStringContainsString("proxy_cdn_requests_total{method=\"other\",cache=\"none\",status=\"other\"} 50\n", $text);
        self::assertStringContainsString("proxy_cdn_requests_total{method=\"GET\",cache=\"HIT\",status=\"other\"} 1\n", $text);
        self::assertStringContainsString("proxy_cdn_origin_fetches_total{outcome=\"other\"} 50\n", $text);
        self::assertStringContainsString("proxy_cdn_purges_total{result=\"other\"} 50\n", $text);
        self::assertSame(2, substr_count($text, 'proxy_cdn_requests_total{'));
        self::assertSame(1, substr_count($text, 'proxy_cdn_origin_fetches_total{'));
        self::assertSame(1, substr_count($text, 'proxy_cdn_purges_total{'));
    }

    public function testTheCacheGaugesComeFromTheLastScan(): void
    {
        $text = (new Metrics(null))->render(new CacheUsage(2048, 7, 1700000001));

        self::assertStringContainsString("# TYPE proxy_cdn_cache_bytes gauge\nproxy_cdn_cache_bytes 2048\n", $text);
        self::assertStringContainsString("proxy_cdn_cache_entries 7\n", $text);
        self::assertStringContainsString("proxy_cdn_cache_scan_timestamp_seconds 1700000001\n", $text);
        self::assertStringNotContainsString('proxy_cdn_cache_bytes', (new Metrics(null))->render());
        self::assertStringNotContainsString('proxy_cdn_cache_pending_entries', $text, 'not counted by that scan');
        self::assertStringContainsString(
            "# TYPE proxy_cdn_cache_pending_entries gauge\nproxy_cdn_cache_pending_entries 3\n",
            (new Metrics(null))->render(new CacheUsage(2048, 7, 1700000001, 3))
        );
    }

    public function testTheImageConversionsAreCountedByConverterAndResult(): void
    {
        $metrics = new Metrics($this->store());
        $metrics->recordImageConversion('remote', Metrics::RESULT_OK);
        $metrics->recordImageConversion('remote', Metrics::RESULT_OK);
        $metrics->recordImageConversion('remote', Metrics::CONVERSION_CIRCUIT_OPEN);
        $metrics->recordImageConversion('local', Metrics::CONVERSION_REJECTED);
        $metrics->recordImageConversion('other-' . mt_rand(), 'result-' . mt_rand());
        $metrics->flush();

        $text = (new Metrics($this->store()))->render();

        self::assertStringContainsString("# TYPE proxy_cdn_image_conversions_total counter\n", $text);
        self::assertStringContainsString("proxy_cdn_image_conversions_total{converter=\"remote\",result=\"ok\"} 2\n", $text);
        self::assertStringContainsString("proxy_cdn_image_conversions_total{converter=\"remote\",result=\"circuit_open\"} 1\n", $text);
        self::assertStringContainsString("proxy_cdn_image_conversions_total{converter=\"local\",result=\"rejected\"} 1\n", $text);
        self::assertStringContainsString("proxy_cdn_image_conversions_total{converter=\"other\",result=\"other\"} 1\n", $text);
    }

    public function testWithoutStoreNothingIsCounted(): void
    {
        $metrics = new Metrics(null);
        $metrics->recordRequest('GET', 'HIT', 200, 0.001);

        self::assertTrue($metrics->flush());
        self::assertSame(
            "# HELP proxy_cdn_up Whether the proxy answers (always 1 in a scrape that succeeds).\n"
            . "# TYPE proxy_cdn_up gauge\nproxy_cdn_up 1\n"
            . "# HELP proxy_cdn_metrics_available Whether the counters could be read from METRICS_STORE (0 when they are missing from this scrape).\n"
            . "# TYPE proxy_cdn_metrics_available gauge\nproxy_cdn_metrics_available 1\n",
            $metrics->render()
        );
    }

    /**
     * A store that cannot be read is reported, never read as a reset.
     */
    public function testAnUnreadableStoreIsReported(): void
    {
        $this->directory->write('metrics', 'a file where the directory is expected');
        $metrics = new Metrics(new FileMetricsStore($this->directory->path('metrics')));
        $metrics->recordRequest('GET', 'HIT', 200, 0.001);

        self::assertFalse($metrics->flush());
        self::assertStringContainsString("proxy_cdn_metrics_available 0\n", $metrics->render());
        self::assertStringNotContainsString('requests_total', $metrics->render());
    }

    /**
     * The directory of the counters is private to the PHP user
     * (LockDirectory): one that others can write to is refused.
     */
    public function testAStoreInADirectoryOthersCanWriteIsRefused(): void
    {
        $path = $this->directory->path('shared');
        mkdir($path, 0700);
        chmod($path, 0777);
        $store = new FileMetricsStore($path);

        self::assertFalse($store->add(['proxy_cdn_up' => 1]));
        self::assertNull($store->read());
    }

    /**
     * A shard whose slots cannot be decoded is read as empty and starts
     * again from zero: it stays in use.
     */
    public function testADamagedShardStartsAgainFromZero(): void
    {
        $store = $this->store();
        self::assertTrue($store->add(['a' => 1]));
        $files = $this->shardFiles();
        self::assertCount(1, $files);
        file_put_contents($files[0], '{not json');

        self::assertSame([], $store->read());
        self::assertTrue($store->add(['a' => 2, 'b' => 1]));
        self::assertSame(['a' => 2, 'b' => 1], $store->read());
        self::assertTrue($store->add(['a' => 1]));
        self::assertSame(['a' => 3, 'b' => 1], $store->read());
        self::assertCount(2, $this->shardFiles());
        self::assertStringEndsWith(' {"seq":2,"counters":{"a":3,"b":1}}', (string) file_get_contents($this->ownShard(true)));
    }

    /**
     * The first write of a shard cut by a full disk leaves its only slot
     * damaged: the shard is not lost for the updates that follow.
     */
    public function testAShardWhoseFirstWriteWasCutIsUsedAgain(): void
    {
        $store = $this->store();
        self::assertSame([], $store->read());
        file_put_contents($this->ownShard(), '0123abcd {"seq":1,"coun');

        self::assertSame([], $store->read());
        self::assertTrue($store->add(['a' => 1]));
        self::assertTrue($store->add(['a' => 1]));
        self::assertSame(['a' => 2], $store->read());
        self::assertCount(2, $this->shardFiles());
    }

    /**
     * Counters that cannot be encoded fail the update and write nothing.
     */
    public function testAnUpdateThatCannotBeEncodedWritesNothing(): void
    {
        $store = $this->store();
        self::assertTrue($store->add(['a' => 1]));
        $before = (string) file_get_contents($this->ownShard());

        self::assertFalse($store->add(["\xff" => 1]));
        self::assertSame(['a' => 1], $store->read());
        self::assertSame($before, file_get_contents($this->ownShard()));
        self::assertFalse(is_file($this->ownShard(true)) && filesize($this->ownShard(true)) > 0);
    }

    /**
     * Each update writes the slot that does not hold the newer counters.
     */
    public function testTheUpdatesAlternateBetweenTheSlotsOfAShard(): void
    {
        $store = $this->store();
        for ($i = 1; $i <= 3; ++$i) {
            self::assertTrue($store->add(['a' => 1]));
            self::assertSame(['a' => $i], $store->read());
        }

        self::assertStringEndsWith(' {"seq":3,"counters":{"a":3}}', (string) file_get_contents($this->ownShard()));
        self::assertStringEndsWith(' {"seq":2,"counters":{"a":2}}', (string) file_get_contents($this->ownShard(true)));
        self::assertSame(0644, fileperms($this->ownShard()) & 0777);
        self::assertSame(0644, fileperms($this->ownShard(true)) & 0777);
    }

    /**
     * A write that fails leaves the counters as they were: the sum never
     * falls, which Prometheus would count as a reset of every counter.
     */
    public function testAFailedWriteNeverLowersTheCounters(): void
    {
        $store = $this->store();
        self::assertTrue($store->add(['a' => 5, 'b' => 1]));
        // The slot to write next cannot be opened: a directory is in its place.
        mkdir($this->ownShard(true));

        try {
            self::assertFalse($store->add(['a' => 1]));
            self::assertSame(['a' => 5, 'b' => 1], $store->read());
        } finally {
            rmdir($this->ownShard(true));
        }

        self::assertTrue($store->add(['a' => 1]));
        self::assertSame(['a' => 6, 'b' => 1], $store->read());
    }

    /**
     * A writer that dies half way, or runs out of disk, leaves a part of
     * the slot it writes: the other slot still holds the counters, and the
     * next writer of the shard writes the spoilt slot again. (Two writes
     * mixed in one slot are covered by the checksum, below.)
     */
    public function testAWriterThatDiesHalfWayLosesNothing(): void
    {
        $store = $this->store();
        self::assertTrue($store->add(['a' => 5]));
        self::assertTrue($store->add(['a' => 1, 'b' => 2]));
        $json = '{"seq":3,"counters":{"a":7,"b":2}}';
        $next = sprintf('%08x', crc32($json)) . ' ' . $json;

        // The next update writes over the first slot: every part of it.
        for ($length = 1; $length < strlen($next); ++$length) {
            file_put_contents($this->ownShard(), substr($next, 0, $length));
            self::assertSame(['a' => 6, 'b' => 2], $store->read(), 'cut after ' . $length . ' bytes');
        }

        self::assertTrue($store->add(['a' => 1]));
        self::assertSame(['a' => 7, 'b' => 2], $store->read());
        self::assertStringEndsWith(' {"seq":3,"counters":{"a":7,"b":2}}', (string) file_get_contents($this->ownShard()));
    }

    /**
     * A slot that decodes, with a newer sequence number, but whose
     * checksum does not match is never read: the parts of two writes
     * can make valid JSON with counters older than the other slot's.
     */
    public function testASlotWhoseChecksumDoesNotMatchIsIgnored(): void
    {
        $store = $this->store();
        self::assertTrue($store->add(['a' => 50]));
        self::assertTrue($store->add(['a' => 50]));
        $slot = (string) file_get_contents($this->ownShard(true));
        file_put_contents($this->ownShard(), substr($slot, 0, 9) . '{"seq":9,"counters":{"a":1}}');

        self::assertSame(['a' => 100], $store->read());
        self::assertTrue($store->add(['a' => 1]));
        self::assertSame(['a' => 101], $store->read());
    }

    /**
     * The files of the store before the slots, the counters alone, are
     * read and added to.
     */
    public function testTheFormerFilesAreAddedTo(): void
    {
        $store = $this->store();
        self::assertSame([], $store->read());
        file_put_contents($this->ownShard(), '{"a":40}');

        self::assertSame(['a' => 40], $store->read());
        self::assertTrue($store->add(['a' => 2]));
        self::assertSame(['a' => 42], $store->read());
        self::assertSame('{"a":40}', file_get_contents($this->ownShard()));
    }

    /**
     * No slot is written through a symbolic link, and a store with one is
     * reported as unreadable.
     */
    public function testASlotIsNeverWrittenThroughALink(): void
    {
        $store = $this->store();
        self::assertTrue($store->add(['a' => 1]));
        $target = $this->directory->path('target');
        file_put_contents($target, 'untouched');
        symlink($target, $this->ownShard(true));

        self::assertTrue($store->add(['a' => 1]));
        self::assertSame('untouched', file_get_contents($target));
        self::assertNull($store->read());
        unlink($this->ownShard(true));
        self::assertSame(['a' => 2], $store->read());
    }

    /**
     * The counters are split over shards, one per writer at a time, and
     * summed when read; the first file is the one of the single-file store.
     */
    public function testTheCountersOfEveryShardAreSummed(): void
    {
        $store = $this->store();
        self::assertSame([], $store->read());
        file_put_contents($this->directory->path('var/metrics/' . FileMetricsStore::FILE_NAME), '{"a":1}');
        file_put_contents($this->directory->path('var/metrics/' . FileMetricsStore::fileName(3)), '{"a":2,"b":5}');

        self::assertSame(['a' => 3, 'b' => 5], $store->read());
    }

    /**
     * A writer never waits for a busy shard while another one is free.
     */
    public function testAnUpdateSkipsABusyShard(): void
    {
        $store = $this->store();
        self::assertTrue($store->add(['a' => 1]));
        $own = $this->directory->path('var/metrics/' . FileMetricsStore::lockName((int) getmypid() % FileMetricsStore::SHARDS));
        self::assertFileExists($own);
        $other = fopen($own, 'c+b');
        self::assertIsResource($other);
        self::assertTrue(flock($other, LOCK_EX));

        try {
            self::assertTrue($store->add(['a' => 1, 'b' => 1]));
        } finally {
            flock($other, LOCK_UN);
            fclose($other);
        }

        self::assertCount(2, $this->shardFiles());
        self::assertSame(['a' => 2, 'b' => 1], $store->read());
    }

    /**
     * However many writers, the files are the slots and lock files of the
     * SHARDS, of fixed names, and a writer holding every lock but one only
     * makes the next ones take the last.
     */
    public function testTheNumberOfFilesIsBounded(): void
    {
        $store = $this->store();
        self::assertSame([], $store->read());
        $held = [];
        for ($shard = 0; $shard < FileMetricsStore::SHARDS - 1; ++$shard) {
            $handle = fopen($this->directory->path('var/metrics/' . FileMetricsStore::lockName($shard)), 'c+b');
            self::assertIsResource($handle);
            self::assertTrue(flock($handle, LOCK_EX));
            $held[] = $handle;
        }
        for ($i = 0; $i < 20; ++$i) {
            self::assertTrue($store->add(['a' => 1]));
        }
        foreach ($held as $handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $last = FileMetricsStore::SHARDS - 1;
        self::assertSame(
            [FileMetricsStore::alternateName($last), FileMetricsStore::fileName($last)],
            array_map('basename', $this->shardFiles())
        );
        self::assertCount(FileMetricsStore::SHARDS + 2, array_diff(scandir($this->directory->path('var/metrics')) ?: [], ['.', '..']));
        self::assertSame(['a' => 20], $store->read());
    }

    public function testFromConfig(): void
    {
        $config = TestConfig::create();
        $metrics = Metrics::fromConfig($config, $this->directory->path('var'));
        $metrics->recordRequest('GET', 'HIT', 200, 0.001);
        $metrics->flush();

        self::assertCount(1, $this->shardFiles());
        self::assertSame(0644, fileperms($this->shardFiles()[0]) & 0777);
        self::assertSame(0644, fileperms($this->directory->path('var/metrics/' . FileMetricsStore::lockName((int) getmypid() % FileMetricsStore::SHARDS))) & 0777);
        self::assertSame(0700, fileperms($this->directory->path('var/metrics')) & 0777);
    }

    /**
     * @return list<string>
     */
    private function shardFiles(): array
    {
        $files = glob($this->directory->path('var/metrics') . '/metrics*.json');

        return $files === false ? [] : $files;
    }

    /**
     * A slot of the shard that an update of this process takes first.
     */
    private function ownShard(bool $alternate = false): string
    {
        $shard = (int) getmypid() % FileMetricsStore::SHARDS;

        return $this->directory->path('var/metrics/' . ($alternate ? FileMetricsStore::alternateName($shard) : FileMetricsStore::fileName($shard)));
    }

    private function store(): MetricsStore
    {
        return new FileMetricsStore($this->directory->path('var/metrics'));
    }
}
