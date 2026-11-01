<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Functional\Http;

use Mfonte\ProxyCdn\Http\ProxyKernel;
use Mfonte\ProxyCdn\Origin\OriginResponse;
use Mfonte\ProxyCdn\Security\PurgeAuthenticator;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\TestKernel;
use Mfonte\ProxyCdn\Tests\Fixtures\Image\FakeWebpConverter;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\ProxyCdn\Tests\Fixtures\Origin\FakeOriginFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * ProxyKernel::handle() invoked in-process, with an in-memory origin: the
 * kernel can be mounted by any host that builds a Symfony Request.
 *
 * @covers \Mfonte\ProxyCdn\Http\ProxyKernel
 */
#[CoversClass(ProxyKernel::class)]
final class ProxyKernelTest extends TestCase
{
    private const ORIGIN = 'https://origin.example.test';

    private const PURGE_SECRET = 'purge-secret-0123456789';

    private const CSS = "body { background: url(/images/bg.png); }\n";

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var FakeOriginFetcher
     */
    private $origin;

    /**
     * @var MemoryLogger
     */
    private $logger;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->origin = new FakeOriginFetcher();
        $this->logger = new MemoryLogger();
        $this->origin->serve(self::ORIGIN . '/css/site.css', self::CSS, [
            'content-type' => 'text/css',
            'content-length' => (string) strlen(self::CSS),
            'etag' => '"abc"',
            'set-cookie' => 'session=secret',
            'cache-control' => 'private',
        ]);
        $this->origin->serve(self::ORIGIN . '/images/logo.png', 'PNG-BYTES', ['content-type' => 'image/png', 'content-length' => '9']);
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testAMissIsFetchedStoredAndAnswered(): void
    {
        $response = $this->handle('https://cdn.example.test/js/app.js', [], self::ORIGIN . '/js/app.js', 'console.log(1);');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('console.log(1);', self::body($response));
        self::assertSame('MISS', $response->headers->get('x-cache-status'));
        self::assertSame('max-age=31536000, must-revalidate, public', $response->headers->get('cache-control'));
        self::assertSame('console.log(1);', file_get_contents($this->cachePath('js/app.js')));
        self::assertSame([self::ORIGIN . '/js/app.js'], $this->origin->getRequestedUrls());
    }

    /**
     * A miss is typed by its extension, as a hit is, whatever the origin
     * claims (finding P-23: one table of media types).
     */
    public function testAMissHasTheMediaTypeOfItsExtension(): void
    {
        $this->origin->serve(self::ORIGIN . '/css/a.css', self::CSS, ['content-type' => 'text/html; charset=UTF-8']);
        $this->origin->serve(self::ORIGIN . '/fonts/a.woff2', 'font', []);

        $css = $this->kernel()->handle(Request::create('https://cdn.example.test/css/a.css'));
        $font = $this->kernel()->handle(Request::create('https://cdn.example.test/fonts/a.woff2'));
        $hit = $this->kernel()->handle(Request::create('https://cdn.example.test/css/a.css'));

        self::assertSame('MISS', $css->headers->get('x-cache-status'));
        self::assertSame('text/css', $css->headers->get('content-type'));
        self::assertSame('font/woff2', $font->headers->get('content-type'));
        self::assertSame('HIT', $hit->headers->get('x-cache-status'));
        self::assertSame('text/css', $hit->headers->get('content-type'));
    }

    /**
     * No header of the origin reaches the client: the miss carries the
     * headers a hit carries, its entity tag and Last-Modified those of the
     * cache file, so that a client revalidates against the web server.
     */
    public function testNoOriginHeaderIsForwarded(): void
    {
        $this->origin->serve(self::ORIGIN . '/js/app.js', 'x', [
            'content-type' => 'application/javascript',
            'etag' => '"e"',
            'last-modified' => 'Tue, 01 Sep 2026 10:00:00 GMT',
            'vary' => 'Origin',
            'content-disposition' => 'attachment',
            'set-cookie' => 'session=secret',
            'x-origin-only' => 'yes',
        ]);

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js'));
        $file = $this->cachePath('js/app.js');

        self::assertSame('text/javascript', $response->headers->get('content-type'), 'the type of the extension');
        self::assertSame(sprintf('"%x-%x"', filemtime($file), 1), $response->headers->get('etag'));
        self::assertSame(gmdate('D, d M Y H:i:s', (int) filemtime($file)) . ' GMT', $response->headers->get('last-modified'));
        self::assertSame('Accept-Encoding', $response->headers->get('vary'));
        self::assertSame('1', $response->headers->get('content-length'));
        self::assertFalse($response->headers->has('content-disposition'));
        self::assertFalse($response->headers->has('set-cookie'));
        self::assertFalse($response->headers->has('x-origin-only'));
    }

