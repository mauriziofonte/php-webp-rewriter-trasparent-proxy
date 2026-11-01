<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Config;

use Mfonte\ProxyCdn\Cache\QueryStringPolicy;
use Mfonte\ProxyCdn\Exception\InvalidArgumentException;
use Mfonte\ProxyCdn\Exception\InvalidConfigurationException;
use Mfonte\ProxyCdn\Net\IpRange;
use Mfonte\ProxyCdn\Security\HostAllowlist;

/**
 * The proxy configuration, immutable and already validated.
 *
 * The keys are those of 2.x (see ConfigLoader::KEYS); later tasks rename
 * them with UPGRADING notes. Build it with ConfigLoader, which reads the
 * environment and the optional .env file and validates the values first.
 * fromArray() and toArray() exchange the normalised values, keyed by the
 * same names, with the compiled configuration cache.
 */
final class Config
{
    public const CONVERSION_NONE = 'none';
    public const CONVERSION_NATIVE = 'native';
    public const CONVERSION_API = 'api';

    /** SENDFILE: PHP streams the files it serves itself. */
    public const SENDFILE_NONE = 'none';

    /** SENDFILE: PHP hands the files it serves to Apache mod_xsendfile. */
    public const SENDFILE_X_SENDFILE = 'x-sendfile';

    /** SENDFILE: PHP hands the files it serves to nginx. */
    public const SENDFILE_X_ACCEL_REDIRECT = 'x-accel-redirect';

    public const SENDFILE_MODES = [self::SENDFILE_NONE, self::SENDFILE_X_SENDFILE, self::SENDFILE_X_ACCEL_REDIRECT];

    /**
     * @var bool
     */
    private $debug;

    /**
     * @var string
     */
    private $sourceOrigin;

    /**
     * @var string|null
     */
    private $sourceOriginServer;

    /**
     * @var list<string> "host=origin" entries, normalised
     */
    private $proxyHosts;

    /**
     * @var string
     */
    private $sendfile;

    /**
     * @var bool
     */
    private $precompress;

    /**
     * @var bool
     */
    private $legacyWebpUrls;

    /**
     * @var string
     */
    private $charset;

    /**
     * @var string|null
     */
    private $timezone;

    /**
     * @var string
     */
    private $locale;

    /**
     * @var list<string>
     */
    private $cacheableExtensions;

    /**
     * @var int
     */
    private $cacheExpiry;

    /**
     * @var int
     */
    private $negativeCacheTtl;

    /**
     * @var int
     */
    private $circuitBreakerThreshold;

    /**
     * @var int
     */
    private $networkErrorTtl;

    /**
     * @var int
     */
    private $upstreamTimeout;

    /**
     * @var int
     */
    private $upstreamConnectTimeout;

    /**
     * @var bool
     */
    private $upstreamVerifyTls;

    /**
     * @var bool
     */
    private $upstreamFollowRedirects;

    /**
     * @var int
     */
    private $upstreamMaxSize;

    /**
     * @var string|null
     */
    private $upstreamUserAgent;

    /**
     * @var list<string>
     */
    private $queryStringAllowlist;

    /**
     * @var string
     */
    private $queryStringMode;

    /**
     * @var list<IpRange>
     */
    private $trustedProxies;

    /**
     * @var list<string>
     */
    private $purgeKeys;

    /**
     * @var string
     */
    private $imageConversionMode;

    /**
     * @var string|null
     */
    private $webpApiServer;

    /**
     * @var string|null
     */
    private $webpApiKey;

    private function __construct()
    {
    }

