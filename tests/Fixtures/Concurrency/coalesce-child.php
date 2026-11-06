<?php

declare(strict_types=1);

/*
 * One of the concurrent requests of RequestCoalescingTest: waits until the
 * start time, sends GET <url> through a kernel built as the front controller
 * builds it, whose origin takes <delay> milliseconds and records each fetch
 * as a line of <fetch log>, then prints the status, X-Cache-Status and
 * SHA-256 of the body and the Location as JSON. The origin answers with
 * the stylesheet (mode "ok", the default), with a 503 ("fail"), with a
 * refused connection ("transport"), with the stylesheet marked
 * "Cache-Control: private" ("private"), with a 302 to
 * "/css/moved.css" ("redirect"), with a 404 ("notfound", with
 * NEGATIVE_CACHE_TTL 0, so that only the coalescer can share it), or with
 * a 404 for the ".webp" URLs and the stylesheet for any other ("legacy":
 * the raster sibling of a legacy WEBP URL).
 *
 * Usage: php coalesce-child.php <directory> <fetch log> <url> <delay ms> <start time> [<mode>]
 */

use Mfonte\ProxyCdn\Origin\OriginFetcher;
use Mfonte\ProxyCdn\Origin\OriginRequest;
use Mfonte\ProxyCdn\Origin\OriginResponse;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\TestKernel;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

// The arguments from $_SERVER: PHPStan leaves $argv undefined when
// register_argc_argv is off, as on the php:8.5-cli image.
$argv = array_values(array_filter(is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [], 'is_string'));

error_reporting(E_ALL);
set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
    if ((error_reporting() & $level) === 0) {
        // Silenced with "@": the code checks the outcome itself.
        return false;
    }

    throw new ErrorException($message, 0, $level, $file, $line);
});

require dirname(__DIR__, 3) . '/vendor/autoload.php';

list(, $directory, $fetchLog, $url, $delay, $start) = $argv;
$mode = $argv[6] ?? 'ok';

$origin = new class ($fetchLog, (int) $delay, $mode) implements OriginFetcher {
    /** @var string */
    private $log;

    /** @var int */
    private $delay;

    /** @var string */
    private $mode;

    public function __construct(string $log, int $delay, string $mode)
    {
        $this->log = $log;
        $this->delay = $delay;
        $this->mode = $mode;
    }

    public function fetch(OriginRequest $request): OriginResponse
    {
        file_put_contents($this->log, $request->getUrl() . "\n", FILE_APPEND | LOCK_EX);
        usleep($this->delay * 1000);
        if ($this->mode === 'fail') {
            return OriginResponse::failure(OriginResponse::FAILURE_SERVER_ERROR, 503);
        }
        if ($this->mode === 'transport') {
            return OriginResponse::failure(OriginResponse::FAILURE_CONNECT, 0, 7, 'Connection refused');
        }
        if ($this->mode === 'redirect') {
            return OriginResponse::redirect(302, 'https://origin.example.test/css/moved.css');
        }
        if ($this->mode === 'notfound' || ($this->mode === 'legacy' && substr($request->getUrl(), -5) === '.webp')) {
            return OriginResponse::failure(OriginResponse::FAILURE_NOT_FOUND, 404);
        }
        $headers = ['content-type' => 'text/css'];
        if ($this->mode === 'private') {
            $headers['cache-control'] = 'private, max-age=60';
        }

        return OriginResponse::success($headers, str_repeat("body { color: red; }\n", 100));
    }
};

$kernel = TestKernel::create(
    TestConfig::create(['SOURCE_ORIGIN' => 'https://origin.example.test'] + ($mode === 'notfound' ? ['NEGATIVE_CACHE_TTL' => '0'] : [])),
    $directory,
    $origin,
    null,
    new NullLogger()
);

$wait = (float) $start - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1000000));
}
$response = $kernel->handle(Request::create($url));
ob_start();
$response->sendContent();
$body = (string) ob_get_clean();

echo json_encode([
    'status' => $response->getStatusCode(),
    'cache' => $response->headers->get('X-Cache-Status'),
    'hash' => hash('sha256', $body),
    'location' => $response->headers->get('Location'),
]), "\n";
