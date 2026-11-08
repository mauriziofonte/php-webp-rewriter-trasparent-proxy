<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Cache\CacheStorage;
use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Cache\NegativeCache;
use Mfonte\ProxyCdn\Cache\Purger;
use Mfonte\ProxyCdn\Cache\QueryStringPolicy;
use Mfonte\ProxyCdn\Cache\RequestCoalescer;
use Mfonte\ProxyCdn\Compression\Precompressor;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Image\ImageConverter;
use Mfonte\ProxyCdn\Image\ImageConverterFactory;
use Mfonte\ProxyCdn\Image\SignedUrlVerifier;
use Mfonte\ProxyCdn\Origin\OriginCircuitBreaker;
use Mfonte\ProxyCdn\Origin\OriginFetcher;
use Mfonte\ProxyCdn\Security\PathSanitizer;
use Mfonte\ProxyCdn\Security\PurgeAuthenticator;
use Mfonte\SecureImageConverter\Concurrency\KeyedLock;
use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the ProxyKernel object graph from the configuration, the way the
 * front controller, a host application and the tests all need it: they
 * choose the directories, the origin fetcher, the converter and the
 * logger; the rest follows from the configuration.
 */
final class ProxyKernelFactory
{
    /**
     * Seconds a request waits for the leader of its entry beyond
     * UPSTREAM_TIMEOUT, for storing and compressing; the worst case of the
     * image conversions (ImageConverterFactory::worstCaseSeconds()) is
     * added on top (coalescingTimeout()).
     */
    private const COALESCING_MARGIN = 30;

    /**
     * The optional $guard runs before each miss is fetched
     * (OriginFetchGuards::fromConfig(), PRX-9); null for none.
     *
     * @param string              $publicDirectory the document root; the cache entries are stored in its
     *                                             "_cache" directory (CacheKeyResolver::CACHE_DIRECTORY)
     * @param string              $stateDirectory  a writable directory outside the document root for the
     *                                             proxy state (var/ of the project)
     * @param ImageConverter|null $converter       the converter of IMAGE_CONVERTER
     *                                             (ImageConverterFactory::create()); null when no variant is made
     */
    public static function create(
        Config $config,
        string $publicDirectory,
        string $stateDirectory,
        OriginFetcher $origin,
        ?ImageConverter $converter,
        LoggerInterface $logger,
        ?OriginFetchGuard $guard = null
    ): ProxyKernel {
        $stateDirectory = rtrim($stateDirectory, '/');
        $storage = new FilesystemStorage();
        $negativeCache = new NegativeCache($storage, $stateDirectory . '/negative', $config->getNegativeCacheTtl());
        $responses = ResponseFactory::fromConfig($config);
        $cacheDirectory = rtrim($publicDirectory, '/') . '/' . CacheKeyResolver::CACHE_DIRECTORY;
        $variants = $converter === null ? null : ImageConverterFactory::variants($config, $converter, $cacheDirectory, $stateDirectory, $storage, $logger);
        $coalescer = self::coalescer($config, $stateDirectory, $converter !== null, $logger, $storage);

        return new ProxyKernel(
            $config,
            new PathSanitizer(),
            new CacheKeyResolver($cacheDirectory),
            QueryStringPolicy::fromConfig($config),
            new TrustedProxies($config->getTrustedProxies()),
            PurgeAuthenticator::fromConfig($config),
            $storage,
            $negativeCache,
            new OriginCircuitBreaker($stateDirectory . '/breaker', $config->getNetworkErrorTtl(), $config->getCircuitBreakerThreshold()),
            new Purger($storage, $negativeCache, $coalescer),
            $origin,
            $variants,
            new Precompressor($storage, $config->shouldPrecompress()),
            $responses,
            new FileResponder($storage, $responses, $config->getSendfile(), $cacheDirectory, $logger),
            $coalescer,
            $logger,
            $converter === null ? null : SignedUrlVerifier::fromConfig($config),
            $guard
        );
    }

    /**
     * The request coalescer of the kernel, over the lock and outcome
     * directories of $stateDirectory; also what cache:purge gives its
     * Purger, so that a purge from the command line forgets the outcomes
     * the requests share, as a PURGE does.
     *
     * @param bool $converts whether a converter is configured (coalescingTimeout())
     */
    public static function coalescer(Config $config, string $stateDirectory, bool $converts, LoggerInterface $logger, CacheStorage $storage): RequestCoalescer
    {
        $stateDirectory = rtrim($stateDirectory, '/');

        return new RequestCoalescer(self::lock($stateDirectory . '/locks', $logger), self::coalescingTimeout($config, $converts), $logger, $storage, $stateDirectory . '/coalescing', self::outcomeWindow($config));
    }

    /**
     * Seconds during which the coalescer shares an outcome:
     * RequestCoalescer::OUTCOME_WINDOW, or NETWORK_ERROR_TTL when it is
     * shorter. The requests of a URL that keeps failing fetch it once per
     * window, so the circuit breaker counts one failure per window, and
     * it starts the count again after a pause longer than
     * NETWORK_ERROR_TTL: a longer window would keep one failing URL from
     * ever opening it.
     */
    public static function outcomeWindow(Config $config): int
    {
        $ttl = $config->getNetworkErrorTtl();

        return $ttl > 0 ? min(RequestCoalescer::OUTCOME_WINDOW, $ttl) : RequestCoalescer::OUTCOME_WINDOW;
    }

    /**
     * Seconds a request waits for the request that fetches its entry:
     * the fetch (UPSTREAM_TIMEOUT), storing and compressing
     * (COALESCING_MARGIN), and with a converter the resize made before
     * the answer (ImageConverterFactory::worstCaseSeconds()); the variants
     * are made after the answer and count for nothing. 60 s with the
     * defaults. The read timeout of the web server towards PHP must be
     * above it (config/: 300 s).
     */
    public static function coalescingTimeout(Config $config, bool $converts): int
    {
        return $config->getUpstreamTimeout() + self::COALESCING_MARGIN + ($converts ? ImageConverterFactory::worstCaseSeconds($config) : 0);
    }

    /**
     * The keyed lock of origin fetches, in a lock directory of the state
     * directory that only the proxy user can write; null, and requests are
     * not coalesced, when the library refuses the directory (for example a
     * parent writable by other users).
     */
    private static function lock(string $directory, LoggerInterface $logger): ?KeyedLock
    {
        try {
            return new KeyedLock(new LockDirectory($directory), RequestCoalescer::LOCK_NAME);
        } catch (ExceptionInterface $exception) {
            $logger->warning('request coalescing unavailable', ['directory' => $directory, 'error' => $exception->getMessage()]);

            return null;
        }
    }
}
