<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image;

use Mfonte\ProxyCdn\Cache\CacheStorage;
use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Origin\OriginCircuitBreaker;
use Mfonte\SecureImageConverter\Backend\BackendOptions;
use Mfonte\SecureImageConverter\Concurrency\KeyedLock;
use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface as LibraryException;
use Mfonte\SecureImageConverter\Isolation\IsolationDetector;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Pipeline\ConverterOptions;
use Mfonte\SecureImageConverter\Pipeline\ProcessingBudget;
use Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder;
use Psr\Log\LoggerInterface;

/**
 * Builds the ImageConverter of IMAGE_CONVERTER, and the VariantConverter
 * the kernel uses, with their state below the state directory (var/ of
 * the project):
 *
 *     var/converter-cache/    what the library detected and probed
 *                             (isolation level, backend capabilities),
 *                             private to the PHP user; needs ext-posix
 *     var/convert/            the private directories of the library's
 *                             conversion processes
 *     var/conversion-api/     the capabilities of the API and its circuit
 *                             breaker
 *     var/conversion-failures/ the markers of failed conversions
 *
 * and the private directories of the converted files in the ".work"
 * directory of the cache root, on the filesystem of the cache.
 */
final class ImageConverterFactory
{
    /**
     * The directory of the private conversion directories, below the cache
     * root: a name no host and no request path can take.
     */
    public const WORK_DIRECTORY = '.work';

    /**
     * Seconds added to the time of the conversions of one entry in the
     * coalescing timeout, for the discovery of the API capabilities.
     */
    private const DISCOVERY_SECONDS = RemoteConverter::DISCOVERY_TIMEOUT;

    /**
     * The converter of IMAGE_CONVERTER; null for "none".
     *
     * @param string $stateDirectory the state directory of the proxy (var/ of the project)
     */
    public static function create(Config $config, string $stateDirectory, LoggerInterface $logger, ?CacheStorage $storage = null): ?ImageConverter
    {
        $stateDirectory = rtrim($stateDirectory, '/');
        switch ($config->getImageConverter()) {
            case Config::CONVERTER_LOCAL:
                return self::local($config, $stateDirectory, $logger);
            case Config::CONVERTER_REMOTE:
                $remote = self::remote($config, $stateDirectory, $logger, $storage ?? new FilesystemStorage());
                if ($remote === null) {
                    return null;
                }

                return $config->shouldFallBackToLocalConversion()
                    ? new FallbackConverter($remote, self::local($config, $stateDirectory, $logger), $logger)
                    : $remote;
            default:
                return null;
        }
    }

    /**
     * The VariantConverter of $converter for the cache below
     * $cacheDirectory.
     */
    public static function variants(Config $config, ImageConverter $converter, string $cacheDirectory, string $stateDirectory, CacheStorage $storage, LoggerInterface $logger): VariantConverter
    {
        return new VariantConverter(
            $converter,
            FormatNegotiator::fromConfig($config),
            new ConversionFailures($storage, rtrim($stateDirectory, '/') . '/conversion-failures'),
            $storage,
            rtrim($cacheDirectory, '/') . '/' . self::WORK_DIRECTORY,
            $config->getNetworkErrorTtl(),
            $config->getUpstreamMaxSize(),
            $logger
        );
    }

    /**
     * The longest time, in seconds, that a request may convert before it
     * answers, while the requests for the same entry wait for it: the
     * resized image of a resize URL, within IMAGE_CONVERSION_TIMEOUT (plus
     * the connect timeout and one discovery of the capabilities for the
     * API, plus a local conversion when the API may fall back to one). The
     * AVIF and WEBP variants are made after the response, so they count
     * for nothing; 0 without converter, or without RESIZE_WIDTHS.
     */
    public static function worstCaseSeconds(Config $config): int
    {
        $converter = $config->getImageConverter();
        if ($converter === Config::CONVERTER_NONE || $config->getResizeWidths() === []) {
            return 0;
        }
        $timeout = $config->getImageConversionTimeout();
        if ($converter === Config::CONVERTER_LOCAL) {
            return $timeout;
        }
        $remote = $timeout + $config->getUpstreamConnectTimeout() + self::DISCOVERY_SECONDS + $config->getUpstreamConnectTimeout();

        return $remote + ($config->shouldFallBackToLocalConversion() ? $timeout : 0);
    }

