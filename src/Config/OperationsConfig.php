<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Config;

use Mfonte\ProxyCdn\Exception\InvalidConfigurationException;
use Mfonte\ProxyCdn\Observability\JsonLogger;
use Mfonte\ProxyCdn\Observability\MetricsAuthenticator;

/**
 * The operations keys of the configuration (PRX-9), validated and
 * normalised apart from the others so that Config, ConfigLoader and
 * ConfigValidator only delegate to this class:
 *
 * - LOG_FILE: where the JSON log lines go: an absolute file path,
 *   "stderr" (the standard error stream, the container log behind PHP-FPM
 *   with catch_workers_output on and decorate_workers_output off) or
 *   "error_log" (PHP's error_log(), the web server's or PHP-FPM's log).
 *   Not set: logs/proxy.log of the project, as before.
 * - LOG_LEVEL: the least severe PSR-3 level that is logged. Not set:
 *   "debug" with DEBUG, "notice" otherwise, as before.
 * - METRICS_KEYS: the keys a Prometheus scraper sends as "Authorization:
 *   Bearer <key>" on GET /metrics, 16 or more characters, or
 *   "sha256:<hex digest>" (MetricsAuthenticator). Not set: /metrics is
 *   not served.
 * - METRICS_STORE: "file" (counters in one locked file of var/metrics,
 *   shared by every PHP process of the host) or "off". Default: file.
 * - MISS_RATE_LIMIT: origin fetches per minute that one client address
 *   may cause (cache misses; hits are never limited), read through
 *   TRUSTED_PROXIES; 0 turns the limit off. Default: 0.
 * - MISS_RATE_LIMIT_BURST: the misses a client may cause at once after a
 *   pause. Default: 60.
 *
 * Immutable.
 */
final class OperationsConfig
{
    /**
     * The keys, in the order of the documentation above.
     */
    public const KEYS = [
        'LOG_FILE',
        'LOG_LEVEL',
        'METRICS_KEYS',
        'METRICS_STORE',
        'MISS_RATE_LIMIT',
        'MISS_RATE_LIMIT_BURST',
    ];

    public const METRICS_STORE_FILE = 'file';

    public const METRICS_STORE_OFF = 'off';

    private const DEFAULTS = [
        'METRICS_STORE' => self::METRICS_STORE_FILE,
        'MISS_RATE_LIMIT' => '0',
        'MISS_RATE_LIMIT_BURST' => '60',
    ];

    /**
     * Inclusive ranges of the integer keys.
     */
    private const INTEGER_RANGES = [
        'MISS_RATE_LIMIT' => [0, 1000000],
        'MISS_RATE_LIMIT_BURST' => [1, 100000],
    ];

    /**
     * @var string|null
     */
    private $logFile;

    /**
     * @var string|null
     */
    private $logLevel;

    /**
     * @var list<string>
     */
    private $metricsKeys;

    /**
     * @var string
     */
    private $metricsStore;

    /**
     * @var int
     */
    private $missRateLimit;

    /**
     * @var int
     */
    private $missRateLimitBurst;

    private function __construct()
    {
    }

