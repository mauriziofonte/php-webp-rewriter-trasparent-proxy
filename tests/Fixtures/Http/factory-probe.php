<?php

declare(strict_types=1);

/*
 * Sends one GET through HttpClientFactory and prints, as JSON, the transport
 * class it picked and what came back. HttpClientFactoryTest runs it in a
 * separate PHP process, with and without the curl functions disabled.
 *
 * Usage: php factory-probe.php <url> <temporary directory>
 */

use Mfonte\ProxyCdn\Origin\HttpClientFactory;
use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Origin\HttpRequest;

error_reporting(E_ALL);
set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $level, $file, $line);
});

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$client = HttpClientFactory::create((new HttpClientOptions())->withTemporaryDirectory($argv[2]));
$response = $client->send(new HttpRequest($argv[1]));
$body = $response->getBody();

echo json_encode([
    'transport' => get_class($client),
    'outcome' => $response->getOutcome(),
    'hash' => $body === null ? null : hash_file('sha256', $body->getPath()),
]), "\n";