    /**
     * The origin receives the allowlisted parameters as the client sent
     * them, without the others (findings P-5 and P-9).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testTheOriginRequestCarriesTheOriginalQueryAndTheRefererP9(): void
    {
        $this->origin->serve(self::ORIGIN . '/js/app.js?ver=2&v=1', 'x');

        $this->kernel()->handle(Request::create('http://cdn.example.test:8080/js/app.js?ver=2&utm_source=mail&v=1'));

        $requests = $this->origin->getRequests();
        self::assertCount(1, $requests);
        self::assertSame(self::ORIGIN . '/js/app.js?ver=2&v=1', $requests[0]->getUrl());
        self::assertSame(['Referer' => 'http://cdn.example.test:8080'], $requests[0]->getHeaders());
        self::assertFileExists($this->cachePath('.q/v=1&ver=2/js/app.js'));
    }

    public function testTheRefererSchemeComesFromATrustedProxyOnly(): void
    {
        $this->origin->serve(self::ORIGIN . '/js/a.js', 'a');
        $this->origin->serve(self::ORIGIN . '/js/b.js', 'b');
        $kernel = TestKernel::create(
            TestConfig::create(['SOURCE_ORIGIN' => self::ORIGIN . '/', 'TRUSTED_PROXIES' => '10.0.0.0/8']),
            $this->directory->path(),
            $this->origin,
            null,
            $this->logger
        );
        $headers = ['HTTP_X_FORWARDED_PROTO' => 'https'];

        $kernel->handle(Request::create('http://cdn.example.test/js/a.js', 'GET', [], [], [], $headers + ['REMOTE_ADDR' => '10.1.2.3']));
        $kernel->handle(Request::create('http://cdn.example.test/js/b.js', 'GET', [], [], [], $headers + ['REMOTE_ADDR' => '192.0.2.1']));

        $requests = $this->origin->getRequests();
        self::assertCount(2, $requests);
        self::assertSame(['Referer' => 'https://cdn.example.test'], $requests[0]->getHeaders());
        self::assertSame(['Referer' => 'http://cdn.example.test'], $requests[1]->getHeaders());
    }

    /**
     * A path whose request reaches PHP although its file is stored (a web
     * server that does not serve it, or a parameter outside the allowlist)
     * is served from disk; 2.x fetched it again every time (finding P-5).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testAStoredPathIsServedFromDiskP5(): void
    {
        $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css'));
        $again = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css'));
        $busted = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css?_=1700000000'));

        self::assertSame('HIT', $again->headers->get('x-cache-status'));
        self::assertSame('HIT', $busted->headers->get('x-cache-status'));
        self::assertCount(1, $this->origin->getRequests());
        self::assertDirectoryDoesNotExist($this->cachePath('.q'));
    }

    /**
     * A new build of Laravel Mix ("?id=<hash>") is fetched again by default,
     * not served from the entry of the old build.
     */
    public function testALaravelMixVersionKeysTheCacheByDefault(): void
    {
        $this->origin->serve(self::ORIGIN . '/css/app.css?id=old', 'old build', ['content-type' => 'text/css']);
        $this->origin->serve(self::ORIGIN . '/css/app.css?id=new', 'new build', ['content-type' => 'text/css']);

        $old = $this->kernel()->handle(Request::create('https://cdn.example.test/css/app.css?id=old'));
        $new = $this->kernel()->handle(Request::create('https://cdn.example.test/css/app.css?id=new'));

        self::assertSame('old build', self::body($old));
        self::assertSame('new build', self::body($new));
        self::assertCount(2, $this->origin->getRequests());
    }

    public function testRejectModeRefusesOtherParameters(): void
    {
        $kernel = TestKernel::create(
            TestConfig::create(['SOURCE_ORIGIN' => self::ORIGIN . '/', 'QUERY_STRING_MODE' => 'reject']),
            $this->directory->path(),
            $this->origin,
            null,
            $this->logger
        );

        $response = $kernel->handle(Request::create('https://cdn.example.test/css/site.css?x=1'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->origin->getRequests());
    }

    public function testAQueryStringBucketIsServedFromDisk(): void
    {
        $this->origin->serve(self::ORIGIN . '/js/app.js?v=1', 'versioned', ['content-type' => 'application/javascript']);
        $this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js?v=1'));

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js?v=1&purge=0'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('versioned', self::body($response));
        self::assertSame('HIT', $response->headers->get('x-cache-status'));
        self::assertSame('text/javascript', $response->headers->get('content-type'));
        self::assertSame('9', $response->headers->get('content-length'));
        self::assertSame('Accept-Encoding', $response->headers->get('vary'));
        self::assertSame('max-age=31536000, must-revalidate, public', $response->headers->get('cache-control'));
        self::assertCount(1, $this->origin->getRequests());
        self::assertFileExists($this->cachePath('.q/v=1/js/app.js'));
    }

    public function testOptionsGetsThePreflightAnswer(): void
    {
        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css', 'OPTIONS'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('*', $response->headers->get('access-control-allow-origin'));
        self::assertSame('86400', $response->headers->get('access-control-max-age'));
        self::assertSame([], $this->origin->getRequests());
    }

    public function testAMethodOverrideIsIgnored(): void
    {
        $request = Request::create('https://cdn.example.test/css/site.css', 'POST', [], [], [], ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'GET']);

        self::assertSame(405, $this->kernel()->handle($request)->getStatusCode());
        self::assertSame([], $this->origin->getRequests());
    }

