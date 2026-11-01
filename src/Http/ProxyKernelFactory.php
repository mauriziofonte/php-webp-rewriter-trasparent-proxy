<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Cache\NegativeCache;
use Mfonte\ProxyCdn\Cache\Purger;
use Mfonte\ProxyCdn\Cache\QueryStringPolicy;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Image\WebpConverter;
use Mfonte\ProxyCdn\Origin\MarkerFileCircuitBreaker;
use Mfonte\ProxyCdn\Origin\OriginFetcher;
use Mfonte\ProxyCdn\Security\PathSanitizer;
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
     * @param string             $publicDirectory the document root, where the cache entries are stored
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

        return new ProxyKernel(
            $config,
            new PathSanitizer(),
            new CacheKeyResolver($publicDirectory),
            QueryStringPolicy::fromConfig($config),
            new TrustedProxies($config->getTrustedProxies()),
            $storage,
            $negativeCache,
            new MarkerFileCircuitBreaker($storage, $config->getNetworkErrorTtl()),
            new Purger($storage, $negativeCache),
            $origin,
            $converter,
            $responses,
            new FileResponder($storage, $responses),
            $logger
        );
    }
}
