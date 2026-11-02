<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Image;

use Mfonte\ProxyCdn\Image\ConversionFailure;
use Mfonte\ProxyCdn\Image\ImageConverter;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ConversionResult;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;

/**
 * An ImageConverter that writes the small valid images of files/ instead of
 * converting: "variant.webp" (4x3) and "variant.avif" (8x8) for the
 * variants, "resized.png" (2x1) for a resize. An input whose content
 * contains "broken" is rejected, one that contains "busy" is refused as
 * transient; setUnavailable() makes every conversion transient, and
 * setCapabilities() what getCapabilities() reports.
 */
final class FakeImageConverter implements ImageConverter
{
    /**
     * The fixture images: format => [file, width, height].
     */
    private const OUTPUTS = [
        ImageFormat::WEBP => ['variant.webp', 4, 3],
        ImageFormat::AVIF => ['variant.avif', 8, 8],
        ImageFormat::PNG => ['resized.png', 2, 1],
    ];

    /**
     * @var list<array{string, string, int|null, string|null}>
     */
    private $calls = [];

    /**
     * @var Capabilities|null
     */
    private $capabilities;

    /**
     * @var bool
     */
    private $unavailable = false;

    /**
     * @var string|null
     */
    private $reportedFormat;

    /**
     * @var int
     */
    private $capabilityCalls = 0;

    public function __construct()
    {
        $this->capabilities = self::capabilities([ImageFormat::WEBP, ImageFormat::AVIF, ImageFormat::PNG]);
    }

    /**
     * Capabilities with the output formats $outputs.
     *
     * @param list<string> $outputs
     */
    public static function capabilities(array $outputs): Capabilities
    {
        return new Capabilities(
            [ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF],
            $outputs,
            [],
            ['gd'],
            IsolationLevel::NONE,
            [
                Capabilities::LIMIT_MAX_BYTES => 1048576,
                Capabilities::LIMIT_MAX_WIDTH => 4096,
                Capabilities::LIMIT_MAX_HEIGHT => 4096,
                Capabilities::LIMIT_MAX_MEGAPIXELS => 16,
                Capabilities::LIMIT_MAX_FRAMES => 1,
                Capabilities::LIMIT_MAX_TOTAL_MEGAPIXELS => 16,
            ]
        );
    }

    public function setCapabilities(?Capabilities $capabilities): void
    {
        $this->capabilities = $capabilities;
    }

    public function setUnavailable(bool $unavailable): void
    {
        $this->unavailable = $unavailable;
    }

    /**
     * Makes the converter report $format whatever it wrote: an output the
     * verification must refuse.
     */
    public function reportFormat(?string $format): void
    {
        $this->reportedFormat = $format;
    }

    public function getName(): string
    {
        return 'fake';
    }

    public function getCapabilities(): ?Capabilities
    {
        ++$this->capabilityCalls;

        return $this->capabilities;
    }

    public function convert(string $inputPath, string $outputPath, ConversionRequest $request, ?string $requestId = null): ConversionResult
    {
        $input = (string) file_get_contents($inputPath);
        $this->calls[] = [$request->getFormat(), $input, $request->getWidth(), $requestId];
        if ($this->unavailable || strpos($input, 'busy') !== false) {
            throw ConversionFailure::unavailable('fake', 'The fake converter is busy', 'concurrency_unavailable', 503);
        }
        if (strpos($input, 'broken') !== false) {
            throw ConversionFailure::rejected('fake', 'The fake converter refused the image', 'conversion_failed', 422);
        }
        $format = $request->getFormat() === ConversionRequest::FORMAT_ORIGINAL ? ImageFormat::PNG : $request->getFormat();
        list($file, $width, $height) = self::OUTPUTS[$format];
        copy(__DIR__ . '/files/' . $file, $outputPath);

        return new ConversionResult($outputPath, $this->reportedFormat ?? $format, $width, $height, (int) filesize($outputPath), 1, 'gd', 1, IsolationLevel::NONE, false);
    }

    /**
     * The conversions asked so far, oldest first: the format asked, the
     * input, the width and the request ID.
     *
     * @return list<array{string, string, int|null, string|null}>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

    /**
     * How many times getCapabilities() was called.
     */
    public function getCapabilityCalls(): int
    {
        return $this->capabilityCalls;
    }

    /**
     * The formats asked so far, oldest first.
     *
     * @return list<string>
     */
    public function getFormats(): array
    {
        return array_map(static function (array $call): string {
            return $call[0];
        }, $this->calls);
    }
}
