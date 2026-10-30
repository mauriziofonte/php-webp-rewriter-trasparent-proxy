<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Config;

use Mfonte\ProxyCdn\Config\ConfigValidator;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
    public function testValidValuesHaveNoErrorsAndNoWarnings(): void
    {
        $validation = (new ConfigValidator())->validate(TestConfig::rawValues());

        self::assertTrue($validation->isValid());
        self::assertSame([], $validation->getErrors());
        self::assertSame([], $validation->getWarnings());
    }

    public function testOnlyOriginAndExtensionsAreRequired(): void
    {
        $values = array_fill_keys(array_keys(TestConfig::rawValues()), null);
        $values['SOURCE_ORIGIN'] = 'http://origin.example.test';
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
            'mode in upper case' => [
                ['IMAGE_CONVERSION_MODE' => 'NATIVE'],
                "IMAGE_CONVERSION_MODE must be one of: native, api, none (got: 'NATIVE')",
            ],
            'api without server' => [
                ['IMAGE_CONVERSION_MODE' => 'api', 'WEBP_API_KEY' => 'key'],
                'WEBP_API_SERVER must be set for API mode',
            ],
            'api without key' => [
                ['IMAGE_CONVERSION_MODE' => 'api', 'WEBP_API_SERVER' => 'https://api.example.test'],
                'WEBP_API_KEY must be set for API mode',
            ],
            'integer with letters' => [['CACHE_EXPIRY' => '1d'], "CACHE_EXPIRY must be a non-negative integer (got: '1d')"],
            'negative integer' => [['NEGATIVE_CACHE_TTL' => '-1'], "NEGATIVE_CACHE_TTL must be a non-negative integer (got: '-1')"],
            'integer with sign' => [['NETWORK_ERROR_TTL' => '+5'], "NETWORK_ERROR_TTL must be a non-negative integer (got: '+5')"],
            'integer with newline' => [['UPSTREAM_TIMEOUT' => "5\n"], "UPSTREAM_TIMEOUT must be a non-negative integer (got: '5\n')"],
            'negative TTL above range' => [['NEGATIVE_CACHE_TTL' => '86401'], 'NEGATIVE_CACHE_TTL must be between 0 and 86400 (got: 86401)'],
            'network TTL above range' => [['NETWORK_ERROR_TTL' => '3601'], 'NETWORK_ERROR_TTL must be between 0 and 3600 (got: 3601)'],
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
            'unknown time zone' => [['TIMEZONE' => 'Mars/Olympus'], "TIMEZONE must be a valid time zone identifier (got: 'Mars/Olympus')"],
        ];
    }

    public function testEveryErrorIsReported(): void
    {
        $validation = (new ConfigValidator())->validate([]);

        self::assertSame([
            'SOURCE_ORIGIN must be set',
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
            'api mode' => [[
                'IMAGE_CONVERSION_MODE' => 'api',
                'WEBP_API_SERVER' => 'https://api.example.test',
                'WEBP_API_KEY' => 'key',
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
        ];
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
            "NOTE: SOURCE_ORIGIN_SERVER is set. cURL will bypass DNS and\n"
            . "talk directly to the pinned IP:port. This intentionally bypasses any\n"
            . 'CDN / WAF that may sit in front of the origin hostname.',
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
     * @return array<string, array{string|null}>
     */
    public static function provideUnverifiedTls(): array
    {
        return [
            'unset' => [null],
            'false' => ['false'],
            'zero' => ['0'],
        ];
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
}
