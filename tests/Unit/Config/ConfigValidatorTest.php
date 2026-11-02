<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Config;

use Mfonte\ProxyCdn\Config\ConfigValidator;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The messages are those of the 2.x check-env script, word for word.
 *
 * @covers \Mfonte\ProxyCdn\Config\ConfigValidator
 * @covers \Mfonte\ProxyCdn\Config\ConfigValidation
 */
#[CoversClass(ConfigValidator::class)]
#[CoversClass(\Mfonte\ProxyCdn\Config\ConfigValidation::class)]
final class ConfigValidatorTest extends TestCase
{
    private const HOSTS_REQUIRED = 'PROXY_HOSTS must be set: the host names the proxy answers for, separated by commas '
        . '("cdn.example.com", or "cdn.example.com=https://origin.example.com" for another origin than SOURCE_ORIGIN)';

    public function testValidValuesHaveNoErrorsAndNoWarnings(): void
    {
        $validation = (new ConfigValidator())->validate(TestConfig::rawValues());

        self::assertTrue($validation->isValid());
        self::assertSame([], $validation->getErrors());
        self::assertSame([], $validation->getWarnings());
    }

    public function testOnlyOriginHostsAndExtensionsAreRequired(): void
    {
        $values = array_fill_keys(array_keys(TestConfig::rawValues()), null);
        $values['SOURCE_ORIGIN'] = 'http://origin.example.test';
        $values['PROXY_HOSTS'] = 'cdn.example.test';
        $values['CACHEABLE_EXTENSIONS'] = 'css';

        $validation = (new ConfigValidator())->validate($values);

        self::assertSame([], $validation->getErrors());
    }

    /**
     * @dataProvider provideInvalidValues
     *
     * @param array<string, string|null> $overrides
     */
    #[DataProvider('provideInvalidValues')]
    public function testInvalidValueIsReported(array $overrides, string $error): void
    {
        $validation = (new ConfigValidator())->validate(TestConfig::rawValues($overrides));

        self::assertFalse($validation->isValid());
        self::assertSame([$error], $validation->getErrors());
    }

