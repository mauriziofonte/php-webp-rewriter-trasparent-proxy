<?php

declare(strict_types=1);

/*
 * Run by run.sh inside each PHP-FPM container: fetches the fixture from the
 * fake origin over TLS (https://origin, certificate trusted through
 * /etc/harness/origin-ca.pem) with CurlTransport and with StreamTransport,
 * and prints "<transport> <outcome> HTTP/<version> <sha256 of the body>"
 * for each. CurlTransport must negotiate HTTP/2 when libcurl supports it,
 * StreamTransport speaks HTTP/1.1. Exits 1 when a fetch fails or a version
 * is not the expected one.
 *
 * Usage: php origin-protocol.php <path of vendor/autoload.php>
 */

use Mfonte\ProxyCdn\Origin\CurlTransport;
use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Origin\HttpRequest;
use Mfonte\ProxyCdn\Origin\HttpResponse;
use Mfonte\ProxyCdn\Origin\StreamTransport;

require $argv[1] ?? '/app/vendor/autoload.php';

$options = (new HttpClientOptions())->withTlsVerification(true, '/etc/harness/origin-ca.pem')->withTimeouts(5.0, 20.0);
$clients = [
    'curl' => [new CurlTransport($options), CurlTransport::supportsHttp2() ? '2' : '1.1'],
    'stream' => [new StreamTransport($options), '1.1'],
];

$status = 0;
foreach ($clients as $name => list($client, $expected)) {
    $response = $client->send(new HttpRequest('https://origin/images/gradient.png'));
    $body = $response->getBody();
    printf(
        "%s %s HTTP/%s %s\n",
        $name,
        $response->getOutcome(),
        $response->getProtocolVersion(),
        $body === null ? '-' : hash_file('sha256', $body->getPath())
    );
    if ($response->getOutcome() !== HttpResponse::OUTCOME_SUCCESS || $response->getProtocolVersion() !== $expected) {
        fprintf(STDERR, "%s: expected success over HTTP/%s, got %s %s\n", $name, $expected, $response->getOutcome(), $response->getError());
        $status = 1;
    }
}

exit($status);
