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

    public function testADamagedFileStartsAgainFromZero(): void
    {
        $store = $this->store();
        self::assertTrue($store->add(['a' => 1]));
        file_put_contents($this->directory->path('var/metrics/' . FileMetricsStore::FILE_NAME), '{not json');

        self::assertSame([], $store->read());
        self::assertTrue($store->add(['a' => 2, 'b' => 1]));
        self::assertSame(['a' => 2, 'b' => 1], $store->read());
    }

    public function testFromConfig(): void
    {
        $config = TestConfig::create();
        $metrics = Metrics::fromConfig($config, $this->directory->path('var'));
        $metrics->recordRequest('GET', 'HIT', 200, 0.001);
        $metrics->flush();

        self::assertFileExists($this->directory->path('var/metrics/' . FileMetricsStore::FILE_NAME));
        self::assertSame(0700, fileperms($this->directory->path('var/metrics')) & 0777);
    }

    private function store(): MetricsStore
    {
        return new FileMetricsStore($this->directory->path('var/metrics'));
    }
}
