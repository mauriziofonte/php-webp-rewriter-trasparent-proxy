<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Cache\RequestCoalescer;
use Mfonte\ProxyCdn\Tests\Fixtures\FakeOrigin\FakeOriginServer;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\ProxyServer;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\RawHttpResponse;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Black-box tests of the proxy over HTTP: a copy of the application runs in
 * PHP's built-in web server behind public/index.php and fetches from a fake
 * origin in another one. They pin what the proxy does when a request reaches
 * PHP, independently of how the code is organised, so that the PRX-3 port
 * keeps the 2.x behaviour. The web server's own hit path (.htaccess) is
 * covered by the Docker harness in tests/Integration/docker.
 *
 * A test that pins a 2.x defect listed in the findings register names the
 * finding in its name and its docblock, so that the task that resolves the
 * finding flips it.
 */
abstract class CharacterizationTestCase extends TestCase
{
    /**
     * @var string
     */
    protected $workDirectory;

    /**
     * @var FakeOriginServer
     */
    protected $origin;

    /**
     * @var ProxyServer
     */
    protected $proxy;

    protected function set_up(): void
    {
        parent::set_up();

        $this->workDirectory = sys_get_temp_dir() . '/proxy-cdn-http-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->workDirectory . '/origin', 0777, true));

        $this->origin = FakeOriginServer::start($this->workDirectory . '/origin', $this->originCacheControl());
        $this->proxy = ProxyServer::start(
            $this->workDirectory . '/app',
            ['PATH' => $this->executablePath()],
            $this->dotEnv($this->configuration())
        );
    }

    protected function tear_down(): void
    {
        $this->proxy->stop();
        $this->origin->stop();
        self::remove($this->workDirectory);

        parent::tear_down();
    }

    /**
     * Configuration of the proxy, as .env values; null leaves a key out.
     * Subclasses override it to change single values.
     *
     * @return array<string, string|null>
     */
    protected function configuration(): array
    {
        return [
            'DEBUG' => 'false',
            'SOURCE_ORIGIN' => '"' . $this->origin->getBaseUrl() . '"',
            'PROXY_HOSTS' => '"127.0.0.1"',
            'CACHEABLE_EXTENSIONS' => '"css,js,jpg,jpeg,png,gif,bmp,webp,ttf,woff,woff2,otf,eot,svg,ico"',
            'CACHE_EXPIRY' => '31536000',
            'NEGATIVE_CACHE_TTL' => '300',
            'NETWORK_ERROR_TTL' => '30',
            'UPSTREAM_TIMEOUT' => '10',
            'UPSTREAM_CONNECT_TIMEOUT' => '2',
            'UPSTREAM_VERIFY_TLS' => 'false',
            'UPSTREAM_FOLLOW_REDIRECTS' => 'false',
            'CHARSET' => '"UTF-8"',
            'TIMEZONE' => '"UTC"',
            'LOCALE' => '"en_US"',
            'IMAGE_CONVERTER' => '"none"',
        ];
    }

    /**
     * The Cache-Control of the files of the fake origin. These tests
     * characterize the cache, so its default is public and long-lived; the
     * default of the fake origin, "private, max-age=60", is for the tests
     * of what the proxy must not store (null).
     */
    protected function originCacheControl(): ?string
    {
        return 'public, max-age=7776000';
    }

    /**
     * PATH of the proxy process.
     */
    protected function executablePath(): string
    {
        return '/usr/local/bin:/usr/bin:/bin';
    }

    /**
     * @param array<string, string|null> $values
     */
    protected function dotEnv(array $values): string
    {
        $lines = '';
        foreach ($values as $name => $value) {
            if ($value !== null) {
                $lines .= $name . ' = ' . $value . "\n";
            }
        }

        return $lines;
    }

    /**
     * @param array<string, string> $headers
     */
    protected function get(string $target, array $headers = []): RawHttpResponse
    {
        return $this->send('GET', $target, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    protected function send(string $method, string $target, array $headers = []): RawHttpResponse
    {
        return $this->proxy->getClient()->request($method, $target, $headers);
    }

    /**
     * Absolute path of a file below the copy's public/ directory.
     */
    protected function publicPath(string $relativePath): string
    {
        return $this->proxy->getProjectDirectory() . '/public/' . ltrim($relativePath, '/');
    }

    /**
     * Absolute path of the cache entry of a path without query string:
     * public/_cache/127.0.0.1/<path>.
     */
    protected function cachePath(string $relativePath): string
    {
        return $this->publicPath('_cache/127.0.0.1/' . ltrim($relativePath, '/'));
    }

    /**
     * Absolute path of the cache entry of a canonical query string:
     * public/_cache/127.0.0.1/.q/<query>/<path>, or .qh/<hash>/<path>.
     */
    protected function bucketPath(string $canonicalQuery, string $relativePath): string
    {
        return $this->cachePath(CacheKeyResolver::bucketOf($canonicalQuery) . '/' . ltrim($relativePath, '/'));
    }

    /**
     * The slot files of the negative cache, in var/negative/ of the copy.
     *
     * @return list<string>
     */
    protected function negativeMarkers(): array
    {
        $files = glob($this->proxy->getProjectDirectory() . '/var/negative/*.neg');

        return $files === false ? [] : $files;
    }

    /**
     * The entity tag nginx, and the proxy, give a file: "<mtime>-<size>" in
     * hexadecimal.
     */
    protected static function etagOf(string $file): string
    {
        clearstatcache();

        return sprintf('"%x-%x"', (int) filemtime($file), (int) filesize($file));
    }

    protected static function fixture(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/origin/' . ltrim($relativePath, '/'));
        self::assertIsString($contents);

        return $contents;
    }

    /**
     * Asserts the 2.x plain-text error body, whose Ray ID is the MD5 of the
     * request path.
     */
    protected static function assertErrorResponse(RawHttpResponse $response, int $status, string $errorCode, string $path): void
    {
        self::assertSame($status, $response->getStatus(), $response->getBody());
        self::assertSame('text/plain; charset=UTF-8', $response->getHeader('Content-Type'));
        self::assertSame(
            "The request could not be processed.\nError code: {$errorCode}.\nRay ID: " . md5($path),
            $response->getBody()
        );
    }

    /**
     * As if OUTCOME_WINDOW seconds had passed since the last request: the
     * outcome the coalescer shares with every request of an entry (a
     * failure, a 404, a redirect) has expired, so that the next request of
     * the same URL reaches the origin again.
     */
    protected function afterTheOutcomeWindow(): void
    {
        foreach (glob($this->proxy->getProjectDirectory() . '/var/coalescing/*.outcome') ?: [] as $file) {
            touch($file, time() - RequestCoalescer::OUTCOME_WINDOW);
        }
        clearstatcache();
    }

    /**
     * Sets the modification time of a negative-cache marker $seconds in the past.
     */
    protected static function age(string $file, int $seconds): void
    {
        self::assertFileExists($file);
        self::assertTrue(touch($file, time() - $seconds));
        clearstatcache();
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if (is_string($entry) && $entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
