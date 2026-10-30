<?php

declare(strict_types=1);

/*
 * Fetches one URL through HttpOriginFetcher in a process whose memory_limit
 * is smaller than the body, so that reading the body into a string ends in
 * a fatal error while its temporary file exists. TemporaryFilesTest runs it
 * in a separate PHP process and checks that the file is deleted anyway.
 *
 * Its shutdown function, registered before any other and therefore run
 * before TemporaryFiles::deleteAll(), writes to the report file the message
 * and the file of the last error, then the body files that exist at that
 * point, one per line. It writes rather than prints: what a process prints
 * after a fatal error differs between builds (the @ of
 * ResponseBody::getContents() hides it on some before PHP 8, not on all).
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
    $bodies = scandir($argv[3]);
    file_put_contents($argv[4], implode("\n", array_merge(
        $error === null ? ['', ''] : [$error['message'], $error['file']],
        array_diff($bodies === false ? [] : $bodies, ['.', '..'])
    )));
});

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$options = (new HttpClientOptions())
    ->withTemporaryDirectory($argv[3])
    ->withMaxBodySize(PHP_INT_MAX);
$client = $argv[1] === 'curl' ? new CurlTransport($options) : new StreamTransport($options);

(new HttpOriginFetcher($client, new NullLogger()))->fetch(new OriginRequest($argv[2]));

echo "fetched without a fatal error\n";
