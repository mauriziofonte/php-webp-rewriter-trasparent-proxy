<?php

declare(strict_types=1);

/*
 * Front controller of the standalone proxy: the web server routes here every
 * request it cannot serve from the cache (see public/.htaccess). It builds
 * the kernel with EdgeKernelFactory (ProxyKernel with /healthz and the
 * request ID) and sends its response; a host application can mount
 * Mfonte\ProxyCdn\Http\ProxyKernel or EdgeKernel the same way. Every log
 * entry of the request carries its ID (LOG_FILE, LOG_LEVEL).
 */

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Config\CompiledConfigCache;
use Mfonte\ProxyCdn\Config\ConfigLoader;
use Mfonte\ProxyCdn\Config\RuntimeSettings;
use Mfonte\ProxyCdn\Exception\InvalidConfigurationException;
use Mfonte\ProxyCdn\Http\EdgeKernelFactory;
use Mfonte\ProxyCdn\Http\RequestId;
use Mfonte\ProxyCdn\Http\ResponseFactory;
use Mfonte\ProxyCdn\Image\ImageConverterFactory;
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
$request = Request::createFromGlobals();
$requestId = RequestId::of($request);

try {
    $config = (new ConfigLoader(
        $projectRoot . '/.env',
        new CompiledConfigCache($projectRoot . '/.config.cache.php', $storage)
    ))->load();
} catch (InvalidConfigurationException $exception) {
    (new JsonLogger($logFile, LogLevel::ERROR, JsonLogger::DEFAULT_CHANNEL, $requestId))->error('invalid configuration', ['errors' => $exception->getErrors()]);
    $response = ResponseFactory::configurationError();
    $response->headers->set(RequestId::HEADER, $requestId);
    $response->send();

    return;
}

(new RuntimeSettings())->apply($config);

$operations = $config->getOperations();
$logger = new JsonLogger(
    $operations->getLogFile() ?? $logFile,
    $operations->getLogLevel() ?? ($config->isDebug() ? LogLevel::DEBUG : LogLevel::NOTICE),
    JsonLogger::DEFAULT_CHANNEL,
    $requestId
);
$kernel = null;
try {
    $kernel = EdgeKernelFactory::create(
        $config,
        __DIR__,
        $projectRoot . '/var',
        HttpOriginFetcher::fromConfig($config, $logger),
        ImageConverterFactory::create($config, $projectRoot . '/var', $logger, $storage),
        $logger
    );
    $response = $kernel->handle($request);
} catch (\Throwable $exception) {
    // Finding P-21: an internal error is a 5xx, never an asset or a 404.
    $logger->error('unhandled exception', ['exception' => $exception]);
    $response = ResponseFactory::unexpectedError('Unexpected error');
    $response->headers->set(RequestId::HEADER, $requestId);
}
$response->send();

// Work left for after the response, such as the revalidation of an entry
// served stale: Response::send() has already let the client go under
// PHP-FPM (fastcgi_finish_request()).
if ($kernel !== null) {
    try {
        $kernel->terminate();
    } catch (\Throwable $exception) {
        $logger->error('unhandled exception after the response', ['exception' => $exception]);
    }
}
