<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Config;

use Dotenv\Dotenv;
use Mfonte\ProxyCdn\Cache\QueryStringPolicy;
use Mfonte\ProxyCdn\Exception\InvalidConfigurationException;

/**
 * Loads the configuration, environment first (plan section 4):
 *
 * 1. a variable of the environment that is set and not empty wins;
 * 2. otherwise the value of the optional .env file is used;
 * 3. the empty string and the literal "null" mean "not set", and the
 *    default of the key applies.
 *
 * The .env file is parsed with phpdotenv into an array: the process
 * environment, $_ENV and $_SERVER are never modified. The values are
 * validated with ConfigValidator before a Config is built. With a
 * CompiledConfigCache the parsing and validation run only when the sources
 * change: the fingerprint of the sources (the environment variables and the
 * contents of the .env file) is compared on every load.
 */
final class ConfigLoader
{
    /**
     * The configuration keys: those of 2.x, plus UPSTREAM_MAX_SIZE and
     * UPSTREAM_USER_AGENT (PRX-4), QUERY_STRING_ALLOWLIST,
     * QUERY_STRING_MODE, TRUSTED_PROXIES, PURGE_KEYS and
     * CIRCUIT_BREAKER_THRESHOLD (PRX-5), PROXY_HOSTS, SENDFILE, PRECOMPRESS
     * and LEGACY_WEBP_URLS (PRX-6), CACHE_REVALIDATE_BELOW and
     * STALE_WHILE_REVALIDATE (PRX-7).
     */
    public const KEYS = [
        'DEBUG',
        'SOURCE_ORIGIN',
        'SOURCE_ORIGIN_SERVER',
        'PROXY_HOSTS',
        'CHARSET',
        'TIMEZONE',
        'LOCALE',
        'CACHEABLE_EXTENSIONS',
        'CACHE_EXPIRY',
        'CACHE_REVALIDATE_BELOW',
        'STALE_WHILE_REVALIDATE',
        'NEGATIVE_CACHE_TTL',
        'NETWORK_ERROR_TTL',
        'CIRCUIT_BREAKER_THRESHOLD',
        'UPSTREAM_TIMEOUT',
        'UPSTREAM_CONNECT_TIMEOUT',
        'UPSTREAM_VERIFY_TLS',
        'UPSTREAM_FOLLOW_REDIRECTS',
        'UPSTREAM_MAX_SIZE',
        'UPSTREAM_USER_AGENT',
        'QUERY_STRING_ALLOWLIST',
        'QUERY_STRING_MODE',
        'TRUSTED_PROXIES',
        'PURGE_KEYS',
        'IMAGE_CONVERSION_MODE',
        'WEBP_API_SERVER',
        'WEBP_API_KEY',
        'SENDFILE',
        'PRECOMPRESS',
        'LEGACY_WEBP_URLS',
    ];

    /**
     * Defaults of the keys that may be left unset, as in 2.x except
     * UPSTREAM_VERIFY_TLS, true since 3.0 (risk L-6). The default of
     * UPSTREAM_MAX_SIZE is HttpClientOptions::DEFAULT_MAX_BODY_SIZE.
     */
    private const DEFAULTS = [
        'DEBUG' => 'false',
        'CHARSET' => 'UTF-8',
        'TIMEZONE' => 'UTC',
        'LOCALE' => 'en_US',
        'CACHE_EXPIRY' => '86400',
        'CACHE_REVALIDATE_BELOW' => '3600',
        'STALE_WHILE_REVALIDATE' => '60',
        'NEGATIVE_CACHE_TTL' => '300',
        'NETWORK_ERROR_TTL' => '30',
        'CIRCUIT_BREAKER_THRESHOLD' => '5',
        'UPSTREAM_TIMEOUT' => '30',
        'UPSTREAM_CONNECT_TIMEOUT' => '5',
        'UPSTREAM_VERIFY_TLS' => 'true',
        'UPSTREAM_FOLLOW_REDIRECTS' => 'false',
        'UPSTREAM_MAX_SIZE' => '67108864',
        'QUERY_STRING_ALLOWLIST' => 'v,ver,version,id',
        'QUERY_STRING_MODE' => QueryStringPolicy::MODE_IGNORE,
        'IMAGE_CONVERSION_MODE' => Config::CONVERSION_NONE,
        'SENDFILE' => Config::SENDFILE_NONE,
        'PRECOMPRESS' => 'true',
        'LEGACY_WEBP_URLS' => 'true',
    ];

