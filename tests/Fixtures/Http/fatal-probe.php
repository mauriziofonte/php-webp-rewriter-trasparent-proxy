<?php

declare(strict_types=1);

/*
 * Fetches one URL through HttpOriginFetcher in a process whose memory_limit
 * is smaller than the body, so that reading the body into a string ends in
 * a fatal error while its temporary file exists. TemporaryFilesTest runs it
 * in a separate PHP process and checks that the file is deleted anyway. The
 * last error is printed at shutdown: before PHP 8, the @ of
 * ResponseBody::getContents() hides even a fatal error.
 *
 * Usage: php -d memory_limit=16M fatal-probe.php <curl|stream> <url> <temporary directory>
 */

use Mfonte\ProxyCdn\Origin\CurlTransport;
use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Origin\HttpOriginFetcher;
use Mfonte\ProxyCdn\Origin\OriginRequest;
use Mfonte\ProxyCdn\Origin\StreamTransport;
use Psr\Log\NullLogger;

error_reporting(E_ALL);
register_shutdown_function(static function (): void {
    $error = error_get_last();
    echo 'last error: ', $error === null ? 'none' : $error['message'], "\n";
});

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$options = (new HttpClientOptions())
    ->withTemporaryDirectory($argv[3])
    ->withMaxBodySize(PHP_INT_MAX);
$client = $argv[1] === 'curl' ? new CurlTransport($options) : new StreamTransport($options);

(new HttpOriginFetcher($client, new NullLogger()))->fetch(new OriginRequest($argv[2]));

echo "fetched without a fatal error\n";
