<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Observability;

use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Config\OperationsConfig;

/**
 * The Prometheus metrics of the proxy, counted in a MetricsStore shared by
 * every PHP process and rendered in the text exposition format 0.0.4 by
 * /metrics, with the conventions of the conversion API's metrics:
 *
 * | Metric                                  | Type      | Labels                 |
 * | --------------------------------------- | --------- | ---------------------- |
 * | proxy_cdn_up                            | gauge     |                        |
 * | proxy_cdn_metrics_available             | gauge     |                        |
 * | proxy_cdn_requests_total                | counter   | method, cache, status  |
 * | proxy_cdn_request_duration_seconds      | histogram | cache                  |
 * | proxy_cdn_origin_fetches_total          | counter   | outcome                |
 *
 * - Only the requests that reach PHP are counted: the hits the web server
 *   serves never do (read them from its own logs or status module).
 * - method: GET, HEAD, OPTIONS, PURGE or "other"; cache: the
 *   X-Cache-Status of the response (HIT, MISS, STALE, REVALIDATED,
 *   BYPASS) or "none"; status: the HTTP status, or "other" outside
 *   100-599; outcome: "success", "not_modified" or the failure reason of
 *   the origin fetch (OriginResponse::FAILURE_*).
 *   Every label value comes from a fixed set, never from the request, so
 *   the series cannot grow with the traffic, and no label names a host,
 *   a path, a peer or a version.
 * - metrics_available is 0 when the store cannot be read: the counters are
 *   then missing from the scrape, not reset.
 *
 * The increments of one request are kept in this object and written to the
 * store in one update by flush(), which the front controller runs once the
 * response is sent. Without a store (METRICS_STORE=off), nothing is
 * counted and only the gauges are rendered.
 */
final class Metrics
{
    public const PREFIX = 'proxy_cdn_';

    /**
     * Label value of a request without X-Cache-Status, or of an unknown one.
     */
    public const NONE = 'none';

    /**
     * Label value of a method, status or outcome outside the fixed sets.
     */
    public const OTHER = 'other';

    /**
     * Upper bounds of the histogram buckets, in seconds: hits served by PHP
     * take about a millisecond, misses up to UPSTREAM_TIMEOUT.
     */
    public const BUCKETS = [0.001, 0.0025, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0];

    private const METHODS = ['GET', 'HEAD', 'OPTIONS', 'PURGE'];

    private const CACHE_STATUSES = ['HIT', 'MISS', 'STALE', 'REVALIDATED', 'BYPASS'];

    /**
     * The outcomes of an origin fetch (OriginResponse::FAILURE_* and the
     * two successes).
     */
    private const OUTCOMES = [
        'success', 'not_modified', 'not_found', 'gone', 'forbidden', 'redirect', 'client_error', 'server_error',
        'too_large', 'timeout', 'dns', 'connect', 'tls', 'network', 'other',
    ];

    private const FAMILIES = [
        'requests_total' => ['counter', 'Requests answered by PHP, by method, X-Cache-Status and HTTP status.'],
        'request_duration_seconds' => ['histogram', 'Time PHP took to build the response, by X-Cache-Status.'],
        'origin_fetches_total' => ['counter', 'Requests sent to the origins, by outcome.'],
    ];

    /**
     * Suffix of the counters that hold histogram sums, in microseconds.
     */
    private const SUM_SUFFIX = '_sum_us';

    /**
     * @var MetricsStore|null
     */
    private $store;

    /**
     * @var array<string, int>
     */
    private $pending = [];

    /**
     * @param MetricsStore|null $store null when nothing is counted (METRICS_STORE=off)
     */
    public function __construct(?MetricsStore $store)
    {
        $this->store = $store;
    }

    /**
     * The metrics of the configuration: counted in var/metrics/ of the
     * project unless METRICS_STORE is off.
     *
     * @param string $stateDirectory the state directory of the proxy (var/ of the project)
     */
    public static function fromConfig(Config $config, string $stateDirectory): self
    {
        return new self($config->getOperations()->getMetricsStore() === OperationsConfig::METRICS_STORE_OFF
            ? null
            : new FileMetricsStore(rtrim($stateDirectory, '/') . '/metrics'));
    }

    /**
     * Counts a request that was answered.
     *
     * @param string      $method      the request method
     * @param string|null $cacheStatus the X-Cache-Status of the response; null when it has none
     * @param float       $seconds     time the request took
     */
    public function recordRequest(string $method, ?string $cacheStatus, int $status, float $seconds): void
    {
        $method = in_array($method, self::METHODS, true) ? $method : self::OTHER;
        $cache = $cacheStatus !== null && in_array($cacheStatus, self::CACHE_STATUSES, true) ? $cacheStatus : self::NONE;
        $statusLabel = $status >= 100 && $status <= 599 ? (string) $status : self::OTHER;
        $this->increment('requests_total', ['method' => $method, 'cache' => $cache, 'status' => $statusLabel]);
        $this->observe('request_duration_seconds', ['cache' => $cache], $seconds);
    }

    /**
     * Counts an origin fetch.
     *
     * @param string $outcome "success", "not_modified" or an OriginResponse::FAILURE_* reason
     */
    public function recordOriginFetch(string $outcome): void
    {
        $this->increment('origin_fetches_total', ['outcome' => in_array($outcome, self::OUTCOMES, true) ? $outcome : self::OTHER]);
    }

