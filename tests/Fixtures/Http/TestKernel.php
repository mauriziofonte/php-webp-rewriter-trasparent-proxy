<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Http;

use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Http\ProxyKernel;
use Mfonte\ProxyCdn\Http\ProxyKernelFactory;
use Mfonte\ProxyCdn\Image\ImageConverter;
use Mfonte\ProxyCdn\Origin\OriginFetcher;
use Psr\Log\LoggerInterface;

/**
 * Builds a ProxyKernel the way public/index.php does, with the origin, the
 * converter and the logger of the test.
 */
final class TestKernel
{
    /**
     * Directory below the cache directory that stands for var/ of the
     * project: the kernel state (negative cache) lives there, and the test
     * removes it with the cache directory.
     */
    public const STATE_DIRECTORY = '.var';

    public static function create(
        Config $config,
        string $cacheDirectory,
        OriginFetcher $origin,
        ?ImageConverter $converter,
        LoggerInterface $logger
    ): ProxyKernel {
        return ProxyKernelFactory::create($config, $cacheDirectory, $cacheDirectory . '/' . self::STATE_DIRECTORY, $origin, $converter, $logger);
    }
}
