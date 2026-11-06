<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image;

use Mfonte\ProxyCdn\Cache\CacheLocation;
use Mfonte\ProxyCdn\Cache\CacheStorage;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ConversionResult;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface as LibraryException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Validation\FormatSniffer;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
use Mfonte\SecureImageConverter\Validation\Limits;
use Psr\Log\LoggerInterface;

/**
 * Makes the AVIF and WEBP variants of the raster entries, and the resized
 * images of the signed resize URLs, with an ImageConverter, and stores
 * only what it verified (finding C-11: 2.x stored whatever the API sent).
 *
 * Each conversion writes to a private directory (mode 0700) of the work
 * directory, which lives in the cache root so that the result is moved
 * into place with one rename() on the same filesystem: the web server
 * never sees a partial file. Before the move the output is checked: its
 * format, sniffed from its content and parsed from its header by the
 * library's ImageInspector, must be the expected one, and its dimensions
 * and frame count those the converter reported. The file gets the mode of
 * the cache files (0644).
 *
 * A conversion that fails never fails the request (finding P-10): the
 * original is served instead, the failure is logged with its error codes
 * (ConversionFailure::logContext()), and a marker (ConversionFailures)
 * keeps the requests PHP serves from converting the same variant again on
 * every hit: for PERMANENT_RETRY seconds when the image itself failed,
 * for the transient TTL (NETWORK_ERROR_TTL) when the converter could not
 * convert now. A variant that would not be smaller than the original (the
 * library then answers the original, finding P-12) is remembered the
 * same way, as an expected outcome. Nothing it does throws.
 */
final class VariantConverter
{
    /**
     * Seconds a conversion that failed because of the image is not tried
     * again on a hit.
     */
    public const PERMANENT_RETRY = 86400;

    /**
     * Seconds a format the converter cannot produce (no AVIF encoder, as
     * with GD alone) is not asked again on a hit: the time the
     * capabilities of the conversion API are kept (CapabilityCache).
     */
    public const UNSUPPORTED_RETRY = CapabilityCache::SUCCESS_TTL;

    /**
     * resize(): the resized image is at the target.
     */
    public const RESIZED = 'resized';

    /**
     * resize(): the image cannot be resized as asked; trying again would
     * fail the same way.
     */
    public const REJECTED = 'rejected';

    /**
     * resize(): the converter cannot resize now.
     */
    public const UNAVAILABLE = 'unavailable';

    /**
     * The formats the outputs are parsed as by the verification.
     */
    private const OUTPUT_FORMATS = [ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::BMP, ImageFormat::WEBP, ImageFormat::AVIF];

    /**
     * @var ImageConverter
     */
    private $converter;

    /**
     * @var FormatNegotiator
     */
    private $negotiator;

    /**
     * @var ConversionFailures
     */
    private $failures;

    /**
     * @var CacheStorage
     */
    private $storage;

    /**
     * @var string
     */
    private $workDirectory;

    /**
     * @var int
     */
    private $transientRetry;

    /**
     * @var int
     */
    private $maxOutputBytes;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param string $workDirectory  the directory of the private conversion directories, on the filesystem of the
     *                               cache and never served (a "." directory of the cache root)
     * @param int    $transientRetry seconds a conversion the converter could not make now is not tried again on a hit
     * @param int    $maxOutputBytes the largest output stored (UPSTREAM_MAX_SIZE)
     */
    public function __construct(
        ImageConverter $converter,
        FormatNegotiator $negotiator,
        ConversionFailures $failures,
        CacheStorage $storage,
        string $workDirectory,
        int $transientRetry,
        int $maxOutputBytes,
        LoggerInterface $logger
    ) {
        $this->converter = $converter;
        $this->negotiator = $negotiator;
        $this->failures = $failures;
        $this->storage = $storage;
        $this->workDirectory = rtrim($workDirectory, '/');
        $this->transientRetry = $transientRetry;
        $this->maxOutputBytes = $maxOutputBytes;
        $this->logger = $logger;
    }

    public function getNegotiator(): FormatNegotiator
    {
        return $this->negotiator;
    }

    /**
     * Makes every enabled variant of the entry of $location from $source,
     * replacing the variants of an earlier version; the failure markers of
     * the entry are forgotten first, since $source is a new version. Once
     * the converter fails as unavailable (a timeout, an open breaker), the
     * remaining formats are not tried in this request but remembered as
     * transient failures too: the request that stores the entry waits for
     * one conversion timeout at most, not one per format.
     *
     * @param string      $source    the image: the body about to be stored, or the stored entry
     * @param string|null $requestId the ID of the client request, for the API
     */
    public function convertAll(CacheLocation $location, string $source, ?string $requestId): void
    {
        $unavailable = false;
        foreach ($this->negotiator->getEnabledFormats() as $format) {
            $variant = $location->getVariantFile($format);
            if ($unavailable) {
                $this->failures->remember($variant, $this->transientRetry);
                continue;
            }
            $this->failures->forget($variant);
            $failure = $this->make($location, $format, $source, $requestId);
            $unavailable = $failure !== null && $failure->isTransient();
        }
    }

