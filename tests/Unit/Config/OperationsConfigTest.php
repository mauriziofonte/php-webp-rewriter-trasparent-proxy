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
        self::assertSame(OperationsConfig::METRICS_STORE_FILE, $operations->getMetricsStore());
    }

    public function testTheValuesAreNormalised(): void
    {
        $operations = TestConfig::create([
            'LOG_FILE' => '/var/log/proxy-cdn/proxy.log',
            'LOG_LEVEL' => 'warning',
            'METRICS_STORE' => 'off',
        ])->getOperations();

        self::assertSame('/var/log/proxy-cdn/proxy.log', $operations->getLogFile());
        self::assertSame('warning', $operations->getLogLevel());
        self::assertSame('off', $operations->getMetricsStore());
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
            'unknown store' => [['METRICS_STORE' => 'apcu'], 'METRICS_STORE must'],
        ];
    }

    /**
     * The operations keys survive the compiled configuration.
     */
    public function testTheValuesRoundTrip(): void
    {
        $config = TestConfig::create(['LOG_FILE' => 'stderr', 'LOG_LEVEL' => 'info']);

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
