<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Config;

use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Config\ConfigLoader;

/**
 * Valid configurations for the tests, built without reading the environment
 * or a .env file.
 */
final class TestConfig
{
    /**
     * Raw values as ConfigLoader reads them, with $overrides applied; a null
     * override leaves the key unset.
     *
     * @param array<string, string|null> $overrides
     *
     * @return array<string, string|null>
     */
    public static function rawValues(array $overrides = []): array
    {
        return array_merge([
            'DEBUG' => 'false',
            'SOURCE_ORIGIN' => 'https://origin.example.test',
            'SOURCE_ORIGIN_SERVER' => null,
            'CHARSET' => 'UTF-8',
            'TIMEZONE' => 'UTC',
            'LOCALE' => 'en_US',
            'CACHEABLE_EXTENSIONS' => 'css,js,jpg,jpeg,png,gif,bmp,webp,ttf,woff,woff2,otf,eot,svg,ico',
            'CACHE_EXPIRY' => '31536000',
            'NEGATIVE_CACHE_TTL' => '300',
            'NETWORK_ERROR_TTL' => '30',
            'UPSTREAM_TIMEOUT' => '30',
            'UPSTREAM_CONNECT_TIMEOUT' => '5',
            'UPSTREAM_VERIFY_TLS' => 'true',
            'UPSTREAM_FOLLOW_REDIRECTS' => 'false',
            'UPSTREAM_MAX_SIZE' => '67108864',
            'UPSTREAM_USER_AGENT' => null,
            'QUERY_STRING_ALLOWLIST' => null,
            'QUERY_STRING_MODE' => null,
            'TRUSTED_PROXIES' => null,
            'PURGE_KEYS' => null,
            'IMAGE_CONVERSION_MODE' => 'none',
            'WEBP_API_SERVER' => null,
            'WEBP_API_KEY' => null,
        ], $overrides);
    }

    /**
     * A validated configuration made of rawValues($overrides).
     *
     * @param array<string, string|null> $overrides
     */
    public static function create(array $overrides = []): Config
    {
        return self::loader()->loadFromArray(self::rawValues($overrides));
    }

    /**
     * A loader that sees an empty environment and no .env file.
     */
    public static function loader(): ConfigLoader
    {
        return new ConfigLoader(null, null, static function (string $name): ?string {
            return null;
        });
    }
}