    public function testOtherMethodsAreRefused(): void
    {
        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css', 'POST'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD, OPTIONS', $response->headers->get('allow'));
        self::assertSame([], $this->origin->getRequests());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideMethods(): array
    {
        return ['GET' => ['GET'], 'OPTIONS' => ['OPTIONS'], 'PURGE' => ['PURGE']];
    }

    /**
     * A Host header that is not a host name is the client's mistake: 400,
     * not the exception of Symfony, which the front controller logs as an
     * error and answers 500.
     *
     * @dataProvider provideMethods
     */
    #[DataProvider('provideMethods')]
    public function testAnInvalidHostIsABadRequest(string $method): void
    {
        $request = Request::create('/css/site.css', $method, [], [], [], [
            'HTTP_HOST' => 'bad host',
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::PURGE_SECRET,
        ]);

        $response = $this->kernel()->handle($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Error code: Invalid Host header.', self::body($response));
        self::assertSame([], $this->origin->getRequests());
        self::assertSame([], $this->logger->getRecords());
    }

    public function testHeadHasTheHeadersOfTheGetWithoutBody(): void
    {
        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css', 'HEAD'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', self::body($response));
        self::assertSame((string) strlen(self::CSS), $response->headers->get('content-length'));
        self::assertFileExists($this->cachePath('css/site.css'));
    }