    /**
     * @return array<string, array{array<string, string|null>, string}>
     */
    public static function provideInvalidValues(): array
    {
        return [
            'origin missing' => [['SOURCE_ORIGIN' => null], 'SOURCE_ORIGIN must be set'],
            'origin not a URL' => [['SOURCE_ORIGIN' => 'origin'], "SOURCE_ORIGIN must be a valid URL (got: 'origin')"],
            'origin with another scheme' => [
                ['SOURCE_ORIGIN' => 'ftp://origin.example.test'],
                "SOURCE_ORIGIN must use http:// or https:// scheme (got: 'ftp://origin.example.test')",
            ],
            'extensions missing' => [
                ['CACHEABLE_EXTENSIONS' => null],
                'CACHEABLE_EXTENSIONS must be set (comma-separated list of file extensions)',
            ],
            'extensions empty' => [
                ['CACHEABLE_EXTENSIONS' => ' , ,'],
                'CACHEABLE_EXTENSIONS must be set (comma-separated list of file extensions)',
            ],
            'unknown mode' => [
                ['IMAGE_CONVERSION_MODE' => 'gd'],
                "IMAGE_CONVERSION_MODE must be one of: native, api, none (got: 'gd')",
            ],
            'api without server' => [
                ['IMAGE_CONVERSION_MODE' => 'api', 'WEBP_API_KEY' => 'key'],
                'WEBP_API_SERVER must be set for API mode',
            ],
            'unknown converter' => [
                ['IMAGE_CONVERTER' => 'gd'],
                "IMAGE_CONVERTER must be one of: none, local, remote (got: 'gd')",
            ],
            'converter in upper case' => [
                ['IMAGE_CONVERTER' => 'LOCAL'],
                "IMAGE_CONVERTER must be one of: none, local, remote (got: 'LOCAL')",
            ],
            'unknown format' => [
                ['IMAGE_FORMATS' => 'avif,jxl'],
                "IMAGE_FORMATS must list avif and/or webp, separated by commas, each once (got: 'avif,jxl')",
            ],
            'no format' => [
                ['IMAGE_FORMATS' => ' , '],
                "IMAGE_FORMATS must list avif and/or webp, separated by commas, each once (got: ' , ')",
            ],
            'conversion timeout above range' => [
                ['IMAGE_CONVERSION_TIMEOUT' => '601'],
                'IMAGE_CONVERSION_TIMEOUT must be between 1 and 600 (got: 601)',
            ],
            'remote without URL' => [
                ['IMAGE_CONVERTER' => 'remote', 'IMAGE_API_KEY' => '0123456789abcdef'],
                'IMAGE_API_URL must be set for IMAGE_CONVERTER "remote"',
            ],
            'remote without key' => [
                ['IMAGE_CONVERTER' => 'remote', 'IMAGE_API_URL' => 'https://api.example.test'],
                'IMAGE_API_KEY must be set for IMAGE_CONVERTER "remote"',
            ],
            'API URL with credentials' => [
                ['IMAGE_API_URL' => 'https://user:pass@api.example.test'],
                "IMAGE_API_URL must be the http:// or https:// base URL of the conversion API, without credentials, query string or fragment (got: 'https://user:pass@api.example.test')",
            ],
            'API URL with a query string' => [
                ['IMAGE_API_URL' => 'https://api.example.test/?key=1'],
                "IMAGE_API_URL must be the http:// or https:// base URL of the conversion API, without credentials, query string or fragment (got: 'https://api.example.test/?key=1')",
            ],
            'API key below 16 bytes' => [
                ['IMAGE_API_KEY' => '0123456789abcde'],
                'IMAGE_API_KEY must be 16 or more printable ASCII characters, without leading or trailing spaces',
            ],
            'API key with a control character' => [
                ['IMAGE_API_KEY' => "0123456789abcdef\x01"],
                'IMAGE_API_KEY must be 16 or more printable ASCII characters, without leading or trailing spaces',
            ],
            'relative CA file' => [
                ['IMAGE_API_CA_FILE' => 'ca.pem'],
                "IMAGE_API_CA_FILE must be the absolute path of a readable PEM file (got: 'ca.pem')",
            ],
            'resize widths without key' => [
                ['IMAGE_CONVERTER' => 'local', 'RESIZE_WIDTHS' => '320'],
                'RESIZE_KEY must be set when RESIZE_WIDTHS is',
            ],
            'resize widths without converter' => [
                ['RESIZE_WIDTHS' => '320', 'RESIZE_KEY' => str_repeat('k', 32)],
                'RESIZE_WIDTHS needs a converter: set IMAGE_CONVERTER to "local" or "remote"',
            ],
            'resize width of zero' => [
                ['IMAGE_CONVERTER' => 'local', 'RESIZE_WIDTHS' => '0,320', 'RESIZE_KEY' => str_repeat('k', 32)],
                "RESIZE_WIDTHS must list 1 to 16 distinct widths in pixels, from 1 to 16383, separated by commas (got: '0,320')",
            ],
            'resize width listed twice' => [
                ['IMAGE_CONVERTER' => 'local', 'RESIZE_WIDTHS' => '320,320', 'RESIZE_KEY' => str_repeat('k', 32)],
                "RESIZE_WIDTHS must list 1 to 16 distinct widths in pixels, from 1 to 16383, separated by commas (got: '320,320')",
            ],
            'resize width above range' => [
                ['IMAGE_CONVERTER' => 'local', 'RESIZE_WIDTHS' => '16384', 'RESIZE_KEY' => str_repeat('k', 32)],
                "RESIZE_WIDTHS must list 1 to 16 distinct widths in pixels, from 1 to 16383, separated by commas (got: '16384')",
            ],
            'too many resize widths' => [
                ['IMAGE_CONVERTER' => 'local', 'RESIZE_WIDTHS' => implode(',', range(1, 17)), 'RESIZE_KEY' => str_repeat('k', 32)],
                "RESIZE_WIDTHS must list 1 to 16 distinct widths in pixels, from 1 to 16383, separated by commas (got: '" . implode(',', range(1, 17)) . "')",
            ],
            'short resize key' => [
                ['RESIZE_KEY' => str_repeat('k', 31)],
                'RESIZE_KEY must be 32 or more printable ASCII characters without spaces',
            ],
            'integer with letters' => [['CACHE_EXPIRY' => '1d'], "CACHE_EXPIRY must be a non-negative integer (got: '1d')"],
            'negative integer' => [['NEGATIVE_CACHE_TTL' => '-1'], "NEGATIVE_CACHE_TTL must be a non-negative integer (got: '-1')"],
            'integer with sign' => [['NETWORK_ERROR_TTL' => '+5'], "NETWORK_ERROR_TTL must be a non-negative integer (got: '+5')"],
            'integer with newline' => [['UPSTREAM_TIMEOUT' => "5\n"], "UPSTREAM_TIMEOUT must be a non-negative integer (got: '5\n')"],
            'negative TTL above range' => [['NEGATIVE_CACHE_TTL' => '86401'], 'NEGATIVE_CACHE_TTL must be between 0 and 86400 (got: 86401)'],
            'revalidation threshold above range' => [['CACHE_REVALIDATE_BELOW' => '31536001'], 'CACHE_REVALIDATE_BELOW must be between 0 and 31536000 (got: 31536001)'],
            'stale-while-revalidate above range' => [['STALE_WHILE_REVALIDATE' => '86401'], 'STALE_WHILE_REVALIDATE must be between 0 and 86400 (got: 86401)'],
            'stale-if-error above range' => [['STALE_IF_ERROR' => '604801'], 'STALE_IF_ERROR must be between 0 and 604800 (got: 604801)'],
            'network TTL above range' => [['NETWORK_ERROR_TTL' => '3601'], 'NETWORK_ERROR_TTL must be between 0 and 3600 (got: 3601)'],
            'breaker threshold of zero' => [['CIRCUIT_BREAKER_THRESHOLD' => '0'], 'CIRCUIT_BREAKER_THRESHOLD must be between 1 and 1000 (got: 0)'],
            'timeout below range' => [['UPSTREAM_TIMEOUT' => '0'], 'UPSTREAM_TIMEOUT must be between 1 and 300 (got: 0)'],
            'timeout above range' => [['UPSTREAM_TIMEOUT' => '301'], 'UPSTREAM_TIMEOUT must be between 1 and 300 (got: 301)'],
            'connect timeout below range' => [
                ['UPSTREAM_CONNECT_TIMEOUT' => '0'],
                'UPSTREAM_CONNECT_TIMEOUT must be between 1 and 60 (got: 0)',
            ],
            'connect timeout above range' => [
                ['UPSTREAM_CONNECT_TIMEOUT' => '61'],
                'UPSTREAM_CONNECT_TIMEOUT must be between 1 and 60 (got: 61)',
            ],
            'debug not a boolean' => [['DEBUG' => 'yes'], "DEBUG must be one of: true, false, 1, 0 (got: 'yes')"],
            'verify not a boolean' => [['UPSTREAM_VERIFY_TLS' => 'on'], "UPSTREAM_VERIFY_TLS must be one of: true, false, 1, 0 (got: 'on')"],
            'follow not a boolean' => [
                ['UPSTREAM_FOLLOW_REDIRECTS' => '2'],
                "UPSTREAM_FOLLOW_REDIRECTS must be one of: true, false, 1, 0 (got: '2')",
            ],
            'max size zero' => [['UPSTREAM_MAX_SIZE' => '0'], 'UPSTREAM_MAX_SIZE must be between 1 and 268435456 (got: 0)'],
            'max size above 256 MiB' => [['UPSTREAM_MAX_SIZE' => '268435457'], 'UPSTREAM_MAX_SIZE must be between 1 and 268435456 (got: 268435457)'],
            'max size with a unit' => [['UPSTREAM_MAX_SIZE' => '64M'], "UPSTREAM_MAX_SIZE must be a non-negative integer (got: '64M')"],
            'User-Agent with a line feed' => [
                ['UPSTREAM_USER_AGENT' => "agent\nX-Injected: 1"],
                "UPSTREAM_USER_AGENT must be 1 to 512 printable ASCII characters (got: 'agent\nX-Injected: 1')",
            ],
            'User-Agent with UTF-8' => [
                ['UPSTREAM_USER_AGENT' => 'agént'],
                "UPSTREAM_USER_AGENT must be 1 to 512 printable ASCII characters (got: 'agént')",
            ],
            'origin server with a path' => [
                ['SOURCE_ORIGIN_SERVER' => '192.0.2.10:443/x'],
                'SOURCE_ORIGIN_SERVER must be "address", "address:port" or "[IPv6 address]:port" (got: \'192.0.2.10:443/x\')',
            ],
            'origin server with a bad port' => [
                ['SOURCE_ORIGIN_SERVER' => '192.0.2.10:70000'],
                'SOURCE_ORIGIN_SERVER must be "address", "address:port" or "[IPv6 address]:port" (got: \'192.0.2.10:70000\')',
            ],
            'origin server IPv6 without brackets' => [
                ['SOURCE_ORIGIN_SERVER' => '2001:db8::1:443'],
                'SOURCE_ORIGIN_SERVER must be "address", "address:port" or "[IPv6 address]:port" (got: \'2001:db8::1:443\')',
            ],
            'unknown time zone' => [['TIMEZONE' => 'Mars/Olympus'], "TIMEZONE must be a valid time zone identifier (got: 'Mars/Olympus')"],
            'empty allowlist' => [
                ['QUERY_STRING_ALLOWLIST' => ' , '],
                'QUERY_STRING_ALLOWLIST must list parameter names separated by commas, "*" or "none" (got: \' , \')',
            ],
            'allowlist with an invalid name' => [
                ['QUERY_STRING_ALLOWLIST' => 'v,a=b'],
                'QUERY_STRING_ALLOWLIST holds an invalid parameter name (got: \'v,a=b\')',
            ],
            'allowlist mixing "*" and names' => [
                ['QUERY_STRING_ALLOWLIST' => '*,v'],
                'QUERY_STRING_ALLOWLIST must be "*" alone to keep every parameter (got: \'*,v\')',
            ],
            'invalid trusted proxy' => [
                ['TRUSTED_PROXIES' => '10.0.0.0/8, proxy.internal'],
                'TRUSTED_PROXIES must list IP addresses and CIDR blocks separated by commas: "proxy.internal" is not an IP address or a CIDR block',
            ],
            'trusted proxy with a bad prefix' => [
                ['TRUSTED_PROXIES' => '10.0.0.0/33'],
                'TRUSTED_PROXIES must list IP addresses and CIDR blocks separated by commas: "10.0.0.0/33" has an invalid prefix length (0 to 32)',
            ],
            'purge key with a short secret, not quoted' => [
                ['PURGE_KEYS' => 'origin.example.test=short'],
                'PURGE_KEYS must list "scope=secret" pairs separated by commas: the scope is the host name of an origin or "*", '
                . 'the secret 16 or more printable ASCII characters without spaces or commas',
            ],
            'unknown query string mode' => [
                ['QUERY_STRING_MODE' => 'drop'],
                "QUERY_STRING_MODE must be one of: ignore, reject (got: 'drop')",
            ],
            'missing hosts' => [['PROXY_HOSTS' => null], self::HOSTS_REQUIRED],
            'wildcard host' => [['PROXY_HOSTS' => '*.example.test'], self::invalidHosts('*.example.test')],
            'ipv6 host' => [['PROXY_HOSTS' => '[::1]'], self::invalidHosts('[::1]')],
            'host listed twice' => [['PROXY_HOSTS' => 'a.example.test,A.example.test'], self::invalidHosts('a.example.test,A.example.test')],
            'host origin not a url' => [['PROXY_HOSTS' => 'a.example.test=origin'], self::invalidHosts('a.example.test=origin')],
            'host origin with a query' => [['PROXY_HOSTS' => 'a.example.test=https://o.example.test/?x=1'], self::invalidHosts('a.example.test=https://o.example.test/?x=1')],
            'pinned server with two origins' => [
                ['PROXY_HOSTS' => 'a.example.test,b.example.test=https://other.example.test', 'SOURCE_ORIGIN_SERVER' => '192.0.2.1'],
                'SOURCE_ORIGIN_SERVER cannot be combined with PROXY_HOSTS entries of another origin: it pins the connections to every origin',
            ],
            'unknown sendfile' => [['SENDFILE' => 'apache'], "SENDFILE must be one of: none, x-sendfile, x-accel-redirect (got: 'apache')"],
            'invalid precompress' => [['PRECOMPRESS' => 'yes'], "PRECOMPRESS must be one of: true, false, 1, 0 (got: 'yes')"],
        ];
    }

