<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Config;

use Mfonte\ProxyCdn\Config\ConfigLoader;
use Mfonte\ProxyCdn\Config\ConfigValidator;
use Mfonte\ProxyCdn\Config\OperationsConfig;
use Mfonte\ProxyCdn\Exception\InvalidConfigurationException;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Config\OperationsConfig
 */
#[CoversClass(OperationsConfig::class)]
final class OperationsConfigTest extends TestCase
{
    public function testTheDefaultsKeepASingleQuietInstance(): void
    {
        $operations = TestConfig::create()->getOperations();

        self::assertNull($operations->getLogFile());
        self::assertNull($operations->getLogLevel());
        self::assertSame([], $operations->getMetricsKeys());
        self::assertSame(OperationsConfig::METRICS_STORE_FILE, $operations->getMetricsStore());
        self::assertSame(0, $operations->getMissRateLimit());
        self::assertSame(60, $operations->getMissRateLimitBurst());
        self::assertNull($operations->getEarlyHints());
        self::assertSame([], $operations->getClusterPeers());
        self::assertNull($operations->getClusterCaFile());
        self::assertSame(2, $operations->getClusterTimeout());
        self::assertSame(0, $operations->getCacheMaxSize());
    }

    public function testTheValuesAreNormalised(): void
    {
        $operations = TestConfig::create([
            'LOG_FILE' => '/var/log/proxy-cdn/proxy.log',
            'LOG_LEVEL' => 'warning',
            'METRICS_KEYS' => ' metrics-key-0123456789 , sha256:' . str_repeat('AB', 32),
            'METRICS_STORE' => 'off',
            'MISS_RATE_LIMIT' => '600',
            'MISS_RATE_LIMIT_BURST' => '100',
            'TRUSTED_PROXIES' => '10.0.0.0/8',
            'EARLY_HINTS' => ' </css/app.css>; rel=preload; as=style, <https://fonts.example.test>; rel=preconnect ',
            'PURGE_KEYS' => '*=purge-secret-0123456789',
            'CLUSTER_PEERS' => 'https://Peer-1.internal:8443/, https://peer-2.internal, https://peer-1.internal:8443',
            'CLUSTER_CA_FILE' => '/etc/proxy-cdn/peers-ca.pem',
            'CLUSTER_TIMEOUT' => '5',
            'CACHE_MAX_SIZE' => '10G',
        ])->getOperations();

        self::assertSame('/var/log/proxy-cdn/proxy.log', $operations->getLogFile());
        self::assertSame('warning', $operations->getLogLevel());
        self::assertSame(['metrics-key-0123456789', 'sha256:' . str_repeat('AB', 32)], $operations->getMetricsKeys());
        self::assertSame('off', $operations->getMetricsStore());
        self::assertSame(600, $operations->getMissRateLimit());
        self::assertSame(100, $operations->getMissRateLimitBurst());
        self::assertSame('</css/app.css>; rel=preload; as=style, <https://fonts.example.test>; rel=preconnect', $operations->getEarlyHints());
        self::assertSame(['https://peer-1.internal:8443', 'https://peer-2.internal'], $operations->getClusterPeers());
        self::assertSame('/etc/proxy-cdn/peers-ca.pem', $operations->getClusterCaFile());
        self::assertSame(5, $operations->getClusterTimeout());
        self::assertSame(10737418240, $operations->getCacheMaxSize());
    }

    /**
     * @dataProvider provideInvalidValues
     *
     * @param array<string, string|null> $overrides
     * @param non-empty-string           $error
     */
    #[DataProvider('provideInvalidValues')]
    public function testInvalidValuesAreReported(array $overrides, string $error): void
    {
        $validation = (new ConfigValidator())->validate(TestConfig::rawValues($overrides));

        self::assertFalse($validation->isValid());
        self::assertStringStartsWith($error, implode("\n", $validation->getErrors()));
    }