    /**
     * Changes whenever the compiled format or the normalisation changes, so
     * that files compiled by another version are recompiled.
     */
    private const FORMAT = 'proxy-cdn-config-5';

    /**
     * @var string|null
     */
    private $envFile;

    /**
     * @var CompiledConfigCache|null
     */
    private $compiledCache;

    /**
     * @var callable(string): ?string
     */
    private $environment;

    /**
     * @var ConfigValidator
     */
    private $validator;

    /**
     * @param string|null                      $envFile       path of the optional .env file; null to read the environment only
     * @param CompiledConfigCache|null         $compiledCache null to parse and validate on every load
     * @param (callable(string): ?string)|null $environment   reads one environment variable and returns null when it is
     *                                                        not set; getenv() by default, which also sees the
     *                                                        variables PHP-FPM and Apache pass to the request
     */
    public function __construct(
        ?string $envFile,
        ?CompiledConfigCache $compiledCache = null,
        ?callable $environment = null,
        ?ConfigValidator $validator = null
    ) {
        $this->envFile = $envFile;
        $this->compiledCache = $compiledCache;
        $this->environment = $environment ?? static function (string $name): ?string {
            $value = getenv($name);

            return is_string($value) ? $value : null;
        };
        $this->validator = $validator ?? new ConfigValidator();
    }

    /**
     * Loads and validates the configuration.
     *
     * @throws InvalidConfigurationException when .env cannot be parsed or a value is invalid
     */
    public function load(): Config
    {
        if ($this->compiledCache === null) {
            return $this->loadFromArray($this->readValues());
        }

        $fingerprint = $this->fingerprint();
        $config = $this->compiledCache->load($fingerprint);
        if ($config === null) {
            $config = $this->loadFromArray($this->readValues());
            $this->compiledCache->save($fingerprint, $config);
        }

        return $config;
    }

    /**
     * Validates raw values and builds the configuration from them, without
     * reading the environment or .env: the entry point for a host
     * application that holds the values itself.
     *
     * @param array<string, string|null> $values raw values keyed by the names of KEYS; missing keys are not set
     *
     * @throws InvalidConfigurationException when a value is invalid
     */
    public function loadFromArray(array $values): Config
    {
        $raw = [];
        foreach (self::KEYS as $key) {
            $raw[$key] = self::normalise($values[$key] ?? null);
        }

        $validation = $this->validator->validate($raw);
        if (!$validation->isValid()) {
            throw new InvalidConfigurationException($validation->getErrors());
        }

        $value = static function (string $key) use ($raw): string {
            return $raw[$key] ?? self::DEFAULTS[$key] ?? '';
        };
        $optional = static function (string $key) use ($raw): ?string {
            return $raw[$key] ?? null;
        };

        return Config::fromArray([
            'DEBUG' => ConfigValidator::booleanOf($value('DEBUG')) === true,
            'SOURCE_ORIGIN' => rtrim($value('SOURCE_ORIGIN'), '/'),
            'SOURCE_ORIGIN_SERVER' => $optional('SOURCE_ORIGIN_SERVER'),
            'PROXY_HOSTS' => ConfigValidator::hostEntries($optional('PROXY_HOSTS'), rtrim($value('SOURCE_ORIGIN'), '/')),
            'SENDFILE' => $value('SENDFILE'),
            'PRECOMPRESS' => ConfigValidator::booleanOf($value('PRECOMPRESS')) === true,
            'LEGACY_WEBP_URLS' => ConfigValidator::booleanOf($value('LEGACY_WEBP_URLS')) === true,
            'CHARSET' => $value('CHARSET'),
            'TIMEZONE' => $value('TIMEZONE'),
            'LOCALE' => $value('LOCALE'),
            'CACHEABLE_EXTENSIONS' => ConfigValidator::extensionList($optional('CACHEABLE_EXTENSIONS')),
            'CACHE_EXPIRY' => (int) $value('CACHE_EXPIRY'),
            'CACHE_REVALIDATE_BELOW' => (int) $value('CACHE_REVALIDATE_BELOW'),
            'STALE_WHILE_REVALIDATE' => (int) $value('STALE_WHILE_REVALIDATE'),
            'NEGATIVE_CACHE_TTL' => (int) $value('NEGATIVE_CACHE_TTL'),
            'NETWORK_ERROR_TTL' => (int) $value('NETWORK_ERROR_TTL'),
            'CIRCUIT_BREAKER_THRESHOLD' => (int) $value('CIRCUIT_BREAKER_THRESHOLD'),
            'UPSTREAM_TIMEOUT' => (int) $value('UPSTREAM_TIMEOUT'),
            'UPSTREAM_CONNECT_TIMEOUT' => (int) $value('UPSTREAM_CONNECT_TIMEOUT'),
            'UPSTREAM_VERIFY_TLS' => ConfigValidator::booleanOf($value('UPSTREAM_VERIFY_TLS')) === true,
            'UPSTREAM_FOLLOW_REDIRECTS' => ConfigValidator::booleanOf($value('UPSTREAM_FOLLOW_REDIRECTS')) === true,
            'UPSTREAM_MAX_SIZE' => (int) $value('UPSTREAM_MAX_SIZE'),
            'UPSTREAM_USER_AGENT' => $optional('UPSTREAM_USER_AGENT'),
            'QUERY_STRING_ALLOWLIST' => ConfigValidator::parameterList($value('QUERY_STRING_ALLOWLIST')),
            'QUERY_STRING_MODE' => $value('QUERY_STRING_MODE'),
            'TRUSTED_PROXIES' => ConfigValidator::rangeList($optional('TRUSTED_PROXIES')),
            'PURGE_KEYS' => ConfigValidator::entryList($optional('PURGE_KEYS')),
            'IMAGE_CONVERSION_MODE' => $value('IMAGE_CONVERSION_MODE'),
            'WEBP_API_SERVER' => $optional('WEBP_API_SERVER'),
            'WEBP_API_KEY' => $optional('WEBP_API_KEY'),
        ]);
    }