    public function testEveryErrorIsReported(): void
    {
        $validation = (new ConfigValidator())->validate([]);

        self::assertSame([
            'SOURCE_ORIGIN must be set',
            self::HOSTS_REQUIRED,
            'CACHEABLE_EXTENSIONS must be set (comma-separated list of file extensions)',
        ], $validation->getErrors());
    }

    /**
     * @dataProvider provideAcceptedValues
     *
     * @param array<string, string|null> $overrides
     */
    #[DataProvider('provideAcceptedValues')]
    public function testAcceptedValueIsNotReported(array $overrides): void
    {
        $validation = (new ConfigValidator())->validate(TestConfig::rawValues($overrides));

        self::assertSame([], $validation->getErrors());
    }

    /**
     * @return array<string, array{array<string, string|null>}>
     */
    public static function provideAcceptedValues(): array
    {
        return [
            'origin with upper-case scheme' => [['SOURCE_ORIGIN' => 'HTTPS://origin.example.test']],
            'origin with path' => [['SOURCE_ORIGIN' => 'https://origin.example.test/assets/']],
            'native mode' => [['IMAGE_CONVERSION_MODE' => 'native']],
            'local converter' => [['IMAGE_CONVERTER' => 'local', 'IMAGE_FORMATS' => 'WEBP']],
            'remote converter' => [[
                'IMAGE_CONVERTER' => 'remote',
                'IMAGE_API_URL' => 'https://api.example.test/base/',
                'IMAGE_API_KEY' => '0123456789abcdef',
                'IMAGE_API_CA_FILE' => __FILE__,
                'IMAGE_API_FALLBACK' => 'true',
                'IMAGE_CONVERSION_TIMEOUT' => '600',
            ]],
            'resize' => [[
                'IMAGE_CONVERTER' => 'local',
                'RESIZE_WIDTHS' => '320, 640,16383',
                'RESIZE_KEY' => str_repeat('k', 32),
            ]],
            'largest cache expiry' => [['CACHE_EXPIRY' => (string) PHP_INT_MAX]],
            'zero TTLs' => [['CACHE_EXPIRY' => '0', 'NEGATIVE_CACHE_TTL' => '0', 'NETWORK_ERROR_TTL' => '0']],
            'range limits' => [[
                'NEGATIVE_CACHE_TTL' => '86400',
                'NETWORK_ERROR_TTL' => '3600',
                'UPSTREAM_TIMEOUT' => '300',
                'UPSTREAM_CONNECT_TIMEOUT' => '60',
            ]],
            'booleans in upper case' => [['DEBUG' => 'FALSE', 'UPSTREAM_VERIFY_TLS' => 'True', 'UPSTREAM_FOLLOW_REDIRECTS' => '0']],
            'time zone in lower case' => [['TIMEZONE' => 'europe/rome']],
            'origin server forms' => [['SOURCE_ORIGIN_SERVER' => '192.0.2.10']],
            'origin server with IPv6' => [['SOURCE_ORIGIN_SERVER' => '[2001:db8::1]:8443']],
            'origin server name' => [['SOURCE_ORIGIN_SERVER' => 'origin.internal:8080']],
            'smallest max size' => [['UPSTREAM_MAX_SIZE' => '1']],
            'largest max size' => [['UPSTREAM_MAX_SIZE' => '268435456']],
            'custom User-Agent' => [['UPSTREAM_USER_AGENT' => 'my-cdn/1.0 (+https://cdn.example.test)']],
            'allowlist of names' => [['QUERY_STRING_ALLOWLIST' => 'v, lang ,a[]']],
            'allowlist of none' => [['QUERY_STRING_ALLOWLIST' => 'None']],
            'reject mode' => [['QUERY_STRING_MODE' => 'reject']],
            'breaker threshold limits' => [['CIRCUIT_BREAKER_THRESHOLD' => '1000']],
            'purge keys' => [['PURGE_KEYS' => 'origin.example.test=0123456789abcdef, *=fedcba9876543210']],
            'trusted proxies' => [['TRUSTED_PROXIES' => '10.0.0.0/8, 192.0.2.10, 2001:db8::/32, ::ffff:198.51.100.0/120']],
        ];
    }

