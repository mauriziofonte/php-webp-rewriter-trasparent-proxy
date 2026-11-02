<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Config;

use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Exception\InvalidConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Config\Config
 * @covers \Mfonte\ProxyCdn\Exception\InvalidConfigurationException
 */
#[CoversClass(Config::class)]
#[CoversClass(InvalidConfigurationException::class)]
final class ConfigTest extends TestCase
{
    public function testGettersReturnTheValues(): void
    {
        $config = Config::fromArray(self::values());

        self::assertTrue($config->isDebug());
        self::assertSame('https://origin.example.test', $config->getSourceOrigin());
        self::assertSame('192.0.2.10:8443', $config->getSourceOriginServer());
        self::assertSame('ISO-8859-1', $config->getCharset());
        self::assertSame('Europe/Rome', $config->getTimezone());
        self::assertSame('it_IT', $config->getLocale());
        self::assertSame(['css', 'png'], $config->getCacheableExtensions());
        self::assertSame(100, $config->getCacheExpiry());
        self::assertSame(200, $config->getNegativeCacheTtl());
        self::assertSame(600, $config->getCacheRevalidateBelow());
        self::assertSame(30, $config->getStaleWhileRevalidate());
        self::assertSame(900, $config->getStaleIfError());
        self::assertSame(300, $config->getNetworkErrorTtl());
        self::assertSame(40, $config->getUpstreamTimeout());
        self::assertSame(5, $config->getUpstreamConnectTimeout());
        self::assertTrue($config->shouldVerifyUpstreamTls());
        self::assertFalse($config->shouldFollowUpstreamRedirects());
        self::assertSame(1000000, $config->getUpstreamMaxSize());
        self::assertSame('agent/1.0', $config->getUpstreamUserAgent());
        self::assertSame(['v', 'lang'], $config->getQueryStringAllowlist());
        self::assertSame('reject', $config->getQueryStringMode());
        self::assertSame(Config::CONVERTER_REMOTE, $config->getImageConverter());
        self::assertSame(['avif', 'webp'], $config->getImageFormats());
        self::assertSame(55, $config->getImageConversionTimeout());
        self::assertSame('https://api.example.test', $config->getImageApiUrl());
        self::assertSame('secret-key-0123456789', $config->getImageApiKey());
        self::assertSame('/etc/ssl/api-ca.pem', $config->getImageApiCaFile());
        self::assertTrue($config->shouldFallBackToLocalConversion());
        self::assertSame([320, 640], $config->getResizeWidths());
        self::assertSame('resize-key-0123456789abcdef0123456789', $config->getResizeKey());
    }

    public function testTheApiUrlLosesItsTrailingSlash(): void
    {
        self::assertSame('https://api.example.test/base', Config::fromArray(self::values(['IMAGE_API_URL' => 'https://api.example.test/base/']))->getImageApiUrl());
    }

