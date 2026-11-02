<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Image\ImageConverter;
use Mfonte\ProxyCdn\Observability\HealthCheck;
use Mfonte\ProxyCdn\Origin\OriginFetcher;
use Psr\Log\LoggerInterface;

/**
 * Builds the EdgeKernel of the front controller around the ProxyKernel of
 * ProxyKernelFactory, with the same arguments: /healthz checks the cache
 * and state directories.
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
        LoggerInterface $logger
    ): EdgeKernel {
        $stateDirectory = rtrim($stateDirectory, '/');
        $kernel = ProxyKernelFactory::create(
            $config,
            $publicDirectory,
            $stateDirectory,
            $origin,
            $converter,
            $logger
        );

        return new EdgeKernel(
            $kernel,
            new HealthCheck(rtrim($publicDirectory, '/') . '/' . CacheKeyResolver::CACHE_DIRECTORY, $stateDirectory)
        );
    }
}