    public function testAPlainHttpApiIsWarnedAbout(): void
    {
        $validation = (new ConfigValidator())->validate(TestConfig::rawValues([
            'IMAGE_CONVERTER' => 'remote',
            'IMAGE_API_URL' => 'http://api.internal',
            'IMAGE_API_KEY' => '0123456789abcdef',
        ]));

        self::assertTrue($validation->isValid());
        self::assertSame(["WARNING: IMAGE_API_URL uses plain http://: the API key and the images\ntravel in clear text. Use https:// outside a private network."], $validation->getWarnings());
    }

    public function testTheApiKeyIsNeverQuoted(): void
    {
        $validation = (new ConfigValidator())->validate(TestConfig::rawValues(['IMAGE_API_KEY' => 'short-secret']));

        self::assertStringNotContainsString('short-secret', implode("\n", $validation->getErrors()));
    }

    public function testSensitiveExtensionsAreWarnedAbout(): void
    {
        $validation = (new ConfigValidator())->validate(TestConfig::rawValues(['CACHEABLE_EXTENSIONS' => 'css,JSON, html,png']));

        self::assertTrue($validation->isValid());
        self::assertSame([
            "WARNING: CACHEABLE_EXTENSIONS includes session/auth-sensitive types: html, json.\n"
            . "If the origin ever serves authenticated content with these extensions,\n"
            . 'the proxy will cache and re-serve it to unrelated clients.',
        ], $validation->getWarnings());
    }