    public function testRemoteConversionNeedsTheUrlAndTheKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        Config::fromArray(self::values(['IMAGE_API_KEY' => null]));
    }

    public function testToArrayRoundTrips(): void
    {
        self::assertSame(self::values(), Config::fromArray(self::values())->toArray());
    }

    public function testOptionalValuesMayBeNull(): void
    {
        $config = Config::fromArray(self::values([
            'SOURCE_ORIGIN_SERVER' => null,
            'TIMEZONE' => null,
            'UPSTREAM_USER_AGENT' => null,
            'IMAGE_CONVERTER' => Config::CONVERTER_NONE,
            'IMAGE_API_URL' => null,
            'IMAGE_API_KEY' => null,
            'IMAGE_API_CA_FILE' => null,
            'RESIZE_KEY' => null,
        ]));

        self::assertNull($config->getSourceOriginServer());
        self::assertNull($config->getTimezone());
        self::assertNull($config->getUpstreamUserAgent());
        self::assertNull($config->getImageApiUrl());
        self::assertNull($config->getImageApiKey());
        self::assertNull($config->getImageApiCaFile());
        self::assertNull($config->getResizeKey());
    }

    public function testIsCacheableExtensionMatchesTheListExactly(): void
    {
        $config = Config::fromArray(self::values());

        self::assertTrue($config->isCacheableExtension('css'));
        self::assertTrue($config->isCacheableExtension('png'));
        self::assertFalse($config->isCacheableExtension('PNG'));
        self::assertFalse($config->isCacheableExtension('php'));
        self::assertFalse($config->isCacheableExtension(''));
    }

    public function testMissingKeyIsRejected(): void
    {
        $values = self::values();
        unset($values['LOCALE']);

        try {
            Config::fromArray($values);
            self::fail('A missing key must be rejected');
        } catch (InvalidConfigurationException $exception) {
            self::assertSame(['LOCALE is missing'], $exception->getErrors());
            self::assertSame('Invalid configuration: LOCALE is missing', $exception->getMessage());
        }
    }

    /**
     * @dataProvider provideWrongValues
     *
     * @param mixed $value
     */
    #[DataProvider('provideWrongValues')]
    public function testWrongTypeIsRejected(string $key, $value, string $error): void
    {
        try {
            Config::fromArray(self::values([$key => $value]));
            self::fail('A value of the wrong type must be rejected');
        } catch (InvalidConfigurationException $exception) {
            self::assertSame([$error], $exception->getErrors());
        }
    }

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function provideWrongValues(): array
    {
        return [
            'boolean as string' => ['DEBUG', 'true', 'DEBUG must be a boolean'],
            'integer as string' => ['CACHE_EXPIRY', '100', 'CACHE_EXPIRY must be a non-negative integer'],
            'negative integer' => ['NEGATIVE_CACHE_TTL', -1, 'NEGATIVE_CACHE_TTL must be a non-negative integer'],
            'empty string' => ['SOURCE_ORIGIN', '', 'SOURCE_ORIGIN must be a non-empty string'],
            'null string' => ['CHARSET', null, 'CHARSET must be a non-empty string'],
            'empty optional string' => ['TIMEZONE', '', 'TIMEZONE must be a non-empty string or null'],
            'integer optional string' => ['IMAGE_API_KEY', 1, 'IMAGE_API_KEY must be a non-empty string or null'],
            'empty list' => ['CACHEABLE_EXTENSIONS', [], 'CACHEABLE_EXTENSIONS must be a non-empty list of strings'],
            'map instead of list' => ['CACHEABLE_EXTENSIONS', ['a' => 'css'], 'CACHEABLE_EXTENSIONS must be a non-empty list of strings'],
            'empty item' => ['CACHEABLE_EXTENSIONS', ['css', ''], 'CACHEABLE_EXTENSIONS must be a non-empty list of strings'],
            'string instead of list' => ['CACHEABLE_EXTENSIONS', 'css', 'CACHEABLE_EXTENSIONS must be a non-empty list of strings'],
            'unknown converter' => ['IMAGE_CONVERTER', 'gd', 'IMAGE_CONVERTER has an unknown value'],
            'empty allowlist item' => ['QUERY_STRING_ALLOWLIST', ['v', ''], 'QUERY_STRING_ALLOWLIST must be a list of non-empty strings'],
            'unknown query mode' => ['QUERY_STRING_MODE', 'drop', 'QUERY_STRING_MODE has an unknown value'],
            'invalid trusted proxy' => ['TRUSTED_PROXIES', ['proxy.internal'], 'TRUSTED_PROXIES must be a list of IP addresses and CIDR blocks'],
        ];
    }

    public function testTheAllowlistMayBeEmpty(): void
    {
        self::assertSame([], Config::fromArray(self::values(['QUERY_STRING_ALLOWLIST' => []]))->getQueryStringAllowlist());
    }

    public function testTrustedProxiesAreParsed(): void
    {
        $proxies = Config::fromArray(self::values(['TRUSTED_PROXIES' => ['192.0.2.10/24']]))->getTrustedProxies();

        self::assertCount(1, $proxies);
        self::assertSame('192.0.2.0/24', (string) $proxies[0]);
        self::assertSame([], Config::fromArray(self::values(['TRUSTED_PROXIES' => []]))->getTrustedProxies());
    }

    public function testExceptionWithoutErrorsHasAGenericError(): void
    {
        $exception = new InvalidConfigurationException([]);

        self::assertSame(['The configuration is invalid'], $exception->getErrors());
        self::assertSame('Invalid configuration: The configuration is invalid', $exception->getMessage());
    }

    public function testExceptionJoinsTheErrors(): void
    {
        $exception = new InvalidConfigurationException(['first', 'second']);

        self::assertSame('Invalid configuration: first; second', $exception->getMessage());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function values(array $overrides = []): array
    {
        return array_merge([
            'DEBUG' => true,
            'SOURCE_ORIGIN' => 'https://origin.example.test',
            'SOURCE_ORIGIN_SERVER' => '192.0.2.10:8443',
            'PROXY_HOSTS' => ['cdn.example.test=https://origin.example.test'],
            'SENDFILE' => Config::SENDFILE_X_ACCEL_REDIRECT,
            'PRECOMPRESS' => false,
            'LEGACY_WEBP_URLS' => true,
            'CHARSET' => 'ISO-8859-1',
            'TIMEZONE' => 'Europe/Rome',
            'LOCALE' => 'it_IT',
            'CACHEABLE_EXTENSIONS' => ['css', 'png'],
            'CACHE_EXPIRY' => 100,
            'CACHE_REVALIDATE_BELOW' => 600,
            'STALE_WHILE_REVALIDATE' => 30,
            'STALE_IF_ERROR' => 900,
            'NEGATIVE_CACHE_TTL' => 200,
            'NETWORK_ERROR_TTL' => 300,
            'CIRCUIT_BREAKER_THRESHOLD' => 3,
            'UPSTREAM_TIMEOUT' => 40,
            'UPSTREAM_CONNECT_TIMEOUT' => 5,
            'UPSTREAM_VERIFY_TLS' => true,
            'UPSTREAM_FOLLOW_REDIRECTS' => false,
            'UPSTREAM_MAX_SIZE' => 1000000,
            'UPSTREAM_USER_AGENT' => 'agent/1.0',
            'QUERY_STRING_ALLOWLIST' => ['v', 'lang'],
            'QUERY_STRING_MODE' => 'reject',
            'TRUSTED_PROXIES' => ['192.0.2.0/24', '2001:db8::/32'],
            'PURGE_KEYS' => ['origin.example.test=0123456789abcdef'],
            'IMAGE_CONVERTER' => Config::CONVERTER_REMOTE,
            'IMAGE_FORMATS' => ['avif', 'webp'],
            'IMAGE_CONVERSION_TIMEOUT' => 55,
            'IMAGE_API_URL' => 'https://api.example.test',
            'IMAGE_API_KEY' => 'secret-key-0123456789',
            'IMAGE_API_CA_FILE' => '/etc/ssl/api-ca.pem',
            'IMAGE_API_FALLBACK' => true,
            'RESIZE_WIDTHS' => [320, 640],
            'RESIZE_KEY' => 'resize-key-0123456789abcdef0123456789',
            'LOG_FILE' => 'stderr',
            'LOG_LEVEL' => 'info',
        ], $overrides);
    }
}
