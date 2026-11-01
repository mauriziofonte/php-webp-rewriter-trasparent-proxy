<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Functional\Http;

use Mfonte\ProxyCdn\Http\ProxyKernel;
use Mfonte\ProxyCdn\Origin\OriginResponse;
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
        self::assertSame('console.log(1);', $response->getContent());
        self::assertSame('miss', $response->headers->get('x-cache-status'));
        self::assertSame('max-age=31536000, must-revalidate, public', $response->headers->get('cache-control'));
        self::assertSame('console.log(1);', file_get_contents($this->directory->path('js/app.js')));
        self::assertSame([self::ORIGIN . '/js/app.js'], $this->origin->getRequestedUrls());
    }

    public function testOnlyTheWhitelistedOriginHeadersAreForwarded(): void
    {
        $this->origin->serve(self::ORIGIN . '/js/app.js', 'x', [
            'content-type' => 'application/javascript',
            'etag' => '"e"',
            'last-modified' => 'Tue, 01 Sep 2026 10:00:00 GMT',
            'vary' => 'Origin',
            'accept-ranges' => 'bytes',
            'content-disposition' => '',
            'content-length' => '0',
            'set-cookie' => 'session=secret',
            'x-origin-only' => 'yes',
        ]);

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js'));

        self::assertSame('application/javascript', $response->headers->get('content-type'));
        self::assertSame('"e"', $response->headers->get('etag'));
        self::assertSame('Tue, 01 Sep 2026 10:00:00 GMT', $response->headers->get('last-modified'));
        self::assertSame('Origin', $response->headers->get('vary'));
        self::assertSame('bytes', $response->headers->get('accept-ranges'));
        self::assertFalse($response->headers->has('content-disposition'), 'empty values are dropped');
        self::assertFalse($response->headers->has('content-length'), '"0" is dropped, as in 2.x');
        self::assertFalse($response->headers->has('set-cookie'));
        self::assertFalse($response->headers->has('x-origin-only'));
    }

    public function testTheOriginRequestCarriesTheCanonicalQueryAndTheReferer(): void
    {
        $this->origin->serve(self::ORIGIN . '/js/app.js?a=1&b=2', 'x');

        $this->kernel()->handle(Request::create('http://cdn.example.test:8080/js/app.js?b=2&a=1'));

        $requests = $this->origin->getRequests();
        self::assertCount(1, $requests);
        self::assertSame(self::ORIGIN . '/js/app.js?a=1&b=2', $requests[0]->getUrl());
        self::assertSame(['Referer' => 'http://cdn.example.test:8080'], $requests[0]->getHeaders());
    }

    /**
     * Without query string the web server serves the stored file itself;
     * when the request reaches PHP anyway, 2.x fetches it again.
     */
    public function testAPathWithoutQueryIsFetchedEveryTime(): void
    {
        $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css'));
        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css'));

        self::assertSame('miss', $response->headers->get('x-cache-status'));
        self::assertCount(2, $this->origin->getRequests());
    }

    public function testAQueryStringBucketIsServedFromDisk(): void
    {
        $this->origin->serve(self::ORIGIN . '/js/app.js?v=1', 'versioned', ['content-type' => 'application/javascript']);
        $this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js?v=1'));

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js?v=1&purge=0'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('versioned', $response->getContent());
        self::assertSame('hit', $response->headers->get('x-cache-status'));
        self::assertSame('text/javascript', $response->headers->get('content-type'));
        self::assertSame('9', $response->headers->get('content-length'));
        self::assertSame('Accept', $response->headers->get('vary'));
        self::assertSame('max-age=31536000, must-revalidate, public', $response->headers->get('cache-control'));
        self::assertCount(1, $this->origin->getRequests());
        self::assertFileExists($this->directory->path('_qs/' . substr(hash('sha256', 'v=1'), 0, 16) . '/js/app.js'));
    }

    public function testOptionsGetsThePreflightAnswer(): void
    {
        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css', 'OPTIONS'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('*', $response->headers->get('access-control-allow-origin'));
        self::assertSame('86400', $response->headers->get('access-control-max-age'));
        self::assertSame([], $this->origin->getRequests());
    }

    public function testOtherMethodsAreRefused(): void
    {
        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css', 'POST'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD, OPTIONS', $response->headers->get('allow'));
        self::assertSame([], $this->origin->getRequests());
    }

    public function testHeadHasTheHeadersOfTheGetWithoutBody(): void
    {
        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css', 'HEAD'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame((string) strlen(self::CSS), $response->headers->get('content-length'));
        self::assertFileExists($this->directory->path('css/site.css'));
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
            $response->getContent()
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
        self::assertStringContainsString('Error code: Cannot read remote origin file.', (string) $first->getContent());
        self::assertSame(404, $second->getStatusCode());
        self::assertCount(1, $this->origin->getRequests());
        self::assertFileExists($this->directory->path('missing.css.404'));
    }

    public function testAnOriginFailureOpensTheBreaker(): void
    {
        $this->origin->respond(self::ORIGIN . '/js/app.js', OriginResponse::failure(OriginResponse::FAILURE_TIMEOUT, 0, 28, 'timed out'));

        $first = $this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js'));
        $second = $this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js'));

        self::assertSame(503, $first->getStatusCode());
        self::assertSame('30', $first->headers->get('retry-after'));
        self::assertStringContainsString('Error code: Upstream temporarily unavailable.', (string) $first->getContent());
        self::assertSame(503, $second->getStatusCode());
        self::assertCount(1, $this->origin->getRequests());
        self::assertFileExists($this->directory->path('js/app.js.netfail'));
    }

    /**
     * A redirect opens the breaker like a network failure (finding P-13,
     * resolved by PRX-5).
     */
    public function testARedirectOpensTheBreakerP13(): void
    {
        $this->origin->respond(self::ORIGIN . '/js/app.js', OriginResponse::failure(OriginResponse::FAILURE_REDIRECT, 301));

        self::assertSame(503, $this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js'))->getStatusCode());
        self::assertFileExists($this->directory->path('js/app.js.netfail'));
    }

    public function testASuccessClearsStaleMarkers(): void
    {
        $this->directory->write('css/site.css.404', '');
        $this->directory->write('css/site.css.netfail', '');
        touch($this->directory->path('css/site.css.404'), time() - 3600);
        touch($this->directory->path('css/site.css.netfail'), time() - 3600);

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css'));

        self::assertSame(200, $response->getStatusCode());
        self::assertFileDoesNotExist($this->directory->path('css/site.css.404'));
        self::assertFileDoesNotExist($this->directory->path('css/site.css.netfail'));
    }

    /**
     * Anyone can purge (finding L-7, resolved by PRX-5).
     */
    public function testPurgeRemovesTheEntryAndItsMarkers(): void
    {
        $this->origin->serve(self::ORIGIN . '/js/app.js?v=1', 'old');
        $this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js?v=1'));
        $bucket = $this->directory->path('_qs/' . substr(hash('sha256', 'v=1'), 0, 16));
        $this->directory->write(substr($bucket, strlen($this->directory->path())) . '/js/app.js.netfail', '');
        $this->origin->serve(self::ORIGIN . '/js/app.js?v=1', 'new');

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/js/app.js?v=1&purge=TRUE'));

        self::assertSame('new', $response->getContent());
        self::assertSame('miss', $response->headers->get('x-cache-status'));
        self::assertCount(2, $this->origin->getRequests());
        self::assertSame(
            [['level' => 'info', 'message' => 'purge', 'context' => ['file' => $bucket . '/js/app.js', 'aggressive' => false]]],
            $this->logger->getRecords()
        );
    }

    public function testPurgeAllRemovesTheSiblings(): void
    {
        $this->directory->write('images/logo.png', 'PNG');
        $this->directory->write('images/logo.webp', 'WEBP');

        $this->kernel()->handle(Request::create('https://cdn.example.test/images/logo.jpg?purge_all=1'));

        self::assertFileDoesNotExist($this->directory->path('images/logo.png'));
        self::assertFileDoesNotExist($this->directory->path('images/logo.webp'));
        self::assertTrue($this->logger->getRecords()[0]['context']['aggressive'] ?? null);
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

        $response = $this->kernel()->handle(Request::create('https://attacker.example.test/css/rewrite.css'));

        self::assertSame($css, $response->getContent());
        self::assertSame($css, file_get_contents($this->directory->path('css/rewrite.css')));
        self::assertSame((string) strlen($css), $response->headers->get('content-length'));
    }

    public function testAWebpCapableClientGetsTheConversionOfAMiss(): void
    {
        $converter = new FakeWebpConverter();

        $response = $this->kernel($converter)->handle(self::webpRequest('https://cdn.example.test/images/logo.png'));

        self::assertSame('FAKE-WEBP:PNG-BYTES', $response->getContent());
        self::assertSame('image/webp', $response->headers->get('content-type'));
        self::assertSame('19', $response->headers->get('content-length'));
        self::assertSame('Accept', $response->headers->get('vary'));
        self::assertSame('miss', $response->headers->get('x-cache-status'));
        self::assertSame([$this->directory->path('images/logo.png')], $converter->getSources());
        self::assertSame('PNG-BYTES', file_get_contents($this->directory->path('images/logo.png')));
    }

    public function testOtherClientsGetTheOriginalAndTheSiblingIsStillWritten(): void
    {
        $response = $this->kernel(new FakeWebpConverter())->handle(Request::create('https://cdn.example.test/images/logo.png'));

        self::assertSame('PNG-BYTES', $response->getContent());
        self::assertSame('image/png', $response->headers->get('content-type'));
        self::assertFalse($response->headers->has('vary'));
        self::assertFileExists($this->directory->path('images/logo.webp'));
    }

    public function testAnExistingSiblingIsServedToWebpCapableClients(): void
    {
        $this->directory->write('images/logo.webp', 'STORED-WEBP');

        $response = $this->kernel()->handle(self::webpRequest('https://cdn.example.test/images/logo.png'));

        self::assertSame('STORED-WEBP', $response->getContent());
        self::assertSame('image/webp', $response->headers->get('content-type'));
        self::assertSame('hit', $response->headers->get('x-cache-status'));
        self::assertSame([], $this->origin->getRequests());
    }

    public function testABucketHitIsConvertedLazily(): void
    {
        $this->origin->serve(self::ORIGIN . '/images/logo.png?v=2', 'PNG-V2');
        $this->kernel()->handle(Request::create('https://cdn.example.test/images/logo.png?v=2'));
        $converter = new FakeWebpConverter();

        $response = $this->kernel($converter)->handle(self::webpRequest('https://cdn.example.test/images/logo.png?v=2'));

        self::assertSame('FAKE-WEBP:PNG-V2', $response->getContent());
        self::assertSame('hit', $response->headers->get('x-cache-status'));
        self::assertCount(1, $converter->getSources());
        self::assertCount(1, $this->origin->getRequests());
    }

    public function testAFailedConversionIsAnInternalError(): void
    {
        $this->origin->serve(self::ORIGIN . '/images/broken.png', 'PNG');

        $response = $this->kernel(new FakeWebpConverter())->handle(self::webpRequest('https://cdn.example.test/images/broken.png'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame("The request could not be processed.\nInternal server error: Image conversion failed: Fake conversion failed.", $response->getContent());
    }

    public function testAnUncreatableDirectoryIsReported(): void
    {
        $this->directory->write('css', 'a file where a directory is expected');

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('Error code: Cannot create cache directory.', (string) $response->getContent());
    }

    public function testAnUnwritableFileIsReported(): void
    {
        $this->directory->write('css/site.css/occupied', 'a directory where the file is expected');

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('Error code: Cannot create cache file.', (string) $response->getContent());
    }

    public function testTheResponseIsReturnedNotSent(): void
    {
        $this->expectOutputString('');

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/css/site.css'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], headers_list());
    }

    private function kernel(?FakeWebpConverter $converter = null): ProxyKernel
    {
        return TestKernel::create(
            TestConfig::create(['SOURCE_ORIGIN' => self::ORIGIN . '/', 'IMAGE_CONVERSION_MODE' => $converter === null ? 'none' : 'native']),
            $this->directory->path(),
            $this->origin,
            $converter,
            $this->logger
        );
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
}
