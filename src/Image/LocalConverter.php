<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image;

use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ConversionResult;
use Mfonte\SecureImageConverter\Contract\ConverterInterface;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface as LibraryException;
use Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder;
use Psr\Log\LoggerInterface;

/**
 * Converts in this host with mfonte/secure-image-converter: input
 * validation before any decoder runs, the isolation level the library
 * detects (and caches) with its policy, backends chosen from their probed
 * capabilities, and output verification (see the library's README).
 *
 * The converter is built from the builder on first use, once per PHP
 * request: detecting the isolation and probing the backends cost little
 * after the first time, since the library caches both in a directory
 * private to the PHP user (it needs ext-posix to check that directory).
 * A builder that cannot build (a strict isolation policy the host cannot
 * meet) makes every conversion fail as unavailable, and
 * getCapabilities() return null.
 */
final class LocalConverter implements ImageConverter
{
    public const NAME = 'local';

    /**
     * @var SecureConverterBuilder
     */
    private $builder;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ConverterInterface|null
     */
    private $converter;

    /**
     * @param SecureConverterBuilder $builder every setting of the converter: limits, isolation, backends, options
     */
    public function __construct(SecureConverterBuilder $builder, LoggerInterface $logger)
    {
        $this->builder = $builder;
        $this->logger = $logger;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function convert(string $inputPath, string $outputPath, ConversionRequest $request, ?string $requestId = null): ConversionResult
    {
        try {
            return $this->converter()->convert($inputPath, $outputPath, $request);
        } catch (LibraryException $exception) {
            throw ConversionFailure::fromLibrary(self::NAME, $exception);
        }
    }

    public function getCapabilities(): ?Capabilities
    {
        try {
            return $this->converter()->getCapabilities();
        } catch (LibraryException $exception) {
            $this->logger->warning('local conversion unavailable', [
                'converter' => self::NAME,
                ConversionFailure::LOG_CONVERSION_ERROR_CODE => $exception->getErrorCode(),
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @throws LibraryException when the converter cannot be built
     */
    private function converter(): ConverterInterface
    {
        if ($this->converter === null) {
            $this->converter = $this->builder->build();
        }

        return $this->converter;
    }
}
