<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Image\Legacy;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Image\Legacy\ConversionApiConverter;
use Mfonte\ProxyCdn\Image\Legacy\CwebpConverter;
use Mfonte\ProxyCdn\Image\Legacy\LegacyConverterFactory;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Image\Legacy\LegacyConverterFactory
 */
#[CoversClass(LegacyConverterFactory::class)]
final class LegacyConverterFactoryTest extends TestCase
{
    public function testNoneHasNoConverter(): void
    {
        self::assertNull($this->create(['IMAGE_CONVERSION_MODE' => 'none']));
    }

    public function testNativeRunsCwebp(): void
    {
        self::assertInstanceOf(CwebpConverter::class, $this->create(['IMAGE_CONVERSION_MODE' => 'native']));
    }

    public function testApiCallsTheConversionApi(): void
    {
        $converter = $this->create([
            'IMAGE_CONVERSION_MODE' => 'api',
            'WEBP_API_SERVER' => 'https://api.example.test/convert',
            'WEBP_API_KEY' => 'key',
        ]);

        self::assertInstanceOf(ConversionApiConverter::class, $converter);
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private function create(array $overrides): ?object
    {
        return LegacyConverterFactory::fromConfig(TestConfig::create($overrides), new FilesystemStorage(), new MemoryLogger());
    }
}
