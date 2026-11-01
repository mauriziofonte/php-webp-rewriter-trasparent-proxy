<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Cache\NegativeCache;
use Mfonte\ProxyCdn\Cache\Purger;
use Mfonte\ProxyCdn\Cache\QueryStringPolicy;
use Mfonte\ProxyCdn\Cache\RequestCoalescer;
use Mfonte\ProxyCdn\Compression\Precompressor;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Image\WebpConverter;
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
     * UPSTREAM_TIMEOUT, for storing, compressing and converting.
     */
    private const COALESCING_MARGIN = 30;

    /**
     * @param string             $publicDirectory the document root; the cache entries are stored in its
     *                                            "_cache" directory (CacheKeyResolver::CACHE_DIRECTORY)
     * @param string             $stateDirectory  a writable directory outside the document root for the
     *                                            proxy state (var/ of the project)
     * @param WebpConverter|null $converter       null when no WebP variant is made
     */
    public static function create(
        Config $config,
        string $publicDirectory,
        string $stateDirectory,
        OriginFetcher $origin,
        ?WebpConverter $converter,
        LoggerInterface $logger
    ): ProxyKernel {
        $stateDirectory = rtrim($stateDirectory, '/');
        $storage = new FilesystemStorage();
        $negativeCache = new NegativeCache($storage, $stateDirectory . '/negative', $config->getNegativeCacheTtl());
        $responses = ResponseFactory::fromConfig($config);
        $cacheDirectory = rtrim($publicDirectory, '/') . '/' . CacheKeyResolver::CACHE_DIRECTORY;

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
            new Purger($storage, $negativeCache),
            $origin,
            $converter,
            new Precompressor($storage, $config->shouldPrecompress()),
            $responses,
            new FileResponder($storage, $responses, $config->getSendfile(), $cacheDirectory),
            new RequestCoalescer(self::lock($stateDirectory . '/locks', $logger), $config->getUpstreamTimeout() + self::COALESCING_MARGIN, $logger, $storage, $stateDirectory . '/coalescing'),
            $logger
        );
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