    /**
     * @return array<string, array{array<string, string|null>, non-empty-string}>
     */
    public static function provideInvalidValues(): array
    {
        return [
            'relative log file' => [['LOG_FILE' => 'logs/proxy.log'], 'LOG_FILE must be'],
            'unknown log level' => [['LOG_LEVEL' => 'verbose'], 'LOG_LEVEL must be'],
            'short metrics key' => [['METRICS_KEYS' => 'short'], 'METRICS_KEYS must'],
            'metrics key with space' => [['METRICS_KEYS' => 'a key with spaces inside'], 'METRICS_KEYS must'],
            'bad digest' => [['METRICS_KEYS' => 'sha256:abc'], 'METRICS_KEYS must'],
            'unknown store' => [['METRICS_STORE' => 'apcu'], 'METRICS_STORE must'],
            'negative rate' => [['MISS_RATE_LIMIT' => '-1'], 'MISS_RATE_LIMIT must'],
            'huge rate' => [['MISS_RATE_LIMIT' => '1000001'], 'MISS_RATE_LIMIT must'],
            'zero burst' => [['MISS_RATE_LIMIT_BURST' => '0'], 'MISS_RATE_LIMIT_BURST must'],
            'hints without brackets' => [['EARLY_HINTS' => '/css/app.css; rel=preload'], 'EARLY_HINTS must'],
            'hints with a line break' => [['EARLY_HINTS' => "</a.css>; rel=preload\r\nX: y"], 'EARLY_HINTS must'],
            'http peer' => [['CLUSTER_PEERS' => 'http://peer.internal', 'PURGE_KEYS' => '*=purge-secret-0123456789'], 'CLUSTER_PEERS must'],
            'peer with a path' => [['CLUSTER_PEERS' => 'https://peer.internal/proxy', 'PURGE_KEYS' => '*=purge-secret-0123456789'], 'CLUSTER_PEERS must'],
            'peer with a user' => [['CLUSTER_PEERS' => 'https://user:pass@peer.internal', 'PURGE_KEYS' => '*=purge-secret-0123456789'], 'CLUSTER_PEERS must'],
            'peer with a query' => [['CLUSTER_PEERS' => 'https://peer.internal?x=1', 'PURGE_KEYS' => '*=purge-secret-0123456789'], 'CLUSTER_PEERS must'],
            'peers without purge keys' => [['CLUSTER_PEERS' => 'https://peer.internal'], 'CLUSTER_PEERS needs PURGE_KEYS'],
            'relative CA file' => [['CLUSTER_CA_FILE' => 'ca.pem'], 'CLUSTER_CA_FILE must'],
            'zero timeout' => [['CLUSTER_TIMEOUT' => '0'], 'CLUSTER_TIMEOUT must'],
            'long timeout' => [['CLUSTER_TIMEOUT' => '31'], 'CLUSTER_TIMEOUT must'],
            'size with a unit' => [['CACHE_MAX_SIZE' => '10 apples'], 'CACHE_MAX_SIZE must'],
            'negative size' => [['CACHE_MAX_SIZE' => '-5G'], 'CACHE_MAX_SIZE must'],
        ];
    }

    /**
     * The secrets of METRICS_KEYS never appear in an error.
     */
    public function testAnInvalidMetricsKeyIsNotQuoted(): void
    {
        $validation = (new ConfigValidator())->validate(TestConfig::rawValues(['METRICS_KEYS' => 'valid-metrics-key-0001, tiny-secret']));

        self::assertStringNotContainsString('tiny-secret', implode("\n", $validation->getErrors()));
        self::assertStringNotContainsString('valid-metrics-key-0001', implode("\n", $validation->getErrors()));
    }

    public function testARateLimitWithoutTrustedProxiesIsNoted(): void
    {
        $validation = (new ConfigValidator())->validate(TestConfig::rawValues(['MISS_RATE_LIMIT' => '600']));
        $behindProxy = (new ConfigValidator())->validate(TestConfig::rawValues(['MISS_RATE_LIMIT' => '600', 'TRUSTED_PROXIES' => '10.0.0.1']));

        self::assertTrue($validation->isValid());
        self::assertStringContainsString('MISS_RATE_LIMIT is on and TRUSTED_PROXIES is empty', implode("\n", $validation->getWarnings()));
        self::assertStringNotContainsString('MISS_RATE_LIMIT', implode("\n", $behindProxy->getWarnings()));
    }

