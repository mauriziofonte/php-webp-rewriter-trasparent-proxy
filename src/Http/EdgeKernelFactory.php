<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Cluster\PeerNotifier;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Image\ImageConverter;
use Mfonte\ProxyCdn\Observability\HealthCheck;
use Mfonte\ProxyCdn\Observability\MeteredOriginFetcher;
use Mfonte\ProxyCdn\Observability\Metrics;
use Mfonte\ProxyCdn\Observability\MetricsAuthenticator;
use Mfonte\ProxyCdn\Origin\OriginFetcher;
use Psr\Log\LoggerInterface;

/**
 * Builds the EdgeKernel of the front controller around the ProxyKernel of
 * ProxyKernelFactory, with the same arguments plus the Metrics of the
 * request: the origin fetches are counted (MeteredOriginFetcher), the
 * misses go through the miss rate limit and the Early Hints of the
 * configuration (OriginFetchGuards), /healthz
 * checks the cache and state directories, /metrics is served when
 * METRICS_KEYS is set, and accepted purges are fanned out to CLUSTER_PEERS
 * (none when it is empty).
 */
final class EdgeKernelFactory
{
    /**
     * @param string              $publicDirectory the document root (see ProxyKernelFactory::create())
     * @param string              $stateDirectory  the state directory, var/ of the project
     * @param ImageConverter|null $converter       null when no image variant is made
     */
    public static function create(
        Config $config,
        string $publicDirectory,
        string $stateDirectory,
        OriginFetcher $origin,
        ?ImageConverter $converter,
        LoggerInterface $logger,
        Metrics $metrics
    ): EdgeKernel {
        $stateDirectory = rtrim($stateDirectory, '/');
        $kernel = ProxyKernelFactory::create(
            $config,
            $publicDirectory,
            $stateDirectory,
            new MeteredOriginFetcher($origin, $metrics),
            $converter,
            $logger,
            OriginFetchGuards::fromConfig($config, $stateDirectory, $metrics, $logger)
        );

        return new EdgeKernel(
            $kernel,
            new HealthCheck(rtrim($publicDirectory, '/') . '/' . CacheKeyResolver::CACHE_DIRECTORY, $stateDirectory),
            $metrics,
            new MetricsAuthenticator($config->getOperations()->getMetricsKeys()),
            $logger,
            PeerNotifier::fromConfig($config, $logger)
        );
    }
}
