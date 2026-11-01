<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Http;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Cache\NegativeCache;
use Mfonte\ProxyCdn\Cache\Purger;
use Mfonte\ProxyCdn\Cache\QueryStringPolicy;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Http\FileResponder;
use Mfonte\ProxyCdn\Http\ProxyKernel;
use Mfonte\ProxyCdn\Http\ResponseFactory;
use Mfonte\ProxyCdn\Image\WebpConverter;
use Mfonte\ProxyCdn\Origin\MarkerFileCircuitBreaker;
use Mfonte\ProxyCdn\Origin\OriginFetcher;
use Mfonte\ProxyCdn\Security\PathSanitizer;
use Psr\Log\LoggerInterface;

/**
 * Builds a ProxyKernel the way public/index.php does, with the origin, the
 * converter and the logger of the test.
 */
final class TestKernel
{
    public static function create(
        Config $config,
        string $cacheDirectory,
        OriginFetcher $origin,
        ?WebpConverter $converter,
        LoggerInterface $logger
    ): ProxyKernel {
        $storage = new FilesystemStorage();
        $negativeCache = new NegativeCache($storage, $config->getNegativeCacheTtl());
        $responses = ResponseFactory::fromConfig($config);

        return new ProxyKernel(
            $config,
            new PathSanitizer(),
            new CacheKeyResolver($cacheDirectory),
            QueryStringPolicy::fromConfig($config),
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
