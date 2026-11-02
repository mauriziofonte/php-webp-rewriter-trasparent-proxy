<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image;

use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ConversionResult;

/**
 * Converts one image: in this host with the conversion library
 * (LocalConverter), through the conversion API v2 (RemoteConverter), or
 * the API with the library as its fallback (FallbackConverter).
 *
 * It speaks the contract objects of the library (ConversionRequest,
 * ConversionResult, Capabilities), so that parameter names and limits
 * have one source of truth. It is not the library's ConverterInterface:
 * a conversion carries the request ID of the proxy, for the logs of the
 * API (risk L-20), and every failure is one ConversionFailure that tells
 * whether the converter or the image failed, with the error codes of the
 * library and of the API kept apart.
 */
interface ImageConverter
{
    /**
     * Converts the untrusted image at $inputPath as $request asks and
     * writes the result to $outputPath, an absent file of an existing
     * private directory. The output is not verified yet: VariantConverter
     * checks it before anything is stored (finding C-11).
     *
     * @param string|null $requestId the ID of the client request, sent to the API in X-Request-Id; null for none
     *
     * @throws ConversionFailure when the conversion could not be made; no output is left at $outputPath
     */
    public function convert(string $inputPath, string $outputPath, ConversionRequest $request, ?string $requestId = null): ConversionResult;

    /**
     * What the converter can do now; null when it cannot convert at all
     * (the API is unreachable, or answers capabilities this library cannot
     * read). Never throws.
     */
    public function getCapabilities(): ?Capabilities;

    /**
     * The name of the converter in logs: "local" or "remote".
     */
    public function getName(): string;
}