    public function testDebugIsWarnedAbout(): void
    {
        $validation = (new ConfigValidator())->validate(TestConfig::rawValues(['DEBUG' => '1']));

        self::assertSame([
            "WARNING: DEBUG is enabled. MUST be disabled in production,\n"
            . 'or PHP warnings will poison asset bodies streamed to clients.',
        ], $validation->getWarnings());
    }

    public function testPinnedOriginServerIsNoted(): void
    {
        $validation = (new ConfigValidator())->validate(TestConfig::rawValues(['SOURCE_ORIGIN_SERVER' => '192.0.2.10:443']));

        self::assertSame([
            "NOTE: SOURCE_ORIGIN_SERVER is set. Origin requests connect to that\n"
            . "address (an IP or a host name, with an optional port) instead of the\n"
            . "address of the origin host name, which they keep for the Host header\n"
            . "and TLS. This intentionally bypasses any CDN / WAF that may sit in\n"
            . 'front of the origin hostname.',
        ], $validation->getWarnings());
    }

    public function testKeepingEveryQueryParameterIsNotedP5(): void
    {
        $validation = (new ConfigValidator())->validate(TestConfig::rawValues(['QUERY_STRING_ALLOWLIST' => '*']));

        self::assertTrue($validation->isValid());
        self::assertSame([
            "NOTE: QUERY_STRING_ALLOWLIST is \"*\": every distinct query string is\n"
            . "a cache entry and an origin fetch of its own, as in 2.x, so random\n"
            . 'query strings can fill the disk and bypass the cache.',
        ], $validation->getWarnings());
    }

