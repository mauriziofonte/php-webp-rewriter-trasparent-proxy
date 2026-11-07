<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Observability;

use Mfonte\ProxyCdn\Image\ConversionFailure;
use Mfonte\ProxyCdn\Image\ImageConverter;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ConversionResult;

/**
 * An ImageConverter that counts the outcome of every conversion and resize
 * of the one it wraps in Metrics (proxy_cdn_image_conversions_total), and
 * changes nothing else: the result, or the same ConversionFailure.
 */
final class MeteredImageConverter implements ImageConverter
{
    /**
     * The error code of a conversion refused while the circuit breaker of
     * the conversion API is open (RemoteConverter).
     */
    private const CIRCUIT_OPEN = 'circuit_open';

    /**
     * @var ImageConverter
     */
    private $converter;

    /**
     * @var Metrics
     */
    private $metrics;

    public function __construct(ImageConverter $converter, Metrics $metrics)
    {
        $this->converter = $converter;
        $this->metrics = $metrics;
    }

    public function convert(string $inputPath, string $outputPath, ConversionRequest $request, ?string $requestId = null): ConversionResult
    {
        try {
            $result = $this->converter->convert($inputPath, $outputPath, $request, $requestId);
        } catch (ConversionFailure $failure) {
            if ($failure->getErrorCode() === self::CIRCUIT_OPEN) {
                $outcome = Metrics::CONVERSION_CIRCUIT_OPEN;
            } else {
                $outcome = $failure->isTransient() ? Metrics::CONVERSION_UNAVAILABLE : Metrics::CONVERSION_REJECTED;
            }
            $this->metrics->recordImageConversion($this->converter->getName(), $outcome);

            throw $failure;
        }
        $this->metrics->recordImageConversion($this->converter->getName(), Metrics::RESULT_OK);

        return $result;
    }

    public function getCapabilities(): ?Capabilities
    {
        return $this->converter->getCapabilities();
    }

    public function getName(): string
    {
        return $this->converter->getName();
    }
}
