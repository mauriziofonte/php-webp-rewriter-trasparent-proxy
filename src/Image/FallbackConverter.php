<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image;

use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ConversionResult;
use Psr\Log\LoggerInterface;

/**
 * The conversion API, with local conversion as its fallback
 * (IMAGE_API_FALLBACK): a conversion the API cannot make now (it is
 * unreachable, its breaker is open, it refuses for lack of capacity) is
 * made by the library in this host. A conversion the API refused because
 * of the image is not tried again locally: the image would be refused
 * the same way.
 *
 * The capabilities are the API's while it can convert, else the local
 * ones.
 */
final class FallbackConverter implements ImageConverter
{
    /**
     * @var ImageConverter
     */
    private $primary;

    /**
     * @var ImageConverter
     */
    private $fallback;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(ImageConverter $primary, ImageConverter $fallback, LoggerInterface $logger)
    {
        $this->primary = $primary;
        $this->fallback = $fallback;
        $this->logger = $logger;
    }

    public function getName(): string
    {
        return $this->primary->getName();
    }

    public function convert(string $inputPath, string $outputPath, ConversionRequest $request, ?string $requestId = null): ConversionResult
    {
        try {
            return $this->primary->convert($inputPath, $outputPath, $request, $requestId);
        } catch (ConversionFailure $failure) {
            if (!$failure->isTransient()) {
                throw $failure;
            }
            $this->logger->notice('remote conversion unavailable, converting locally', $failure->logContext());
        }

        return $this->fallback->convert($inputPath, $outputPath, $request, $requestId);
    }

    public function getCapabilities(): ?Capabilities
    {
        return $this->primary->getCapabilities() ?? $this->fallback->getCapabilities();
    }
}
