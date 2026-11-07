<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Observability;

use Mfonte\ProxyCdn\Image\ConversionFailure;
use Mfonte\ProxyCdn\Image\ImageConverter;
use Mfonte\ProxyCdn\Observability\FileMetricsStore;
use Mfonte\ProxyCdn\Observability\MeteredImageConverter;
use Mfonte\ProxyCdn\Observability\Metrics;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Image\FakeImageConverter;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ConversionResult;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Observability\MeteredImageConverter
 */
#[CoversClass(MeteredImageConverter::class)]
final class MeteredImageConverterTest extends TestCase
{
    private const IMAGE = __DIR__ . '/../../Fixtures/Image/files/image.png';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    /**
     * Each conversion is counted by its result, and the converter's own
     * result or failure is passed on unchanged.
     */
    public function testEachOutcomeIsCountedAndPassedOn(): void
    {
        $metrics = new Metrics(new FileMetricsStore($this->directory->path('metrics')));
        $failures = [
            ConversionFailure::rejected('remote', 'The image is corrupt', 'invalid_image'),
            ConversionFailure::unavailable('remote', 'The API answered 503', 'service_unavailable', 503),
            ConversionFailure::unavailable('remote', 'The circuit breaker of the conversion API is open', 'circuit_open'),
        ];
        foreach ($failures as $failure) {
            try {
                (new MeteredImageConverter(self::failing($failure), $metrics))->convert(self::IMAGE, $this->directory->path('out'), new ConversionRequest('webp'));
                self::fail('the failure is thrown');
            } catch (ConversionFailure $thrown) {
                self::assertSame($failure, $thrown);
            }
        }
        $fake = new FakeImageConverter();
        mkdir($this->directory->path('work'));
        $converter = new MeteredImageConverter($fake, $metrics);
        $converter->convert(self::IMAGE, $this->directory->path('work/out'), new ConversionRequest('webp'), 'req-1');
        $metrics->flush();

        $text = (new Metrics(new FileMetricsStore($this->directory->path('metrics'))))->render();

        self::assertStringContainsString('proxy_cdn_image_conversions_total{converter="remote",result="rejected"} 1' . "\n", $text);
        self::assertStringContainsString('proxy_cdn_image_conversions_total{converter="remote",result="unavailable"} 1' . "\n", $text);
        self::assertStringContainsString('proxy_cdn_image_conversions_total{converter="remote",result="circuit_open"} 1' . "\n", $text);
        // "fake" is no converter name of the fixed set.
        self::assertStringContainsString('proxy_cdn_image_conversions_total{converter="other",result="ok"} 1' . "\n", $text);
        self::assertSame('req-1', $fake->getCalls()[0][3]);
        self::assertSame($fake->getName(), $converter->getName());
        self::assertEquals($fake->getCapabilities(), $converter->getCapabilities());
    }

    private static function failing(ConversionFailure $failure): ImageConverter
    {
        return new class ($failure) implements ImageConverter {
            /**
             * @var ConversionFailure
             */
            private $failure;

            public function __construct(ConversionFailure $failure)
            {
                $this->failure = $failure;
            }

            public function convert(string $inputPath, string $outputPath, ConversionRequest $request, ?string $requestId = null): ConversionResult
            {
                throw $this->failure;
            }

            public function getCapabilities(): ?Capabilities
            {
                return null;
            }

            public function getName(): string
            {
                return 'remote';
            }
        };
    }
}