    /**
     * The errors and warnings of the raw values (null for a key that is not
     * set), as ConfigValidator reports them.
     *
     * @param array<string, string|null> $values raw values keyed by the names of ConfigLoader::KEYS
     *
     * @return array{list<string>, list<string>} the errors and the warnings
     */
    public static function validate(array $values): array
    {
        $errors = [];
        $warnings = [];

        $logFile = $values['LOG_FILE'] ?? null;
        if ($logFile !== null && !in_array($logFile, [JsonLogger::DESTINATION_STDERR, JsonLogger::DESTINATION_ERROR_LOG], true)
            && ($logFile[0] !== '/' || strpos($logFile, "\0") !== false)) {
            $errors[] = 'LOG_FILE must be "stderr", "error_log" or an absolute path (got: ' . var_export($logFile, true) . ')';
        }
        $logLevel = $values['LOG_LEVEL'] ?? null;
        if ($logLevel !== null && !JsonLogger::isLevel($logLevel)) {
            $errors[] = 'LOG_LEVEL must be one of: debug, info, notice, warning, error, critical, alert, emergency (got: ' . var_export($logLevel, true) . ')';
        }

        // The value holds secrets: the error never quotes it.
        if (MetricsAuthenticator::parseKeys(ConfigValidator::entryList($values['METRICS_KEYS'] ?? null)) === null) {
            $errors[] = 'METRICS_KEYS must list keys separated by commas, each ' . MetricsAuthenticator::MIN_KEY_BYTES
                . ' or more printable ASCII characters without spaces or commas, or "sha256:" and the 64 hexadecimal digits of its SHA-256';
        }
        $store = $values['METRICS_STORE'] ?? null;
        if ($store !== null && !in_array($store, [self::METRICS_STORE_FILE, self::METRICS_STORE_OFF], true)) {
            $errors[] = 'METRICS_STORE must be one of: file, off (got: ' . var_export($store, true) . ')';
        }

        foreach (self::INTEGER_RANGES as $key => $range) {
            $raw = $values[$key] ?? null;
            if ($raw === null) {
                continue;
            }
            if (preg_match('/\A[0-9]{1,10}\z/', $raw) !== 1 || (int) $raw < $range[0] || (int) $raw > $range[1]) {
                $errors[] = sprintf('%s must be an integer between %d and %d (got: %s)', $key, $range[0], $range[1], var_export($raw, true));
            }
        }
        $rateLimit = $values['MISS_RATE_LIMIT'] ?? null;
        if ($rateLimit !== null && preg_match('/\A[1-9][0-9]*\z/', $rateLimit) === 1 && ($values['TRUSTED_PROXIES'] ?? null) === null) {
            $warnings[] = "NOTE: MISS_RATE_LIMIT is on and TRUSTED_PROXIES is empty. Behind a load\n"
                . "balancer or a CDN every client has the address of that proxy and\n"
                . 'shares one limit: list its addresses in TRUSTED_PROXIES.';
        }

        return [$errors, $warnings];
    }

    /**
     * The normalised values of valid raw values, keyed by the names of KEYS,
     * as Config::fromArray() takes them.
     *
     * @param array<string, string|null> $values raw values; they must be valid (validate())
     *
     * @return array<string, int|string|list<string>|null>
     */
    public static function normalise(array $values): array
    {
        $value = static function (string $key) use ($values): ?string {
            return $values[$key] ?? self::DEFAULTS[$key] ?? null;
        };

        return [
            'LOG_FILE' => $value('LOG_FILE'),
            'LOG_LEVEL' => $value('LOG_LEVEL'),
            'METRICS_KEYS' => ConfigValidator::entryList($value('METRICS_KEYS')),
            'METRICS_STORE' => (string) $value('METRICS_STORE'),
            'MISS_RATE_LIMIT' => (int) $value('MISS_RATE_LIMIT'),
            'MISS_RATE_LIMIT_BURST' => (int) $value('MISS_RATE_LIMIT_BURST'),
        ];
    }

    /**
     * Builds the settings from normalised values (normalise(), or the
     * compiled configuration).
     *
     * @param array<mixed> $values
     *
     * @throws InvalidConfigurationException when a key is missing or a value has the wrong type
     */
    public static function fromArray(array $values): self
    {
        $settings = new self();
        $settings->logFile = self::optionalString($values, 'LOG_FILE');
        $settings->logLevel = self::optionalString($values, 'LOG_LEVEL');
        $settings->metricsKeys = self::stringList($values, 'METRICS_KEYS');
        $settings->metricsStore = (string) self::optionalString($values, 'METRICS_STORE');
        $settings->missRateLimit = self::integer($values, 'MISS_RATE_LIMIT');
        $settings->missRateLimitBurst = self::integer($values, 'MISS_RATE_LIMIT_BURST');

        if ($settings->logLevel !== null && !JsonLogger::isLevel($settings->logLevel)) {
            throw new InvalidConfigurationException(['LOG_LEVEL has an unknown value']);
        }
        if (!in_array($settings->metricsStore, [self::METRICS_STORE_FILE, self::METRICS_STORE_OFF], true)) {
            throw new InvalidConfigurationException(['METRICS_STORE has an unknown value']);
        }
        if (MetricsAuthenticator::parseKeys($settings->metricsKeys) === null) {
            throw new InvalidConfigurationException(['METRICS_KEYS must be a list of keys']);
        }
        if ($settings->missRateLimitBurst < 1) {
            throw new InvalidConfigurationException(['MISS_RATE_LIMIT_BURST must be at least 1']);
        }

        return $settings;
    }

