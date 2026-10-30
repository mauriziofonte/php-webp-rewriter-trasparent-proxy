<?php

declare(strict_types=1);

/*
 * Downloads one URL through CurlTransport or StreamTransport and prints, as
 * JSON, the outcome, the stored size and hash, and the peak memory of this
 * process: Zend's peak (memory_get_peak_usage(true)) and the peak resident
 * set size (VmHWM, Linux only). The body file is deleted before exiting.
 * LargeDownloadTest runs it in a separate PHP process, so that nothing else
 * weighs on the peaks.
 *
 * Usage: php download-probe.php <curl|stream> <url> <temporary directory>
 */

use Mfonte\ProxyCdn\Origin\CurlTransport;
use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Origin\HttpRequest;
use Mfonte\ProxyCdn\Origin\StreamTransport;

error_reporting(E_ALL);
set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $level, $file, $line);
});

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$options = (new HttpClientOptions())
    ->withTemporaryDirectory($argv[3])
    ->withMaxBodySize(PHP_INT_MAX)
    ->withTimeouts(5.0, 600.0);
$client = $argv[1] === 'curl' ? new CurlTransport($options) : new StreamTransport($options);

$response = $client->send(new HttpRequest($argv[2]));

$peak = memory_get_peak_usage(true);
$status = @file_get_contents('/proc/self/status');
$hwm = is_string($status) && preg_match('/^VmHWM:\s+(\d+) kB/m', $status, $match) === 1 ? (int) $match[1] * 1024 : null;

$body = $response->getBody();
$result = [
    'outcome' => $response->getOutcome(),
    'error' => $response->getError(),
    'size' => $body === null ? null : $body->getSize(),
    'hash' => $body === null ? null : hash_file('sha256', $body->getPath()),
    'peak' => $peak,
    'hwm' => $hwm,
];
if ($body !== null) {
    $body->delete();
}

echo json_encode($result), "\n";