    /**
     * The raw values of every key, environment first, then .env; null for a
     * key that is not set.
     *
     * @throws InvalidConfigurationException when .env exists but cannot be parsed
     *
     * @return array<string, string|null>
     */
    public function readValues(): array
    {
        $fromFile = $this->readEnvFile();
        $fromEnvironment = $this->readEnvironmentValues();

        $values = [];
        foreach (self::KEYS as $key) {
            $values[$key] = self::normalise($fromEnvironment[$key] ?? $fromFile[$key] ?? null);
        }

        return $values;
    }

    /**
     * The keys set to a non-empty value in the environment.
     *
     * @return array<string, string>
     */
    public function readEnvironmentValues(): array
    {
        $values = [];
        foreach (self::KEYS as $key) {
            $value = ($this->environment)($key);
            if ($value !== null && $value !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * Whether a .env file is configured and exists.
     */
    public function hasEnvFile(): bool
    {
        return $this->envFile !== null && is_file($this->envFile);
    }

    /**
     * The empty string and the literal "null" mean "not set".
     */
    private static function normalise(?string $value): ?string
    {
        return $value === null || $value === '' || $value === 'null' ? null : $value;
    }

    /**
     * @throws InvalidConfigurationException
     *
     * @return array<string, string|null>
     */
    private function readEnvFile(): array
    {
        if ($this->envFile === null || !is_file($this->envFile)) {
            return [];
        }
        $contents = @file_get_contents($this->envFile);
        if ($contents === false) {
            throw new InvalidConfigurationException(['The .env file cannot be read']);
        }

        try {
            $parsed = Dotenv::parse($contents);
        } catch (\Exception $exception) {
            throw new InvalidConfigurationException(['The .env file cannot be parsed: ' . $exception->getMessage()]);
        }

        $values = [];
        foreach ($parsed as $name => $value) {
            $values[(string) $name] = $value;
        }

        return $values;
    }

    /**
     * Fingerprint of the sources: the environment values of every key and
     * the contents of .env. Hashing the contents, rather than comparing
     * modification times, also notices an edit made within the same second
     * that keeps the size.
     */
    private function fingerprint(): string
    {
        $parts = [self::FORMAT];
        foreach (self::KEYS as $key) {
            $value = ($this->environment)($key);
            $parts[] = $key . ($value === null ? "\0" : '=' . $value);
        }

        $parts[] = (string) $this->envFile;
        if ($this->envFile !== null) {
            $contents = is_file($this->envFile) ? @file_get_contents($this->envFile) : false;
            $parts[] = $contents === false ? 'absent' : hash('sha256', $contents);
        }

        return hash('sha256', implode("\n", $parts));
    }
}
