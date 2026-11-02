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
        ])->getOperations();

        self::assertSame('/var/log/proxy-cdn/proxy.log', $operations->getLogFile());
        self::assertSame('warning', $operations->getLogLevel());
        self::assertSame(['metrics-key-0123456789', 'sha256:' . str_repeat('AB', 32)], $operations->getMetricsKeys());
        self::assertSame('off', $operations->getMetricsStore());
        self::assertSame(600, $operations->getMissRateLimit());
        self::assertSame(100, $operations->getMissRateLimitBurst());
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

    /**
     * The operations keys survive the compiled configuration.
     */
    public function testTheValuesRoundTrip(): void
    {
        $config = TestConfig::create([
            'METRICS_KEYS' => 'metrics-key-0123456789',
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
        $values['LOG_FILE'] = 42;

        $this->expectException(InvalidConfigurationException::class);

        OperationsConfig::fromArray($values);
    }
}