    /**
     * Makes the variant $format of the stored entry of $location, on a hit
     * PHP serves, unless it is not enabled or a failure of it is
     * remembered. Whether the variant exists afterwards.
     *
     * @param string|null $requestId the ID of the client request, for the API
     */
    public function convertOnHit(CacheLocation $location, string $format, ?string $requestId): bool
    {
        $variant = $location->getVariantFile($format);
        if (!$this->negotiator->isEnabled($format) || $this->failures->isRemembered($variant)) {
            return $this->storage->isFile($variant);
        }

        $this->make($location, $format, $location->getFile(), $requestId);

        return $this->storage->isFile($variant);
    }

    /**
     * Stores $source resized to $width pixels wide (at most: a smaller
     * image is not enlarged), in its own format, as the entry of
     * $location, the entry of a resize URL, with its variants: the
     * resized image is made and verified in a private directory, its
     * variants are made from it, and it is moved into place last, so that
     * the web server never serves it without its variants. One of
     * RESIZED, REJECTED and UNAVAILABLE; nothing is stored unless RESIZED.
     *
     * @param string|null $requestId the ID of the client request, for the API
     */
    public function resizeEntry(CacheLocation $location, string $source, int $width, ?string $requestId): string
    {
        $logPath = SignedUrlVerifier::mask('/' . $location->getRelativePath());
        $directory = $this->privateDirectory();
        if ($directory === null) {
            $this->logger->warning('image resize failed', ['path' => $logPath, 'width' => $width, ConversionFailure::LOG_ERROR_CODE => 'work_directory', 'reason' => 'The work directory of the conversions cannot be created']);

            return self::UNAVAILABLE;
        }
        $resized = $directory . '/resized';
        try {
            $outcome = $this->resize($source, $resized, $width, $logPath, $requestId);
            if ($outcome !== self::RESIZED) {
                return $outcome;
            }
            $this->convertAll($location, $resized, $requestId);
            if (!$this->storage->ensureDirectory($location->getDirectory()) || !@rename($resized, $location->getFile())) {
                $this->logger->warning('image resize failed', ['path' => $logPath, 'width' => $width, ConversionFailure::LOG_ERROR_CODE => 'cache_write', 'reason' => 'The resized image cannot be moved into the cache']);

                return self::UNAVAILABLE;
            }

            return self::RESIZED;
        } finally {
            if (is_file($resized)) {
                @unlink($resized);
            }
            @rmdir($directory);
        }
    }

    /**
     * Writes $source resized to $width pixels wide to $target. One of
     * RESIZED, REJECTED and UNAVAILABLE.
     */
    private function resize(string $source, string $target, int $width, string $logPath, ?string $requestId): string
    {
        $format = self::formatOf($source);
        if ($format === null) {
            $this->logger->warning('image resize failed', ['path' => $logPath, 'width' => $width, 'reason' => 'The image is of no known format'] + ConversionFailure::codeContext(ErrorCode::UNSUPPORTED_FORMAT));

            return self::REJECTED;
        }
        $request = (new ConversionRequest(ConversionRequest::FORMAT_ORIGINAL))->withWidth($width);
        $failure = $this->run($source, $target, $request, $format, $requestId);
        if ($failure === null) {
            return self::RESIZED;
        }
        $this->logger->warning('image resize failed', ['path' => $logPath, 'width' => $width] + $failure->logContext());

        return $failure->isTransient() ? self::UNAVAILABLE : self::REJECTED;
    }

    /**
     * Makes one variant. A failure is logged, remembered and returned;
     * null when the variant was made, or is not one this converter makes.
     */
    private function make(CacheLocation $location, string $format, string $source, ?string $requestId): ?ConversionFailure
    {
        $variant = $location->getVariantFile($format);
        $path = SignedUrlVerifier::mask('/' . $location->getRelativePath());
        $capabilities = $this->converter->getCapabilities();
        if ($capabilities === null) {
            $failure = ConversionFailure::unavailable($this->converter->getName(), 'The converter is unavailable', 'converter_unavailable');
        } elseif (!$capabilities->supportsOutput($format)) {
            // Not a failure: this converter makes no such variant. It is
            // remembered all the same, so that the hits PHP serves to the
            // clients that accept the format do not ask the converter
            // (and build it, or discover the API) on every request.
            $this->logger->debug('variant format not available', ['path' => $path, 'format' => $format, 'converter' => $this->converter->getName()]);
            $this->failures->remember($variant, self::UNSUPPORTED_RETRY);

            return null;
        } else {
            $failure = $this->run($source, $variant, new ConversionRequest($format), $format, $requestId);
        }
        if ($failure === null) {
            return null;
        }

        if ($failure->getErrorCode() === 'not_smaller') {
            $this->logger->info('variant not smaller than the original', ['path' => $path, 'format' => $format] + $failure->logContext());
        } else {
            $this->logger->warning('image conversion failed, the original is served', ['path' => $path, 'format' => $format] + $failure->logContext());
        }
        $this->failures->remember($variant, $failure->isTransient() ? $this->transientRetry : self::PERMANENT_RETRY);

        return $failure;
    }