    /**
     * Writes what was recorded since the last flush to the store.
     *
     * @return bool false when the store could not be updated
     */
    public function flush(): bool
    {
        $pending = $this->pending;
        $this->pending = [];
        if ($this->store === null || $pending === []) {
            return true;
        }

        return $this->store->add($pending);
    }

    /**
     * The text exposition of every metric.
     */
    public function render(): string
    {
        $counters = $this->store !== null ? $this->store->read() : [];
        $lines = [];
        self::gauge($lines, 'up', 'Whether the proxy answers (always 1 in a scrape that succeeds).', '1');
        self::gauge($lines, 'metrics_available', 'Whether the counters could be read from METRICS_STORE (0 when they are missing from this scrape).', $counters === null ? '0' : '1');
        if ($counters === null || $this->store === null) {
            return implode("\n", $lines) . "\n";
        }
        ksort($counters, SORT_STRING);
        foreach (self::FAMILIES as $family => list($type, $help)) {
            $name = self::PREFIX . $family;
            $samples = $type === 'counter' ? self::counterSamples($name, $counters) : self::histogramSamples($name, $counters);
            if ($samples === []) {
                continue;
            }
            $lines[] = '# HELP ' . $name . ' ' . $help;
            $lines[] = '# TYPE ' . $name . ' ' . $type;
            foreach ($samples as $sample) {
                $lines[] = $sample;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $lines
     */
    private static function gauge(array &$lines, string $family, string $help, string $value): void
    {
        $lines[] = '# HELP ' . self::PREFIX . $family . ' ' . $help;
        $lines[] = '# TYPE ' . self::PREFIX . $family . ' gauge';
        $lines[] = self::PREFIX . $family . ' ' . $value;
    }

    /**
     * The samples of the counter family $name.
     *
     * @param array<string, int> $counters
     *
     * @return list<string>
     */
    private static function counterSamples(string $name, array $counters): array
    {
        $samples = [];
        foreach ($counters as $key => $value) {
            if ($key === $name || strpos($key, $name . '{') === 0) {
                $samples[] = $key . ' ' . $value;
            }
        }

        return $samples;
    }

    /**
     * The samples of the histogram family $name, for each label set: the
     * buckets in increasing order, then the sum and the count.
     *
     * @param array<string, int> $counters
     *
     * @return list<string>
     */
    private static function histogramSamples(string $name, array $counters): array
    {
        $samples = [];
        foreach ($counters as $key => $count) {
            if (strpos($key, $name . '_count{') !== 0) {
                continue;
            }
            $labels = (string) substr($key, strlen($name . '_count'));
            $open = (string) substr($labels, 0, -1) . ',le="';
            $bounds = [];
            foreach (self::BUCKETS as $bound) {
                $bounds[] = self::bound($bound);
            }
            $bounds[] = '+Inf';
            foreach ($bounds as $bound) {
                $bucket = $name . '_bucket' . $open . $bound . '"}';
                $samples[] = $bucket . ' ' . ($counters[$bucket] ?? 0);
            }
            $samples[] = $name . '_sum' . $labels . ' ' . self::seconds($counters[$name . self::SUM_SUFFIX . $labels] ?? 0);
            $samples[] = $key . ' ' . $count;
        }

        return $samples;
    }

    /**
     * Microseconds as seconds, in the shortest exact decimal form.
     */
    private static function seconds(int $microseconds): string
    {
        $text = sprintf('%d.%06d', intdiv($microseconds, 1000000), $microseconds % 1000000);

        return rtrim(rtrim($text, '0'), '.');
    }

    /**
     * @param array<string, string> $labels
     */
    private function increment(string $family, array $labels, int $amount = 1): void
    {
        $key = self::PREFIX . $family . self::labels($labels);
        $this->pending[$key] = ($this->pending[$key] ?? 0) + $amount;
    }

    /**
     * Counts one observation of a histogram: every bucket whose bound it
     * does not exceed, the +Inf bucket, the count and the sum.
     *
     * @param array<string, string> $labels
     */
    private function observe(string $family, array $labels, float $seconds): void
    {
        $seconds = is_finite($seconds) ? max(0.0, $seconds) : 0.0;
        foreach (self::BUCKETS as $bound) {
            if ($seconds <= $bound) {
                $this->increment($family . '_bucket', $labels + ['le' => self::bound($bound)]);
            }
        }
        $this->increment($family . '_bucket', $labels + ['le' => '+Inf']);
        $this->increment($family . '_count', $labels);
        $this->increment($family . self::SUM_SUFFIX, $labels, (int) round($seconds * 1000000));
    }

    private static function bound(float $bound): string
    {
        return rtrim(rtrim(sprintf('%.4F', $bound), '0'), '.');
    }

    /**
     * The label set as the exposition format writes it, values escaped;
     * "" for none.
     *
     * @param array<string, string> $labels
     */
    private static function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }
        $pairs = [];
        foreach ($labels as $name => $value) {
            $pairs[] = $name . '="' . strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n']) . '"';
        }

        return '{' . implode(',', $pairs) . '}';
    }
}
