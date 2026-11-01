<?php

declare(strict_types=1);

/*
 * Front controller of the standalone proxy: the web server routes here every
 * request it cannot serve from the cache (see public/.htaccess). It builds
 * the kernel with ProxyKernelFactory and sends its response; a host
 * application can mount Mfonte\ProxyCdn\Http\ProxyKernel the same way.
 */

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Config\CompiledConfigCache;
use Mfonte\ProxyCdn\Config\ConfigLoader;
use Mfonte\ProxyCdn\Config\RuntimeSettings;
use Mfonte\ProxyCdn\Exception\InvalidConfigurationException;
use Mfonte\ProxyCdn\Http\ProxyKernelFactory;
use Mfonte\ProxyCdn\Http\ResponseFactory;
use Mfonte\ProxyCdn\Image\Legacy\LegacyConverterFactory;
use Mfonte\ProxyCdn\Observability\JsonLogger;
use Mfonte\ProxyCdn\Origin\HttpOriginFetcher;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Request;

// PHP errors must never reach an asset body; DEBUG turns them back on.
ini_set('display_errors', '0');
// expose_php would announce the PHP version on every response (finding P-23).
header_remove('X-Powered-By');

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
try {
    $kernel = ProxyKernelFactory::create(
        $config,
        __DIR__,
        $projectRoot . '/var',
        HttpOriginFetcher::fromConfig($config, $logger),
        LegacyConverterFactory::fromConfig($config, $storage, $logger),
        $logger
    );
    $response = $kernel->handle(Request::createFromGlobals());
} catch (\Throwable $exception) {
    // Finding P-21: an internal error is a 5xx, never an asset or a 404.
    $logger->error('unhandled exception', ['exception' => $exception]);
    $response = ResponseFactory::unexpectedError('Unexpected error');
}
$response->send();