    /**
     * Converts $source as $request asks into a private directory, verifies
     * the output, and moves it to $target. Null on success, else the
     * failure; nothing is left at $target on failure.
     */
    private function run(string $source, string $target, ConversionRequest $request, string $expectedFormat, ?string $requestId): ?ConversionFailure
    {
        $name = $this->converter->getName();
        $directory = $this->privateDirectory();
        if ($directory === null) {
            return ConversionFailure::unavailable($name, 'The work directory of the conversions cannot be created', 'work_directory');
        }
        $output = $directory . '/output';
        try {
            $result = $this->converter->convert($source, $output, $request, $requestId);
            $this->verify($result, $output, $expectedFormat);
            @chmod($output, 0644);
            if (!$this->storage->ensureDirectory(dirname($target)) || !@rename($output, $target)) {
                return ConversionFailure::unavailable($name, 'The converted image cannot be moved into the cache', 'cache_write');
            }

            return null;
        } catch (ConversionFailure $failure) {
            return $failure;
        } catch (\Throwable $throwable) {
            return ConversionFailure::unavailable($name, 'Unexpected ' . get_class($throwable) . ' during the conversion', 'unexpected', 0, $throwable);
        } finally {
            if (is_file($output)) {
                @unlink($output);
            }
            @rmdir($directory);
        }
    }

    /**
     * Checks that the output is an image of $expectedFormat with the
     * dimensions and frame count of $result (finding C-11).
     *
     * @throws ConversionFailure when it is not
     */
    private function verify(ConversionResult $result, string $output, string $expectedFormat): void
    {
        $name = $this->converter->getName();
        if ($result->getOutputFormat() !== $expectedFormat) {
            if ($result->isPassthrough()) {
                throw ConversionFailure::rejected($name, 'The ' . $expectedFormat . ' output is not smaller than the original', 'not_smaller');
            }

            throw ConversionFailure::rejected($name, 'The converter produced ' . $result->getOutputFormat() . ' instead of ' . $expectedFormat, ErrorCode::OUTPUT_VERIFICATION_FAILED);
        }
        if (self::formatOf($output) !== $expectedFormat) {
            throw ConversionFailure::rejected($name, 'The output is not a ' . $expectedFormat . ' image', ErrorCode::OUTPUT_VERIFICATION_FAILED);
        }
        $limits = (new Limits())
            ->withMaxBytes(max(1, $this->maxOutputBytes))
            ->withMaxWidth(ConversionRequest::DIMENSION_MAX)
            ->withMaxHeight(ConversionRequest::DIMENSION_MAX)
            ->withMaxMegapixels(270)
            ->withMaxTotalMegapixels(Limits::DEFAULT_MAX_TOTAL_MEGAPIXELS * 10)
            ->withMaxFrames(Limits::DEFAULT_MAX_FRAMES * 10);
        try {
            $info = (new ImageInspector($limits, self::OUTPUT_FORMATS))->inspect($output);
        } catch (LibraryException $exception) {
            throw ConversionFailure::rejected($name, 'The output is not a valid image: ' . $exception->getMessage(), ErrorCode::OUTPUT_VERIFICATION_FAILED, 0, $exception);
        }
        if ($info->getFormat() !== $expectedFormat || $info->getWidth() !== $result->getWidth()
            || $info->getHeight() !== $result->getHeight() || $info->getFrameCount() !== $result->getFrameCount()
        ) {
            throw ConversionFailure::rejected($name, 'The output does not match what the converter reported', ErrorCode::OUTPUT_VERIFICATION_FAILED);
        }
    }

    /**
     * A new private directory (mode 0700) of the work directory; null when
     * it cannot be created. The parents of the work directory, the cache
     * root among them, are created as the cache creates its directories
     * (CacheStorage::ensureDirectory(), mode 0755): the web server serves
     * the cache root, and must be able to enter it even when a conversion
     * creates it first. Only the work directory itself is private.
     */
    private function privateDirectory(): ?string
    {
        if (!is_dir($this->workDirectory)
            && (!$this->storage->ensureDirectory(dirname($this->workDirectory)) || !@mkdir($this->workDirectory, 0700))
            && !is_dir($this->workDirectory)
        ) {
            return null;
        }
        $directory = $this->workDirectory . '/' . bin2hex(random_bytes(8));

        return @mkdir($directory, 0700) ? $directory : null;
    }

    /**
     * The format of the image at $path, sniffed from its content; null when
     * it is not an image of a known format.
     */
    private static function formatOf(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        // fread() fails only on a read error: then nothing is detected.
        $head = (string) fread($handle, FormatSniffer::HEAD_LENGTH);
        fclose($handle);

        return (new FormatSniffer())->detect($head);
    }
}