    /**
     * Builds the configuration from normalised values, as returned by
     * toArray(): every key of ConfigLoader::KEYS with a value of its type.
     *
     * @param array<mixed> $values
     *
     * @throws InvalidConfigurationException when a key is missing or a value has the wrong type
     */
    public static function fromArray(array $values): self
    {
        $config = new self();
        $config->debug = self::boolean($values, 'DEBUG');
        $config->sourceOrigin = self::string($values, 'SOURCE_ORIGIN');
        $config->sourceOriginServer = self::optionalString($values, 'SOURCE_ORIGIN_SERVER');
        $config->proxyHosts = self::stringList($values, 'PROXY_HOSTS');
        $config->sendfile = self::string($values, 'SENDFILE');
        $config->precompress = self::boolean($values, 'PRECOMPRESS');
        $config->legacyWebpUrls = self::boolean($values, 'LEGACY_WEBP_URLS');
        $config->charset = self::string($values, 'CHARSET');
        $config->timezone = self::optionalString($values, 'TIMEZONE');
        $config->locale = self::string($values, 'LOCALE');
        $config->cacheableExtensions = self::stringList($values, 'CACHEABLE_EXTENSIONS');
        $config->cacheExpiry = self::integer($values, 'CACHE_EXPIRY');
        $config->negativeCacheTtl = self::integer($values, 'NEGATIVE_CACHE_TTL');
        $config->networkErrorTtl = self::integer($values, 'NETWORK_ERROR_TTL');
        $config->circuitBreakerThreshold = self::integer($values, 'CIRCUIT_BREAKER_THRESHOLD');
        $config->upstreamTimeout = self::integer($values, 'UPSTREAM_TIMEOUT');
        $config->upstreamConnectTimeout = self::integer($values, 'UPSTREAM_CONNECT_TIMEOUT');
        $config->upstreamVerifyTls = self::boolean($values, 'UPSTREAM_VERIFY_TLS');
        $config->upstreamFollowRedirects = self::boolean($values, 'UPSTREAM_FOLLOW_REDIRECTS');
        $config->upstreamMaxSize = self::integer($values, 'UPSTREAM_MAX_SIZE');
        $config->upstreamUserAgent = self::optionalString($values, 'UPSTREAM_USER_AGENT');
        $config->queryStringAllowlist = self::stringList($values, 'QUERY_STRING_ALLOWLIST', true);
        $config->queryStringMode = self::string($values, 'QUERY_STRING_MODE');
        $config->trustedProxies = self::rangeList($values, 'TRUSTED_PROXIES');
        $config->purgeKeys = self::stringList($values, 'PURGE_KEYS', true);
        $config->imageConversionMode = self::string($values, 'IMAGE_CONVERSION_MODE');
        $config->webpApiServer = self::optionalString($values, 'WEBP_API_SERVER');
        $config->webpApiKey = self::optionalString($values, 'WEBP_API_KEY');

        if (!in_array($config->imageConversionMode, [self::CONVERSION_NONE, self::CONVERSION_NATIVE, self::CONVERSION_API], true)) {
            throw new InvalidConfigurationException(['IMAGE_CONVERSION_MODE has an unknown value']);
        }
        if (!in_array($config->sendfile, self::SENDFILE_MODES, true)) {
            throw new InvalidConfigurationException(['SENDFILE has an unknown value']);
        }
        if (HostAllowlist::parse($config->proxyHosts, $config->sourceOrigin) === null) {
            throw self::wrongType('PROXY_HOSTS', 'a list of "host=origin" entries');
        }
        if (!in_array($config->queryStringMode, [QueryStringPolicy::MODE_IGNORE, QueryStringPolicy::MODE_REJECT], true)) {
            throw new InvalidConfigurationException(['QUERY_STRING_MODE has an unknown value']);
        }

        return $config;
    }

    /**
     * The normalised values, keyed by the names of ConfigLoader::KEYS.
     *
     * @return array<string, bool|int|string|list<string>|null>
     */
    public function toArray(): array
    {
        return [
            'DEBUG' => $this->debug,
            'SOURCE_ORIGIN' => $this->sourceOrigin,
            'SOURCE_ORIGIN_SERVER' => $this->sourceOriginServer,
            'PROXY_HOSTS' => $this->proxyHosts,
            'SENDFILE' => $this->sendfile,
            'PRECOMPRESS' => $this->precompress,
            'LEGACY_WEBP_URLS' => $this->legacyWebpUrls,
            'CHARSET' => $this->charset,
            'TIMEZONE' => $this->timezone,
            'LOCALE' => $this->locale,
            'CACHEABLE_EXTENSIONS' => $this->cacheableExtensions,
            'CACHE_EXPIRY' => $this->cacheExpiry,
            'NEGATIVE_CACHE_TTL' => $this->negativeCacheTtl,
            'NETWORK_ERROR_TTL' => $this->networkErrorTtl,
            'CIRCUIT_BREAKER_THRESHOLD' => $this->circuitBreakerThreshold,
            'UPSTREAM_TIMEOUT' => $this->upstreamTimeout,
            'UPSTREAM_CONNECT_TIMEOUT' => $this->upstreamConnectTimeout,
            'UPSTREAM_VERIFY_TLS' => $this->upstreamVerifyTls,
            'UPSTREAM_FOLLOW_REDIRECTS' => $this->upstreamFollowRedirects,
            'UPSTREAM_MAX_SIZE' => $this->upstreamMaxSize,
            'UPSTREAM_USER_AGENT' => $this->upstreamUserAgent,
            'QUERY_STRING_ALLOWLIST' => $this->queryStringAllowlist,
            'QUERY_STRING_MODE' => $this->queryStringMode,
            'TRUSTED_PROXIES' => array_map('strval', $this->trustedProxies),
            'PURGE_KEYS' => $this->purgeKeys,
            'IMAGE_CONVERSION_MODE' => $this->imageConversionMode,
            'WEBP_API_SERVER' => $this->webpApiServer,
            'WEBP_API_KEY' => $this->webpApiKey,
        ];
    }