    /**
     * The normalised values, keyed by the names of KEYS.
     *
     * @return array<string, int|string|list<string>|null>
     */
    public function toArray(): array
    {
        return [
            'LOG_FILE' => $this->logFile,
            'LOG_LEVEL' => $this->logLevel,
            'METRICS_KEYS' => $this->metricsKeys,
            'METRICS_STORE' => $this->metricsStore,
            'MISS_RATE_LIMIT' => $this->missRateLimit,
            'MISS_RATE_LIMIT_BURST' => $this->missRateLimitBurst,
        ];
    }

    /**
     * LOG_FILE: an absolute path, JsonLogger::DESTINATION_STDERR or
     * JsonLogger::DESTINATION_ERROR_LOG; null for logs/proxy.log of the
     * project.
     */
    public function getLogFile(): ?string
    {
        return $this->logFile;
    }

    /**
     * LOG_LEVEL: a PSR-3 level; null for the default of DEBUG.
     */
    public function getLogLevel(): ?string
    {
        return $this->logLevel;
    }

    /**
     * METRICS_KEYS: the keys (or "sha256:" digests) accepted on /metrics;
     * empty when /metrics is not served.
     *
     * @return list<string>
     */
    public function getMetricsKeys(): array
    {
        return $this->metricsKeys;
    }

    /**
     * METRICS_STORE: METRICS_STORE_FILE or METRICS_STORE_OFF.
     */
    public function getMetricsStore(): string
    {
        return $this->metricsStore;
    }

    /**
     * MISS_RATE_LIMIT: origin fetches per minute and client address; 0
     * when misses are not limited.
     */
    public function getMissRateLimit(): int
    {
        return $this->missRateLimit;
    }

    /**
     * MISS_RATE_LIMIT_BURST: the misses a client may cause at once.
     */
    public function getMissRateLimitBurst(): int
    {
        return $this->missRateLimitBurst;
    }

    /**
     * @param array<mixed> $values
     *
     * @throws InvalidConfigurationException
     */
    private static function optionalString(array $values, string $key): ?string
    {
        if (!array_key_exists($key, $values)) {
            throw new InvalidConfigurationException([$key . ' is missing']);
        }
        $value = $values[$key];
        if ($value !== null && (!is_string($value) || $value === '')) {
            throw new InvalidConfigurationException([$key . ' must be a non-empty string or null']);
        }

        return $value;
    }

    /**
     * @param array<mixed> $values
     *
     * @throws InvalidConfigurationException
     */
    private static function integer(array $values, string $key): int
    {
        $value = $values[$key] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new InvalidConfigurationException([$key . ' must be a non-negative integer']);
        }

        return $value;
    }

    /**
     * @param array<mixed> $values
     *
     * @throws InvalidConfigurationException
     *
     * @return list<string>
     */
    private static function stringList(array $values, string $key): array
    {
        $value = $values[$key] ?? null;
        if (!is_array($value) || array_values($value) !== $value) {
            throw new InvalidConfigurationException([$key . ' must be a list of strings']);
        }
        $list = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new InvalidConfigurationException([$key . ' must be a list of strings']);
            }
            $list[] = $item;
        }

        return $list;
    }
}
