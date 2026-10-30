<?php

declare(strict_types=1);

/*
 * Fetches one URL through HttpOriginFetcher in a process whose memory_limit
 * is smaller than the body, so that reading the body into a string ends in
 * a fatal error while its temporary file exists. TemporaryFilesTest runs it
 * in a separate PHP process and checks that the file is deleted anyway.
 *
 * The last error is written to the report file at shutdown, which shows
 * that the fatal error happened where expected and that the shutdown
 * functions ran. It is not printed: before PHP 8, the @ of
 * ResponseBody::getContents() hides even a fatal error, and what a process
 * prints after one differs between builds.
 *
 * Usage: php -d memory_limit=16M fatal-probe.php <curl|stream> <url> <temporary directory> <report file>
 */

use Mfonte\ProxyCdn\Origin\CurlTransport;
use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Origin\HttpOriginFetcher;
use Mfonte\ProxyCdn\Origin\OriginRequest;
use Mfonte\ProxyCdn\Origin\StreamTransport;
use Psr\Log\NullLogger;

error_reporting(E_ALL);
register_shutdown_function(static function () use ($argv): void {
    $error = error_get_last();
    file_put_contents($argv[4], $error === null ? 'none' : $error['message']);
});

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$options = (new HttpClientOptions())
    ->withTemporaryDirectory($argv[3])
    ->withMaxBodySize(PHP_INT_MAX);
$client = $argv[1] === 'curl' ? new CurlTransport($options) : new StreamTransport($options);

(new HttpOriginFetcher($client, new NullLogger()))->fetch(new OriginRequest($argv[2]));

echo "fetched without a fatal error\n";