    /**
     * DEBUG: informational log entries and displayed PHP errors.
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * SOURCE_ORIGIN without trailing slash, e.g. "https://www.example.com".
     */
    public function getSourceOrigin(): string
    {
        return $this->sourceOrigin;
    }

    /**
     * SOURCE_ORIGIN_SERVER: "address" or "address:port" every origin
     * connection is pinned to, bypassing DNS (an IPv6 address in brackets);
     * null when connections follow DNS.
     */
    public function getSourceOriginServer(): ?string
    {
        return $this->sourceOriginServer;
    }

    /**
     * PROXY_HOSTS: the host names the proxy answers for, each with its
     * origin (see HostAllowlist).
     */
    public function getHostAllowlist(): HostAllowlist
    {
        return new HostAllowlist(HostAllowlist::parse($this->proxyHosts, $this->sourceOrigin) ?? []);
    }

    /**
     * SENDFILE: how PHP sends the cache files it serves, one of the
     * SENDFILE_* constants.
     */
    public function getSendfile(): string
    {
        return $this->sendfile;
    }

    /**
     * PRECOMPRESS: whether ".br" and ".gz" siblings are written next to the
     * compressible entries, for the web server to serve.
     */
    public function shouldPrecompress(): bool
    {
        return $this->precompress;
    }

    /**
     * LEGACY_WEBP_URLS: whether a request for "X.webp" that the origin does
     * not have is answered from a raster sibling "X.jpg", "X.png"... (risk
     * L-4: 2.x redirected raster URLs to such WEBP URLs, permanently).
     */
    public function shouldResolveLegacyWebpUrls(): bool
    {
        return $this->legacyWebpUrls;
    }

    /**
     * CHARSET: PHP's default_charset.
     */
    public function getCharset(): string
    {
        return $this->charset;
    }

    /**
     * TIMEZONE: PHP's default time zone; null keeps the one of php.ini.
     */
    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    /**
     * LOCALE: the LC_TIME and LC_MONETARY locale.
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * CACHEABLE_EXTENSIONS: lower-case extensions the proxy serves, without dot.
     *
     * @return list<string>
     */
    public function getCacheableExtensions(): array
    {
        return $this->cacheableExtensions;
    }

    /**
     * @param string $extension lower-case extension without dot
     */
    public function isCacheableExtension(string $extension): bool
    {
        return in_array($extension, $this->cacheableExtensions, true);
    }

    /**
     * CACHE_EXPIRY: max-age, in seconds, of the responses.
     */
    public function getCacheExpiry(): int
    {
        return $this->cacheExpiry;
    }

    /**
     * NEGATIVE_CACHE_TTL: seconds an origin 404 is remembered.
     */
    public function getNegativeCacheTtl(): int
    {
        return $this->negativeCacheTtl;
    }

    /**
     * CIRCUIT_BREAKER_THRESHOLD: consecutive unavailability failures of the
     * origin that open its circuit breaker.
     */
    public function getCircuitBreakerThreshold(): int
    {
        return $this->circuitBreakerThreshold;
    }

    /**
     * NETWORK_ERROR_TTL: seconds an origin failure keeps the circuit breaker open.
     */
    public function getNetworkErrorTtl(): int
    {
        return $this->networkErrorTtl;
    }

    /**
     * UPSTREAM_TIMEOUT: seconds an origin fetch may take in total, apart
     * from a host name lookup that stalls in the system resolver.
     */
    public function getUpstreamTimeout(): int
    {
        return $this->upstreamTimeout;
    }

    /**
     * UPSTREAM_CONNECT_TIMEOUT: seconds the connection to the origin may take.
     */
    public function getUpstreamConnectTimeout(): int
    {
        return $this->upstreamConnectTimeout;
    }

    /**
     * UPSTREAM_VERIFY_TLS: whether the origin certificate is verified; true
     * unless it is set to false (2.x did not verify by default).
     */
    public function shouldVerifyUpstreamTls(): bool
    {
        return $this->upstreamVerifyTls;
    }

    /**
     * UPSTREAM_FOLLOW_REDIRECTS: whether origin redirects are followed.
     */
    public function shouldFollowUpstreamRedirects(): bool
    {
        return $this->upstreamFollowRedirects;
    }