    /**
     * @dataProvider provideUnverifiedTls
     */
    #[DataProvider('provideUnverifiedTls')]
    public function testUnverifiedTlsIsNoted(?string $verifyTls): void
    {
        $validation = (new ConfigValidator())->validate(TestConfig::rawValues(['UPSTREAM_VERIFY_TLS' => $verifyTls]));

        self::assertSame([
            "NOTE: UPSTREAM_VERIFY_TLS is off. The origin TLS certificate\n"
            . 'will NOT be validated. Acceptable on trusted networks only.',
        ], $validation->getWarnings());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnverifiedTls(): array
    {
        return [
            'false' => ['false'],
            'zero' => ['0'],
        ];
    }

    /**
     * TLS is verified by default since 3.0; check-env tells the operator
     * of an https origin who relied on the 2.x default (risk L-6).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testTheTlsDefaultIsNotedForAnHttpsOriginL6(): void
    {
        $validation = (new ConfigValidator())->validate(TestConfig::rawValues(['UPSTREAM_VERIFY_TLS' => null]));

        self::assertTrue($validation->isValid());
        self::assertSame([
            "NOTE: UPSTREAM_VERIFY_TLS is not set, so the origin TLS certificate\n"
            . "is verified (the default since 3.0; 2.x did not verify). An origin\n"
            . "with a self-signed or mismatched certificate now answers 503: fix\n"
            . "the certificate, or set UPSTREAM_VERIFY_TLS=false on trusted\n"
            . 'networks only.',
        ], $validation->getWarnings());
    }

    public function testTheTlsDefaultIsNotNotedForAnHttpOrigin(): void
    {
        $validation = (new ConfigValidator())->validate(TestConfig::rawValues([
            'UPSTREAM_VERIFY_TLS' => null,
            'SOURCE_ORIGIN' => 'http://origin.example.test',
        ]));

        self::assertSame([], $validation->getWarnings());
    }

    /**
     * @dataProvider provideExtensionLists
     *
     * @param list<string> $expected
     */
    #[DataProvider('provideExtensionLists')]
    public function testExtensionList(?string $raw, array $expected): void
    {
        self::assertSame($expected, ConfigValidator::extensionList($raw));
    }

    /**
     * @return array<string, array{string|null, list<string>}>
     */
    public static function provideExtensionLists(): array
    {
        return [
            'unset' => [null, []],
            'one' => ['css', ['css']],
            'spaces and case' => [' CSS , Js,png ', ['css', 'js', 'png']],
            'empty tokens' => ['css,,js,', ['css', 'js']],
            'zero token' => ['css,0,js', ['css', 'js']],
        ];
    }

    /**
     * @dataProvider provideBooleans
     */
    #[DataProvider('provideBooleans')]
    public function testBooleanOf(string $raw, ?bool $expected): void
    {
        self::assertSame($expected, ConfigValidator::booleanOf($raw));
    }

    /**
     * @return array<string, array{string, bool|null}>
     */
    public static function provideBooleans(): array
    {
        return [
            'true' => ['true', true],
            'TRUE' => ['TRUE', true],
            'one' => ['1', true],
            'false' => ['false', false],
            'False' => ['False', false],
            'zero' => ['0', false],
            'yes' => ['yes', null],
            'empty' => ['', null],
            'padded' => [' true', null],
        ];
    }

    private static function invalidHosts(string $value): string
    {
        return 'PROXY_HOSTS must list "host" or "host=origin URL" entries separated by commas, each host once: '
            . 'a host is letters, digits, "-" and "." (no IPv6 literal, no wildcard), an origin an http or https URL (got: '
            . var_export($value, true) . ')';
    }
}