    public function testEarlyHintsAreNotedAsServerApiDependent(): void
    {
        $validation = (new ConfigValidator())->validate(TestConfig::rawValues(['EARLY_HINTS' => '</a.css>; rel=preload']));

        self::assertTrue($validation->isValid());
        self::assertStringContainsString('EARLY_HINTS is sent as a 103 only where PHP can send', implode("\n", $validation->getWarnings()));
    }

    /**
     * @dataProvider provideSizes
     */
    #[DataProvider('provideSizes')]
    public function testParseSize(string $raw, ?int $bytes): void
    {
        self::assertSame($bytes, OperationsConfig::parseSize($raw));
    }

    /**
     * @return array<string, array{string, int|null}>
     */
    public static function provideSizes(): array
    {
        return [
            'bytes' => ['1000', 1000],
            'zero' => ['0', 0],
            'kibibytes' => ['4K', 4096],
            'mebibytes with B' => ['2MB', 2097152],
            'gibibytes with iB' => ['3GiB', 3221225472],
            'lower case' => ['1t', 1099511627776],
            'space before unit' => ['5 G', 5368709120],
            'fraction' => ['1.5G', null],
            'unknown unit' => ['1P', null],
            'empty' => ['', null],
            'too large' => ['999999999999999T', null],
        ];
    }

    /**
     * @dataProvider provideLinkValues
     */
    #[DataProvider('provideLinkValues')]
    public function testIsLinkValue(string $value, bool $valid): void
    {
        self::assertSame($valid, OperationsConfig::isLinkValue($value));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function provideLinkValues(): array
    {
        return [
            'preload' => ['</app.css>; rel=preload; as=style', true],
            'two links' => ['</app.css>; rel=preload; as=style, <https://fonts.example.test>; rel=preconnect; crossorigin', true],
            'quoted parameter' => ['</a.woff2>; rel="preload"; as=font; type="font/woff2"', true],
            'no link' => ['rel=preload', false],
            'empty uri' => ['<>; rel=preload', false],
            'nested bracket' => ['<a<b>; rel=preload', false],
            'header injection' => ["</a>; rel=preload\nSet-Cookie: x=1", false],
            'trailing comma' => ['</a>; rel=preload,', false],
        ];
    }

    /**
     * The operations keys survive the compiled configuration.
     */
    public function testTheValuesRoundTrip(): void
    {
        $config = TestConfig::create([
            'METRICS_KEYS' => 'metrics-key-0123456789',
            'CLUSTER_PEERS' => 'https://peer.internal',
            'PURGE_KEYS' => '*=purge-secret-0123456789',
            'CACHE_MAX_SIZE' => '1M',
        ]);

        self::assertSame($config->getOperations()->toArray(), OperationsConfig::fromArray($config->toArray())->toArray());
        self::assertSame(OperationsConfig::KEYS, array_keys($config->getOperations()->toArray()));
        self::assertSame(OperationsConfig::KEYS, array_values(array_intersect(ConfigLoader::KEYS, OperationsConfig::KEYS)));
    }

    public function testAMissingKeyIsRejected(): void
    {
        $values = TestConfig::create()->getOperations()->toArray();
        unset($values['LOG_FILE']);

        $this->expectException(InvalidConfigurationException::class);

        OperationsConfig::fromArray($values);
    }

    public function testAWrongTypeIsRejected(): void
    {
        $values = TestConfig::create()->getOperations()->toArray();
        $values['CLUSTER_PEERS'] = 'https://peer.internal';

        $this->expectException(InvalidConfigurationException::class);

        OperationsConfig::fromArray($values);
    }
}