    /**
     * @dataProvider provideRefusedRequests
     */
    #[DataProvider('provideRefusedRequests')]
    public function testRefusedRequestsNeverReachTheOrigin(string $uri, string $path, string $code, int $status): void
    {
        $response = $this->kernel()->handle(Request::create('https://cdn.example.test' . $uri));

        self::assertSame($status, $response->getStatusCode());
        self::assertSame(
            "The request could not be processed.\nError code: " . $code . ".\nRay ID: " . md5($path),
            self::body($response)
        );
        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('content-type'));
        self::assertSame([], $this->origin->getRequests());
    }

    /**
     * @return array<string, array{string, string, string, int}>
     */
    public static function provideRefusedRequests(): array
    {
        return [
            'traversal' => ['/css/../secret.css', '/css/../secret.css', 'Cannot serve this request', 400],
            'php' => ['/app/config.php', '/app/config.php', 'Request cannot be processed', 404],
            'no extension' => ['/images/gradient', '/images/gradient', 'Request cannot be processed', 404],
        ];
    }

    public function testAnOrigin404IsRemembered(): void
    {
        $first = $this->kernel()->handle(Request::create('https://cdn.example.test/missing.css'));
        $second = $this->kernel()->handle(Request::create('https://cdn.example.test/missing.css'));

        self::assertSame(404, $first->getStatusCode());
        self::assertStringContainsString('Error code: Cannot read remote origin file.', self::body($first));
        self::assertSame(404, $second->getStatusCode());
        self::assertCount(1, $this->origin->getRequests());
        self::assertFileDoesNotExist($this->cachePath('missing.css.404'));
        self::assertCount(1, glob($this->directory->path(TestKernel::STATE_DIRECTORY . '/negative/*.neg')) ?: []);
    }

    /**
     * The breaker belongs to the origin: once it opens, every path that
     * needs a fetch is answered 503 without contacting the origin, and the
     * entries on disk are still served (finding P-14: 2.x kept a breaker
     * per file).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testTheBreakerOpensForTheWholeOriginP14(): void
    {
        $this->writeCache('css/cached.css', 'cached');
        $this->origin->respond(self::ORIGIN . '/js/app.js', OriginResponse::failure(OriginResponse::FAILURE_TIMEOUT, 0, 28, 'timed out'));

        $first = $this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js'));
        $other = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css'));
        $cached = $this->kernel()->handle(Request::create('https://cdn.example.test/css/cached.css'));

        self::assertSame(503, $first->getStatusCode());
        self::assertSame('30', $first->headers->get('retry-after'));
        self::assertStringContainsString('Error code: Upstream temporarily unavailable.', self::body($first));
        self::assertSame(503, $other->getStatusCode());
        self::assertSame(200, $cached->getStatusCode());
        self::assertSame(['https://origin.example.test/js/app.js'], $this->origin->getRequestedUrls());
        self::assertFileDoesNotExist($this->cachePath('js/app.js.netfail'));
    }

    public function testTheBreakerOpensAfterTheThreshold(): void
    {
        $this->origin->respond(self::ORIGIN . '/js/app.js', OriginResponse::failure(OriginResponse::FAILURE_SERVER_ERROR, 500));
        $kernel = TestKernel::create(
            TestConfig::create(['SOURCE_ORIGIN' => self::ORIGIN . '/', 'CIRCUIT_BREAKER_THRESHOLD' => '3']),
            $this->directory->path(),
            $this->origin,
            null,
            $this->logger
        );

        for ($attempt = 0; $attempt < 4; ++$attempt) {
            self::assertSame(503, $kernel->handle(Request::create('https://cdn.example.test/js/app.js'))->getStatusCode());
        }

        self::assertCount(3, $this->origin->getRequests());
    }

    /**
     * A transport failure the breaker does not map (here an HTTP/2 stream
     * error) is neutral: it does not start the count again, so the
     * connection failures around it still open the breaker.
     */
    public function testATransportFailureDoesNotResetTheCount(): void
    {
        $kernel = $this->kernelWithThreshold(5);
        $this->origin->respond(self::ORIGIN . '/js/down.js', OriginResponse::failure(OriginResponse::FAILURE_CONNECT, 0, 7, 'refused'));
        $this->origin->respond(self::ORIGIN . '/js/stream.js', OriginResponse::failure(OriginResponse::FAILURE_OTHER, 0, 92, 'stream error'));

        for ($attempt = 0; $attempt < 4; ++$attempt) {
            self::assertSame(503, $kernel->handle(Request::create('https://cdn.example.test/js/down.js'))->getStatusCode());
        }
        self::assertSame(502, $kernel->handle(Request::create('https://cdn.example.test/js/stream.js'))->getStatusCode());
        // The fifth failure in a row opens the breaker.
        self::assertSame(503, $kernel->handle(Request::create('https://cdn.example.test/js/down.js'))->getStatusCode());
        $requests = count($this->origin->getRequests());
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            self::assertSame(503, $kernel->handle(Request::create('https://cdn.example.test/js/down.js'))->getStatusCode());
        }

        self::assertCount($requests, $this->origin->getRequests(), 'the open breaker fetches nothing');
        self::assertSame(503, $kernel->handle(Request::create('https://cdn.example.test/css/site.css'))->getStatusCode());
    }

    /**
     * @return array<string, array{OriginResponse, bool}>
     */
    public static function provideProbeAnswers(): array
    {
        return [
            'HTTP/2 stream error' => [OriginResponse::failure(OriginResponse::FAILURE_OTHER, 0, 92, 'stream error'), false],
            'malformed reply' => [OriginResponse::failure(OriginResponse::FAILURE_OTHER, 0, 8, 'weird server reply'), false],
            'transport exception' => [OriginResponse::failure(OriginResponse::FAILURE_OTHER, 0, 0, 'protocol error'), false],
            'status 404' => [OriginResponse::failure(OriginResponse::FAILURE_NOT_FOUND, 404), true],
            'other 4xx' => [OriginResponse::failure(OriginResponse::FAILURE_CLIENT_ERROR, 400), true],
            'redirect' => [OriginResponse::redirect(302, 'https://elsewhere.example.test/x'), true],
        ];
    }

    /**
     * A half-open probe closes the breaker only when the origin sent an
     * HTTP status; a probe that fails at the transport level leaves it
     * open.
     *
     * @dataProvider provideProbeAnswers
     */
    #[DataProvider('provideProbeAnswers')]
    public function testOnlyAProbeAnsweredWithAStatusClosesTheBreaker(OriginResponse $answer, bool $closes): void
    {
        $kernel = $this->kernelWithThreshold(1);
        $this->origin->respond(self::ORIGIN . '/js/app.js', OriginResponse::failure(OriginResponse::FAILURE_CONNECT, 0, 7, 'refused'));
        self::assertSame(503, $kernel->handle(Request::create('https://cdn.example.test/js/app.js'))->getStatusCode());
        $this->origin->respond(self::ORIGIN . '/js/app.js', $answer);
        $this->shiftBreakerTimes(-31);

        $probe = $kernel->handle(Request::create('https://cdn.example.test/js/app.js'));
        $after = $kernel->handle(Request::create('https://cdn.example.test/css/site.css'));

        self::assertNotSame(503, $probe->getStatusCode(), 'the probe reached the origin');
        self::assertSame($closes ? 200 : 503, $after->getStatusCode());
        self::assertSame(['https://origin.example.test/js/app.js', 'https://origin.example.test/js/app.js'], array_slice($this->origin->getRequestedUrls(), 0, 2));
    }

    /**
     * @return array<string, array{OriginResponse, int, bool}>
     */
    public static function provideOriginFailures(): array
    {
        return [
            'status 403' => [OriginResponse::failure(OriginResponse::FAILURE_FORBIDDEN, 403), 403, false],
            'status 410' => [OriginResponse::failure(OriginResponse::FAILURE_GONE, 410), 410, false],
            'redirect to another origin' => [OriginResponse::redirect(302, 'https://elsewhere.example.test/js/app.js'), 502, false],
            'redirect to a private host' => [OriginResponse::redirect(302, 'http://127.0.0.1/js/app.js'), 502, false],
            'redirect to another port of the origin' => [OriginResponse::redirect(302, self::ORIGIN . ':8443/js/app.js'), 502, false],
            'redirect without a location' => [OriginResponse::redirect(301, null), 502, false],
            // A Location a browser reads as another host: an open redirect.
            'redirect to a network-path reference' => [OriginResponse::redirect(302, self::ORIGIN . '//evil.example.test/x'), 502, false],
            'redirect with a backslash' => [OriginResponse::redirect(302, self::ORIGIN . '/\\evil.example.test/x'), 502, false],
            'redirect with a tab' => [OriginResponse::redirect(302, self::ORIGIN . "/\t/evil.example.test/x"), 502, false],
            'redirect with a dot segment' => [OriginResponse::redirect(302, self::ORIGIN . '/.//evil.example.test/x'), 502, false],
            'other 4xx' => [OriginResponse::failure(OriginResponse::FAILURE_CLIENT_ERROR, 400), 502, false],
            'body too large' => [OriginResponse::failure(OriginResponse::FAILURE_TOO_LARGE, 200), 502, false],
            'other failure' => [OriginResponse::failure(OriginResponse::FAILURE_OTHER, 201), 502, false],
            'status 429' => [OriginResponse::failure(OriginResponse::FAILURE_CLIENT_ERROR, 429), 503, true],
            '5xx' => [OriginResponse::failure(OriginResponse::FAILURE_SERVER_ERROR, 500), 503, true],
            'connection refused' => [OriginResponse::failure(OriginResponse::FAILURE_CONNECT, 0, 7, 'refused'), 503, true],
        ];
    }

    /**
     * Each origin failure is answered by its kind, and only unavailability
     * opens the breaker (finding P-13: 2.x answered 503 and opened the
     * breaker for redirects, 403 and 410 alike).
     *
     * @dataProvider provideOriginFailures
     *
     * @group regression
     */
    #[DataProvider('provideOriginFailures')]
    #[Group('regression')]
    public function testOriginFailuresAreAnsweredByTheirKindP13(OriginResponse $failure, int $status, bool $opensTheBreaker): void
    {
        $this->origin->respond(self::ORIGIN . '/js/app.js', $failure);

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js'));

        self::assertSame($status, $response->getStatusCode());
        self::assertFalse($response->headers->has('location'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        $next = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css'));
        self::assertSame($opensTheBreaker ? 503 : 200, $next->getStatusCode());
    }

    /**
     * A redirect within the origin is passed on, its Location rewritten to
     * the path on the proxy (finding P-13).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testARedirectWithinTheOriginIsPassedOnP13(): void
    {
        $this->origin->respond(self::ORIGIN . '/js/app.js', OriginResponse::redirect(301, self::ORIGIN . '/js/app.min.js?v=2'));

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js'));

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/js/app.min.js?v=2', $response->headers->get('location'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        self::assertSame('', self::body($response));
        self::assertFileDoesNotExist($this->cachePath('js/app.js'));
        self::assertSame(200, $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css'))->getStatusCode());
    }

    public function testARedirectOutsideTheOriginPathIsRefused(): void
    {
        $origin = new FakeOriginFetcher();
        $origin->respond(self::ORIGIN . '/assets/js/app.js', OriginResponse::redirect(302, self::ORIGIN . '/admin/js/app.js'));
        $origin->respond(self::ORIGIN . '/assets/js/b.js', OriginResponse::redirect(302, self::ORIGIN . '/assets/js/c.js'));
        $origin->respond(self::ORIGIN . '/assets/js/d.js', OriginResponse::redirect(302, self::ORIGIN . '/assets//evil.example.test/x'));
        $kernel = TestKernel::create(TestConfig::create(['SOURCE_ORIGIN' => self::ORIGIN . '/assets/']), $this->directory->path(), $origin, null, $this->logger);

        self::assertSame(502, $kernel->handle(Request::create('https://cdn.example.test/js/app.js'))->getStatusCode());
        self::assertSame(502, $kernel->handle(Request::create('https://cdn.example.test/js/d.js'))->getStatusCode());
        self::assertSame('/js/c.js', $kernel->handle(Request::create('https://cdn.example.test/js/b.js'))->headers->get('location'));
    }

    public function testA410IsRemembered(): void
    {
        $this->origin->respond(self::ORIGIN . '/js/app.js', OriginResponse::failure(OriginResponse::FAILURE_GONE, 410));

        $this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js'));
        $second = $this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js'));

        self::assertSame(410, $second->getStatusCode());
        self::assertStringContainsString('Error code: Remote origin file is gone.', self::body($second));
        self::assertCount(1, $this->origin->getRequests());
    }

    public function testASuccessClearsStaleMarkers(): void
    {
        $this->kernel()->handle(Request::create('https://cdn.example.test/css/gone.css'));
        $markers = glob($this->directory->path(TestKernel::STATE_DIRECTORY . '/negative/*.neg')) ?: [];
        self::assertCount(1, $markers);
        touch($markers[0], time() - 3600);
        $this->origin->serve(self::ORIGIN . '/css/gone.css', self::CSS, ['content-type' => 'text/css']);

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css'));
        $gone = $this->kernel()->handle(Request::create('https://cdn.example.test/css/gone.css'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(200, $gone->getStatusCode());
        self::assertFileDoesNotExist($markers[0]);
    }

    /**
     * The anonymous "?purge=1" of 2.x purges nothing and answers a 401
     * that explains how to purge (risk L-7: 2.x purged for anyone).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testAnonymousPurgeIsRefusedWithGuidanceL7(): void
    {
        $this->origin->serve(self::ORIGIN . '/js/app.js?v=1', 'old');
        $this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js?v=1'));
        $this->origin->serve(self::ORIGIN . '/js/app.js?v=1', 'new');

        foreach (['v=1&purge=TRUE', 'v=1&purge_all=1'] as $query) {
            $response = $this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js?' . $query));

            self::assertSame(401, $response->getStatusCode());
            self::assertSame('Bearer realm="proxy-cdn purge"', $response->headers->get('www-authenticate'));
            self::assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
            self::assertStringContainsString('Error code: Purge requires a purge key.', self::body($response));
            self::assertStringContainsString('Send "PURGE <url>" with "Authorization: Bearer <key>"', self::body($response));
        }
        self::assertCount(1, $this->origin->getRequests());
        self::assertSame('old', self::body($this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js?v=1'))));
    }

    public function testPurgeWithAKeyRemovesTheEntry(): void
    {
        $this->origin->serve(self::ORIGIN . '/js/app.js?v=1', 'old');
        $this->purgingKernel()->handle(Request::create('https://cdn.example.test/js/app.js?v=1'));
        $this->logger = new MemoryLogger();

        $response = $this->purgingKernel()->handle(Request::create(
            'https://cdn.example.test/js/app.js?v=1',
            'PURGE',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . self::PURGE_SECRET, 'REMOTE_ADDR' => '192.0.2.7']
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('content-type'));
        self::assertSame(['purged' => true, 'path' => '/js/app.js', 'query' => 'v=1', 'removed' => 1], json_decode(self::body($response), true));
        self::assertFileDoesNotExist($this->cachePath('.q/v=1/js/app.js'));
        self::assertSame(
            [['level' => 'notice', 'message' => 'purge', 'context' => ['host' => 'cdn.example.test', 'path' => '/js/app.js', 'query' => 'v=1', 'removed' => 1, 'auth' => 'bearer', 'client' => '192.0.2.7']]],
            $this->logger->getRecords()
        );
        self::assertCount(1, $this->origin->getRequests());
    }

    public function testPurgeWithoutAValidKeyIsRefused(): void
    {
        $this->writeCache('css/site.css', 'cached');

        $anonymous = $this->purgingKernel()->handle(Request::create('https://cdn.example.test/css/site.css', 'PURGE'));
        $wrong = $this->purgingKernel()->handle(Request::create('https://cdn.example.test/css/site.css', 'PURGE', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer wrong-secret-0123456789']));
        $disabled = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css', 'PURGE', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . self::PURGE_SECRET]));

        foreach ([$anonymous, $wrong, $disabled] as $response) {
            self::assertSame(401, $response->getStatusCode());
        }
        self::assertFileExists($this->cachePath('css/site.css'));
        self::assertCount(3, $this->logger->recordsWithMessage('purge refused'));
    }

    public function testPurgeOfARasterRemovesItsWebpVariantOnly(): void
    {
        $this->writeCache('images/logo.png', 'PNG');
        $this->writeCache('.webp/images/logo.png.webp', 'WEBP');
        $this->writeCache('images/logo.jpg', 'another URL');

        $response = $this->purgingKernel()->handle(Request::create('https://cdn.example.test/images/logo.png', 'PURGE', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . self::PURGE_SECRET]));

        $document = json_decode(self::body($response), true);
        self::assertIsArray($document);
        self::assertSame(2, $document['removed'] ?? null);
        self::assertFileDoesNotExist($this->cachePath('images/logo.png'));
        self::assertFileDoesNotExist($this->cachePath('.webp/images/logo.png.webp'));
        self::assertFileExists($this->cachePath('images/logo.jpg'));
    }

    public function testPurgeWithAnHmacSignatureRemovesTheEntry(): void
    {
        $this->writeCache('css/site.css', 'cached');
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', PurgeAuthenticator::signedMessage('cdn.example.test', '/css/site.css', $timestamp), self::PURGE_SECRET);

        $response = $this->purgingKernel()->handle(Request::create('https://cdn.example.test/css/site.css', 'PURGE', [], [], [], [
            'HTTP_X_PURGE_TIMESTAMP' => $timestamp,
            'HTTP_X_PURGE_SIGNATURE' => $signature,
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertFileDoesNotExist($this->cachePath('css/site.css'));
        self::assertSame('hmac', $this->logger->recordsWithMessage('purge')[0]['context']['auth'] ?? null);
    }

    public function testPurgeClearsTheNegativeMarker(): void
    {
        $this->purgingKernel()->handle(Request::create('https://cdn.example.test/css/gone.css'));
        $this->origin->serve(self::ORIGIN . '/css/gone.css', self::CSS);

        $this->purgingKernel()->handle(Request::create('https://cdn.example.test/css/gone.css', 'PURGE', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . self::PURGE_SECRET]));

        self::assertSame(200, $this->purgingKernel()->handle(Request::create('https://cdn.example.test/css/gone.css'))->getStatusCode());
    }

    /**
     * A purge of a URL without query string removes the entries of every
     * query string of its path, with their variants and
     * encodings (finding P-16: 2.x left them); a purge of a URL with a
     * query string removes that entry only.
     *
     * @group regression
     */
    #[Group('regression')]
    public function testPurgeCoversEveryQueryStringVariantP16(): void
    {
        $this->origin->serve(self::ORIGIN . '/js/app.js?v=1', str_repeat('versioned;', 100));
        $this->origin->serve(self::ORIGIN . '/js/app.js?v=2', 'short-lived', ['cache-control' => 'max-age=60']);
        $this->origin->serve(self::ORIGIN . '/js/app.js?id=a%2Fb', 'hashed');
        $this->origin->serve(self::ORIGIN . '/js/app.js', 'plain');
        foreach (['?v=1', '?v=2', '?id=a%2Fb', ''] as $query) {
            $this->purgingKernel()->handle(Request::create('https://cdn.example.test/js/app.js' . $query));
        }
        self::assertFileExists($this->cachePath('.q/v=1/.gz/js/app.js'));
        self::assertFileExists($this->cachePath('.q/v=2/js/app.js'));

        $one = $this->purgingKernel()->handle(Request::create('https://cdn.example.test/js/app.js?v=1', 'PURGE', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . self::PURGE_SECRET]));
        self::assertStringContainsString('"removed":2', self::body($one), 'the entry and its gzip encoding');
        self::assertSame('HIT', $this->purgingKernel()->handle(Request::create('https://cdn.example.test/js/app.js?v=2'))->headers->get('x-cache-status'));

        $all = $this->purgingKernel()->handle(Request::create('https://cdn.example.test/js/app.js', 'PURGE', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . self::PURGE_SECRET]));
        self::assertSame(200, $all->getStatusCode());
        foreach (['?v=1', '?v=2', '?id=a%2Fb', ''] as $query) {
            self::assertSame('MISS', $this->purgingKernel()->handle(Request::create('https://cdn.example.test/js/app.js' . $query))->headers->get('x-cache-status'), $query);
        }
        self::assertCount(8, $this->origin->getRequests());
    }

    /**
     * Stylesheets are stored and answered as the origin sent them: the host
     * of the request never ends up in them (findings P-2 and P-3) and the
     * Content-Length is the one of the body (finding P-4).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testStylesheetsAreNotRewrittenP2P3P4(): void
    {
        $css = "a { background: url(/a.png); }\nb { background: url('/b.png'); }\nc { background: url(\"/c.png\"); }\nd { background: url(//cdn/d.png); }\n";
        $this->origin->serve(self::ORIGIN . '/css/rewrite.css', $css, ['content-length' => (string) strlen($css)]);

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/css/rewrite.css'));

        self::assertSame($css, self::body($response));
        self::assertSame($css, file_get_contents($this->cachePath('css/rewrite.css')));
        self::assertSame((string) strlen($css), $response->headers->get('content-length'));
    }

    public function testAWebpCapableClientGetsTheConversionOfAMiss(): void
    {
        $converter = new FakeWebpConverter();

        $response = $this->kernel($converter)->handle(self::webpRequest('https://cdn.example.test/images/logo.png'));

        self::assertSame('FAKE-WEBP:PNG-BYTES', self::body($response));
        self::assertSame('image/webp', $response->headers->get('content-type'));
        self::assertSame('19', $response->headers->get('content-length'));
        self::assertSame('Accept', $response->headers->get('vary'));
        self::assertSame('MISS', $response->headers->get('x-cache-status'));
        self::assertSame([$this->cachePath('images/logo.png')], $converter->getSources());
        self::assertSame('PNG-BYTES', file_get_contents($this->cachePath('images/logo.png')));
    }

    public function testOtherClientsGetTheOriginalAndTheSiblingIsStillWritten(): void
    {
        $response = $this->kernel(new FakeWebpConverter())->handle(Request::create('https://cdn.example.test/images/logo.png'));

        self::assertSame('PNG-BYTES', self::body($response));
        self::assertSame('image/png', $response->headers->get('content-type'));
        self::assertSame('Accept', $response->headers->get('vary'), 'finding P-1: the variant depends on Accept');
        self::assertFileExists($this->cachePath('.webp/images/logo.png.webp'));
    }

    public function testAnExistingSiblingIsServedToWebpCapableClients(): void
    {
        $this->writeCache('images/logo.png', 'STORED-PNG');
        $this->writeCache('.webp/images/logo.png.webp', 'STORED-WEBP');

        $response = $this->kernel()->handle(self::webpRequest('https://cdn.example.test/images/logo.png'));

        self::assertSame('STORED-WEBP', self::body($response));
        self::assertSame('image/webp', $response->headers->get('content-type'));
        self::assertSame('HIT', $response->headers->get('x-cache-status'));
        self::assertSame([], $this->origin->getRequests());
    }

    public function testABucketHitIsConvertedLazily(): void
    {
        $this->origin->serve(self::ORIGIN . '/images/logo.png?v=2', 'PNG-V2');
        $this->kernel()->handle(Request::create('https://cdn.example.test/images/logo.png?v=2'));
        $converter = new FakeWebpConverter();

        $response = $this->kernel($converter)->handle(self::webpRequest('https://cdn.example.test/images/logo.png?v=2'));

        self::assertSame('FAKE-WEBP:PNG-V2', self::body($response));
        self::assertSame('HIT', $response->headers->get('x-cache-status'));
        self::assertCount(1, $converter->getSources());
        self::assertCount(1, $this->origin->getRequests());
    }

    public function testAFailedConversionIsAnInternalError(): void
    {
        $this->origin->serve(self::ORIGIN . '/images/broken.png', 'PNG');

        $response = $this->kernel(new FakeWebpConverter())->handle(self::webpRequest('https://cdn.example.test/images/broken.png'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame("The request could not be processed.\nInternal server error: Image conversion failed: Fake conversion failed.", self::body($response));
    }

    public function testAnUncreatableDirectoryIsReported(): void
    {
        $this->writeCache('css', 'a file where a directory is expected');

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('Error code: Cannot create cache directory.', self::body($response));
    }

    public function testAnUnwritableFileIsReported(): void
    {
        $this->writeCache('css/site.css/occupied', 'a directory where the file is expected');

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('Error code: Cannot create cache file.', self::body($response));
    }

    public function testTheResponseIsReturnedNotSent(): void
    {
        $this->expectOutputString('');

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], headers_list());
    }

    private function purgingKernel(): ProxyKernel
    {
        return TestKernel::create(
            TestConfig::create(['SOURCE_ORIGIN' => self::ORIGIN . '/', 'PURGE_KEYS' => 'origin.example.test=' . self::PURGE_SECRET]),
            $this->directory->path(),
            $this->origin,
            null,
            $this->logger
        );
    }

    private function kernel(?FakeWebpConverter $converter = null): ProxyKernel
    {
        return TestKernel::create(
            // One failure opens the breaker, as in 2.x, to keep the tests short.
            TestConfig::create(['SOURCE_ORIGIN' => self::ORIGIN . '/', 'IMAGE_CONVERSION_MODE' => $converter === null ? 'none' : 'native', 'CIRCUIT_BREAKER_THRESHOLD' => '1']),
            $this->directory->path(),
            $this->origin,
            $converter,
            $this->logger
        );
    }

    private function kernelWithThreshold(int $threshold): ProxyKernel
    {
        return TestKernel::create(
            TestConfig::create(['SOURCE_ORIGIN' => self::ORIGIN . '/', 'CIRCUIT_BREAKER_THRESHOLD' => (string) $threshold]),
            $this->directory->path(),
            $this->origin,
            null,
            $this->logger
        );
    }

    /**
     * Moves the times kept by every breaker by $seconds.
     */
    private function shiftBreakerTimes(int $seconds): void
    {
        foreach (glob($this->directory->path(TestKernel::STATE_DIRECTORY . '/breaker/*.breaker')) ?: [] as $file) {
            $state = json_decode((string) file_get_contents($file), true);
            self::assertIsArray($state);
            foreach (['last_failure_at', 'opened_at', 'probe_at'] as $name) {
                if (isset($state[$name]) && is_int($state[$name]) && $state[$name] !== 0) {
                    $state[$name] += $seconds;
                }
            }
            file_put_contents($file, json_encode($state));
        }
    }

    /**
     * Serves $body at $originUrl and handles a GET of $uri.
     *
     * @param array<string, string> $headers origin headers
     */
    private function handle(string $uri, array $headers, string $originUrl, string $body): Response
    {
        $this->origin->serve($originUrl, $body, $headers);

        return $this->kernel()->handle(Request::create($uri));
    }

    private static function webpRequest(string $uri): Request
    {
        return Request::create($uri, 'GET', [], [], [], ['HTTP_ACCEPT' => 'image/avif,image/webp,*/*']);
    }

    /**
     * Absolute path of a cache file of the host cdn.example.test.
     */
    private function cachePath(string $relativePath): string
    {
        return $this->directory->path('_cache/cdn.example.test/' . $relativePath);
    }

    /**
     * Writes a cache file of the host cdn.example.test.
     */
    private function writeCache(string $relativePath, string $contents): string
    {
        return $this->directory->write('_cache/cdn.example.test/' . $relativePath, $contents);
    }

    /**
     * The body of a response, streamed or not.
     */
    private static function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