    /**
     * UPSTREAM_MAX_SIZE: bytes an origin body may have once decoded; a
     * larger one is not fetched (finding P-17). At most
     * ConfigValidator::MAX_UPSTREAM_MAX_SIZE.
     */
    public function getUpstreamMaxSize(): int
    {
        return $this->upstreamMaxSize;
    }

    /**
     * UPSTREAM_USER_AGENT: the User-Agent of origin requests; null for the
     * default, which names this software (finding P-22).
     */
    public function getUpstreamUserAgent(): ?string
    {
        return $this->upstreamUserAgent;
    }

    /**
     * QUERY_STRING_ALLOWLIST: the decoded names of the query parameters that
     * key the cache and reach the origin; ["*"] for every parameter, [] for
     * none (see QueryStringPolicy).
     *
     * @return list<string>
     */
    public function getQueryStringAllowlist(): array
    {
        return $this->queryStringAllowlist;
    }

    /**
     * QUERY_STRING_MODE: what happens to the other parameters, one of the
     * QueryStringPolicy::MODE_* constants.
     */
    public function getQueryStringMode(): string
    {
        return $this->queryStringMode;
    }

    /**
     * TRUSTED_PROXIES: the reverse proxies whose X-Forwarded-Proto and
     * X-Forwarded-For are believed (see TrustedProxies); none by default.
     *
     * @return list<IpRange>
     */
    public function getTrustedProxies(): array
    {
        return $this->trustedProxies;
    }

    /**
     * PURGE_KEYS: the "scope=secret" entries that authenticate a PURGE
     * (see PurgeAuthenticator); none disables purging.
     *
     * @return list<string>
     */
    public function getPurgeKeys(): array
    {
        return $this->purgeKeys;
    }

    /**
     * IMAGE_CONVERSION_MODE: one of the CONVERSION_* constants.
     */
    public function getImageConversionMode(): string
    {
        return $this->imageConversionMode;
    }

    /**
     * WEBP_API_SERVER: URL of the 1.x conversion API, used in "api" mode.
     */
    public function getWebpApiServer(): ?string
    {
        return $this->webpApiServer;
    }

    /**
     * WEBP_API_KEY: key of the 1.x conversion API, used in "api" mode.
     */
    public function getWebpApiKey(): ?string
    {
        return $this->webpApiKey;
    }

    /**
     * @param array<mixed> $values
     *
     * @throws InvalidConfigurationException
     */
    private static function boolean(array $values, string $key): bool
    {
        $value = self::value($values, $key);
        if (!is_bool($value)) {
            throw self::wrongType($key, 'a boolean');
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
        $value = self::value($values, $key);
        if (!is_int($value) || $value < 0) {
            throw self::wrongType($key, 'a non-negative integer');
        }

        return $value;
    }

    /**
     * @param array<mixed> $values
     *
     * @throws InvalidConfigurationException
     */
    private static function string(array $values, string $key): string
    {
        $value = self::value($values, $key);
        if (!is_string($value) || $value === '') {
            throw self::wrongType($key, 'a non-empty string');
        }

        return $value;
    }

    /**
     * @param array<mixed> $values
     *
     * @throws InvalidConfigurationException
     */
    private static function optionalString(array $values, string $key): ?string
    {
        $value = self::value($values, $key);
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw self::wrongType($key, 'a non-empty string or null');
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
    private static function stringList(array $values, string $key, bool $mayBeEmpty = false): array
    {
        $expected = $mayBeEmpty ? 'a list of non-empty strings' : 'a non-empty list of strings';
        $value = self::value($values, $key);
        if (!is_array($value) || ($value === [] && !$mayBeEmpty) || array_values($value) !== $value) {
            throw self::wrongType($key, $expected);
        }
        $list = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw self::wrongType($key, $expected);
            }
            $list[] = $item;
        }

        return $list;
    }

    /**
     * @param array<mixed> $values
     *
     * @throws InvalidConfigurationException
     *
     * @return list<IpRange>
     */
    private static function rangeList(array $values, string $key): array
    {
        $ranges = [];
        foreach (self::stringList($values, $key, true) as $range) {
            try {
                $ranges[] = IpRange::fromString($range);
            } catch (InvalidArgumentException $exception) {
                throw self::wrongType($key, 'a list of IP addresses and CIDR blocks');
            }
        }

        return $ranges;
    }

    /**
     * @param array<mixed> $values
     *
     * @throws InvalidConfigurationException
     *
     * @return mixed
     */
    private static function value(array $values, string $key)
    {
        if (!array_key_exists($key, $values)) {
            throw new InvalidConfigurationException([$key . ' is missing']);
        }

        return $values[$key];
    }

    private static function wrongType(string $key, string $expected): InvalidConfigurationException
    {
        return new InvalidConfigurationException([$key . ' must be ' . $expected]);
    }
}