    private static function local(Config $config, string $stateDirectory, LoggerInterface $logger): LocalConverter
    {
        $cacheDirectory = self::privateDirectory($stateDirectory . '/converter-cache');
        $workDirectory = self::privateDirectory($stateDirectory . '/convert');
        $timeout = (float) $config->getImageConversionTimeout();
        $budget = new ProcessingBudget();
        $budget = $budget->withMinSeconds(min($budget->getMinSeconds(), $timeout))->withMaxSeconds($timeout);
        $options = (new ConverterOptions())->withBudget($budget)->withMaxOutputBytes($config->getUpstreamMaxSize());
        if ($workDirectory !== null) {
            $options = $options->withWorkingDirectoryParent($workDirectory);
        }

        $backendOptions = (new BackendOptions())->withLogger($logger);
        $builder = new SecureConverterBuilder();
        try {
            $builder = $builder
                ->withBackendOptions($backendOptions->withCapabilityCache($cacheDirectory !== null, $cacheDirectory))
                ->withIsolationDetector(new IsolationDetector(new ProcessOptions(), $cacheDirectory, $cacheDirectory === null ? 0 : IsolationDetector::DEFAULT_CACHE_TTL_SECONDS, $logger))
                ->withConverterOptions($options);
        } catch (LibraryException $exception) {
            $logger->warning('local conversion settings refused, using the library defaults', ['reason' => $exception->getMessage()]);
        }

        return new LocalConverter($builder, $logger);
    }

    private static function remote(Config $config, string $stateDirectory, LoggerInterface $logger, CacheStorage $storage): ?RemoteConverter
    {
        $url = $config->getImageApiUrl();
        $key = $config->getImageApiKey();
        if ($url === null || $key === null) {
            return null;
        }
        $directory = $stateDirectory . '/conversion-api';
        $userAgent = $config->getUpstreamUserAgent() ?? HttpClientOptions::DEFAULT_USER_AGENT;

        return new RemoteConverter(
            $url,
            $key,
            new ApiClient((float) $config->getUpstreamConnectTimeout(), (float) $config->getImageConversionTimeout(), $config->getImageApiCaFile(), $userAgent),
            new OriginCircuitBreaker($directory . '/breaker', $config->getNetworkErrorTtl(), $config->getCircuitBreakerThreshold()),
            new OriginCircuitBreaker($directory . '/breaker', $config->getNetworkErrorTtl(), 1),
            new CapabilityCache($storage, $directory, $url),
            $config->getNetworkErrorTtl(),
            $config->getUpstreamMaxSize(),
            $logger,
            self::discoveryLock($stateDirectory . '/locks', $logger)
        );
    }

    /**
     * The lock of the discovery of the conversion API, in the lock
     * directory of the state directory; null, and the requests discover
     * without it, when the library refuses the directory.
     */
    private static function discoveryLock(string $directory, LoggerInterface $logger): ?KeyedLock
    {
        try {
            return new KeyedLock(new LockDirectory($directory), RemoteConverter::DISCOVERY_LOCK_NAME);
        } catch (LibraryException $exception) {
            $logger->warning('conversion API discovery lock unavailable', ['directory' => $directory, 'reason' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * $directory, created with mode 0700 when missing, as the absolute,
     * normalised path the library requires; null when it cannot be had.
     */
    private static function privateDirectory(string $directory): ?string
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        $real = realpath($directory);

        return $real === false || !is_dir($real) ? null : $real;
    }
}
