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
 * the raster sibling of a legacy WEBP URL; "legacy-short" sends that
 * sibling with "Cache-Control: max-age=60", so that it is stored in the
 * tier PHP revalidates). With "resize-hang", <url> is a signed resize
 * URL (RESIZE_KEY of RESIZE_HANG_KEY), the origin answers with a PNG,
 * and the converter hangs RESIZE_HANG_MS, then answers that it cannot
 * convert now, as a conversion API that hangs does; "resize-fail" does
 * the same after RESIZE_FAIL_MS. Either converter records each attempt
 * as a line of "conversions.log" in <directory>.
 *
 * Usage: php coalesce-child.php <directory> <fetch log> <url> <delay ms> <start time> [<mode>]
 */

use Mfonte\ProxyCdn\Image\ConversionFailure;
use Mfonte\ProxyCdn\Image\ImageConverter;
use Mfonte\ProxyCdn\Origin\OriginFetcher;
use Mfonte\ProxyCdn\Origin\OriginRequest;
use Mfonte\ProxyCdn\Origin\OriginResponse;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\TestKernel;
use Mfonte\ProxyCdn\Tests\Fixtures\Image\FakeImageConverter;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ConversionResult;
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

const RESIZE_HANG_KEY = 'resize-hang-key-0123456789abcdef0123';
const RESIZE_HANG_MS = 3000;
const RESIZE_FAIL_MS = 1000;

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
        $legacy = $this->mode === 'legacy' || $this->mode === 'legacy-short';
        if ($this->mode === 'notfound' || ($legacy && substr($request->getUrl(), -5) === '.webp')) {
            return OriginResponse::failure(OriginResponse::FAILURE_NOT_FOUND, 404);
        }
        if ($this->mode === 'resize-hang' || $this->mode === 'resize-fail') {
            return OriginResponse::success(['content-type' => 'image/png'], (string) file_get_contents(dirname(__DIR__) . '/Image/files/image.png'));
        }
        $headers = ['content-type' => 'text/css'];
        if ($this->mode === 'private') {
            $headers['cache-control'] = 'private, max-age=60';
        }
        if ($this->mode === 'legacy-short') {
            $headers['cache-control'] = 'max-age=60';
        }

        return OriginResponse::success($headers, str_repeat("body { color: red; }\n", 100));
    }
};

$converter = null;
$overrides = $mode === 'notfound' ? ['NEGATIVE_CACHE_TTL' => '0'] : [];
if ($mode === 'resize-hang' || $mode === 'resize-fail') {
    $converter = new class ($directory . '/conversions.log', $mode === 'resize-hang' ? RESIZE_HANG_MS : RESIZE_FAIL_MS) implements ImageConverter {
        /** @var string */
        private $log;

        /** @var int */
        private $hang;

        public function __construct(string $log, int $hang)
        {
            $this->log = $log;
            $this->hang = $hang;
        }

        public function convert(string $inputPath, string $outputPath, ConversionRequest $request, ?string $requestId = null): ConversionResult
        {
            file_put_contents($this->log, sprintf("%.6F\n", microtime(true)), FILE_APPEND | LOCK_EX);
            usleep($this->hang * 1000);

            throw ConversionFailure::unavailable('hang', 'The converter timed out', 'timeout');
        }

        public function getCapabilities(): Capabilities
        {
            return FakeImageConverter::capabilities(['webp', 'avif', 'png']);
        }

        public function getName(): string
        {
            return 'hang';
        }
    };
    $overrides = [
        'IMAGE_CONVERTER' => 'local',
        'RESIZE_WIDTHS' => '8',
        'RESIZE_KEY' => RESIZE_HANG_KEY,
        'UPSTREAM_TIMEOUT' => '1',
        'IMAGE_CONVERSION_TIMEOUT' => '1',
    ];
}

$kernel = TestKernel::create(
    TestConfig::create(['SOURCE_ORIGIN' => 'https://origin.example.test'] + $overrides),
    $directory,
    $origin,
    $converter,
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
