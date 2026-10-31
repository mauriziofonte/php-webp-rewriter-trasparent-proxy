<?php

declare(strict_types=1);

/*
 * Fetches one URL with a transport and, while the body's temporary file
 * exists, ends in a fatal error, after which destructors do not run.
 * TemporaryFilesTest runs it in a separate PHP process and checks that the
 * file is deleted anyway.
 *
 * The fatal error is a class declared twice, not the likelier memory
 * exhaustion while a body is read into a string: the builds of PHP 7.2 to
 * 8.1 that setup-php installs on GitHub's Ubuntu runners end the process
 * on memory exhaustion, with exit status 1, without running shutdown
 * functions. Both errors skip the destructors in the same way.
 *
 * Its shutdown function, registered before any other and therefore run
 * before TemporaryFiles::deleteAll(), writes to the report file the message
 * of the last error, then the body files that exist at that point, one per
 * line.
 *
 * Usage: php fatal-probe.php <curl|stream> <url> <temporary directory> <report file>
 */

use Mfonte\ProxyCdn\Origin\CurlTransport;
use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Origin\HttpRequest;
use Mfonte\ProxyCdn\Origin\StreamTransport;

error_reporting(E_ALL);
register_shutdown_function(static function () use ($argv): void {
    $error = error_get_last();
    $bodies = scandir($argv[3]);
    file_put_contents($argv[4], implode("\n", array_merge(
        [$error === null ? '' : $error['message']],
        array_diff($bodies === false ? [] : $bodies, ['.', '..'])
    )));
});

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$options = (new HttpClientOptions())->withTemporaryDirectory($argv[3]);
$client = $argv[1] === 'curl' ? new CurlTransport($options) : new StreamTransport($options);
$response = $client->send(new HttpRequest($argv[2]));
if ($response->getBody() === null) {
    echo 'no body: ', $response->getError(), "\n";

    exit(2);
}

eval('final class FatalProbe {}');
eval('final class FatalProbe {}');

echo "no fatal error\n";
