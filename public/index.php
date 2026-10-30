<?php

declare(strict_types=1);

/*
 * Front controller of the standalone proxy: the web server routes here every
 * request it cannot serve from the cache (see public/.htaccess). It builds
 * the object graph explicitly and sends the response of the kernel; a host
 * application can mount Mfonte\ProxyCdn\Http\ProxyKernel the same way.
 */

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Cache\NegativeCache;
use Mfonte\ProxyCdn\Cache\Purger;
use Mfonte\ProxyCdn\Config\CompiledConfigCache;
use Mfonte\ProxyCdn\Config\ConfigLoader;
use Mfonte\ProxyCdn\Config\RuntimeSettings;
use Mfonte\ProxyCdn\Exception\InvalidConfigurationException;
use Mfonte\ProxyCdn\Http\FileResponder;
use Mfonte\ProxyCdn\Http\ProxyKernel;
use Mfonte\ProxyCdn\Http\ResponseFactory;
use Mfonte\ProxyCdn\Image\Legacy\LegacyConverterFactory;
use Mfonte\ProxyCdn\Observability\JsonLogger;
use Mfonte\ProxyCdn\Origin\HttpOriginFetcher;
use Mfonte\ProxyCdn\Origin\MarkerFileCircuitBreaker;
use Mfonte\ProxyCdn\Security\PathSanitizer;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Request;

// PHP errors must never reach an asset body; DEBUG turns them back on.
ini_set('display_errors', '0');

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

$storage = new FilesystemStorage();
$logFile = $projectRoot . '/logs/proxy.log';

try {
    $config = (new ConfigLoader(
        $projectRoot . '/.env',
        new CompiledConfigCache($projectRoot . '/.config.cache.php', $storage)
    ))->load();
} catch (InvalidConfigurationException $exception) {
    (new JsonLogger($logFile, LogLevel::ERROR))->error('invalid configuration', ['errors' => $exception->getErrors()]);
    ResponseFactory::configurationError()->send();

    return;
}

(new RuntimeSettings())->apply($config);

$logger = new JsonLogger($logFile, $config->isDebug() ? LogLevel::DEBUG : LogLevel::NOTICE);
$negativeCache = new NegativeCache($storage, $config->getNegativeCacheTtl());
$responses = ResponseFactory::fromConfig($config);

$kernel = new ProxyKernel(
    $config,
    new PathSanitizer(),
    new CacheKeyResolver(__DIR__),
    $storage,
    $negativeCache,
    new MarkerFileCircuitBreaker($storage, $config->getNetworkErrorTtl()),
    new Purger($storage, $negativeCache),
    HttpOriginFetcher::fromConfig($config, $logger),
    LegacyConverterFactory::fromConfig($config, $storage, $logger),
    $responses,
    new FileResponder($storage, $responses),
    $logger
);

$kernel->handle(Request::createFromGlobals())->send();
