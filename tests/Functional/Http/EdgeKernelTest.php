<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Functional\Http;

use Mfonte\ProxyCdn\Cache\CacheUsage;
use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Cluster\PeerNotifier;
use Mfonte\ProxyCdn\Http\EdgeKernel;
use Mfonte\ProxyCdn\Http\EdgeKernelFactory;
use Mfonte\ProxyCdn\Http\ProxyKernelFactory;
use Mfonte\ProxyCdn\Http\RequestId;
use Mfonte\ProxyCdn\Observability\HealthCheck;
use Mfonte\ProxyCdn\Observability\Metrics;
use Mfonte\ProxyCdn\Observability\MetricsAuthenticator;
use Mfonte\ProxyCdn\Origin\MockTransport;
use Mfonte\ProxyCdn\Security\PurgeAuthenticator;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\ProxyCdn\Tests\Fixtures\Origin\FakeOriginFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * EdgeKernel in-process: /healthz, /metrics and the request ID around
 * ProxyKernel.
 *
 * @covers \Mfonte\ProxyCdn\Http\EdgeKernel
 * @covers \Mfonte\ProxyCdn\Http\EdgeKernelFactory
 */
#[CoversClass(EdgeKernel::class)]
#[CoversClass(EdgeKernelFactory::class)]
final class EdgeKernelTest extends TestCase
{
    private const ORIGIN = 'https://origin.example.test';

    private const METRICS_KEY = 'metrics-key-0123456789';

    private const PURGE_SECRET = 'purge-secret-0123456789';

    private const PEER = 'https://peer.example.test';

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
        $this->origin->serve(self::ORIGIN . '/js/app.js', 'console.log(1);', ['content-type' => 'text/javascript']);
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testEveryResponseCarriesTheRequestId(): void
    {
        $kernel = $this->kernel();

        $adopted = $kernel->handle(self::request('https://cdn.example.test/js/app.js', ['HTTP_X_REQUEST_ID' => 'trace-42']));
        $generated = $kernel->handle(self::request('https://cdn.example.test/js/app.js', ['HTTP_X_REQUEST_ID' => "bad id\r\n"]));
        $error = $kernel->handle(self::request('https://unknown.example.test/js/app.js'));
        $health = $kernel->handle(self::request('https://cdn.example.test/healthz', ['HTTP_X_REQUEST_ID' => 'probe-1']));

        self::assertSame('trace-42', $adopted->headers->get(RequestId::HEADER));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', (string) $generated->headers->get(RequestId::HEADER));
        self::assertSame(421, $error->getStatusCode());
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', (string) $error->headers->get(RequestId::HEADER));
        self::assertSame('probe-1', $health->headers->get(RequestId::HEADER));
    }

    public function testHealthzAnswersOkOnAnyHost(): void
    {
        $response = $this->kernel()->handle(self::request('http://10.0.0.5/healthz?probe=1'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame("{\"status\":\"ok\"}\n", $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame([], $this->origin->getRequestedUrls());
    }

    public function testHealthzReportsAnUnwritableDirectoryWithoutItsPath(): void
    {
        $this->directory->write('public', 'a file where the document root is expected');

        $response = $this->kernel()->handle(self::request('https://cdn.example.test/healthz'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame("{\"status\":\"unavailable\",\"checks\":{\"cache\":\"unwritable\",\"state\":\"ok\"}}\n", $response->getContent());
        self::assertStringNotContainsString($this->directory->path(), (string) $response->getContent());
    }

    public function testHealthzAnswersHeadWithoutBody(): void
    {
        $response = $this->kernel()->handle(self::request('https://cdn.example.test/healthz', [], 'HEAD'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame((string) strlen("{\"status\":\"ok\"}\n"), $response->headers->get('Content-Length'));
    }

    public function testHealthzRefusesOtherMethods(): void
    {
        $response = $this->kernel()->handle(self::request('https://cdn.example.test/healthz', [], 'POST'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->headers->get('Allow'));
    }

    /**
     * Without METRICS_KEYS the path is an asset path like any other: the
     * proxy answers it as ProxyKernel does.
     */
    public function testMetricsAreNotServedWithoutKeys(): void
    {
        $response = $this->kernel()->handle(self::request('https://cdn.example.test/metrics', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::METRICS_KEY]));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('proxy_cdn_', (string) $response->getContent());
    }

    public function testMetricsNeedAKey(): void
    {
        $kernel = $this->kernel(['METRICS_KEYS' => self::METRICS_KEY]);

        $anonymous = $kernel->handle(self::request('https://cdn.example.test/metrics'));
        $wrong = $kernel->handle(self::request('https://cdn.example.test/metrics', ['HTTP_AUTHORIZATION' => 'Bearer purge-secret-0123456789']));

        self::assertSame(401, $anonymous->getStatusCode());
        self::assertSame('Bearer realm="proxy-cdn metrics"', $anonymous->headers->get('WWW-Authenticate'));
        self::assertSame(401, $wrong->getStatusCode());
        self::assertStringNotContainsString('proxy_cdn_', (string) $wrong->getContent());
    }

    /**
     * The requests and origin fetches of every PHP process are counted in
     * the shared store and rendered for a scraper; nothing in the
     * exposition names a path, a host or a version.
     */
    public function testMetricsCountTheRequestsOfEveryProcess(): void
    {
        $overrides = ['METRICS_KEYS' => self::METRICS_KEY];
        $this->serveAndTerminate($overrides, 'https://cdn.example.test/js/app.js');
        $this->serveAndTerminate($overrides, 'https://cdn.example.test/js/app.js');
        $this->serveAndTerminate($overrides, 'https://cdn.example.test/js/missing.js');
        (new CacheUsage(12345, 3, 1700000000))->write(new FilesystemStorage(), $this->directory->path('var/' . CacheUsage::FILE_NAME));

        $kernel = $this->kernel($overrides);
        $scrape = $kernel->handle(self::request('https://cdn.example.test/metrics', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::METRICS_KEY]));
        $text = (string) $scrape->getContent();

        self::assertSame(200, $scrape->getStatusCode());
        self::assertSame(EdgeKernel::METRICS_CONTENT_TYPE, $scrape->headers->get('Content-Type'));
        self::assertStringContainsString("proxy_cdn_up 1\n", $text);
        self::assertStringContainsString("proxy_cdn_metrics_available 1\n", $text);
        self::assertStringContainsString("proxy_cdn_requests_total{method=\"GET\",cache=\"MISS\",status=\"200\"} 1\n", $text);
        self::assertStringContainsString("proxy_cdn_requests_total{method=\"GET\",cache=\"HIT\",status=\"200\"} 1\n", $text);
        self::assertStringContainsString("proxy_cdn_requests_total{method=\"GET\",cache=\"none\",status=\"404\"} 1\n", $text);
        self::assertStringContainsString("proxy_cdn_origin_fetches_total{outcome=\"success\"} 1\n", $text);
        self::assertStringContainsString("proxy_cdn_origin_fetches_total{outcome=\"not_found\"} 1\n", $text);
        self::assertStringContainsString("proxy_cdn_request_duration_seconds_count{cache=\"MISS\"} 1\n", $text);
        self::assertStringContainsString("proxy_cdn_cache_bytes 12345\n", $text);
        self::assertStringContainsString("proxy_cdn_cache_entries 3\n", $text);
        self::assertStringNotContainsString('app.js', $text);
        self::assertStringNotContainsString('example.test', $text);
        self::assertStringNotContainsString($this->directory->path(), $text);
        self::assertSame(1, preg_match_all('/^# TYPE proxy_cdn_requests_total counter$/m', $text));
    }

    /**
     * With METRICS_STORE=off nothing is written to var/metrics/, and
     * /metrics only renders the gauges.
     */
    public function testMetricsCanBeTurnedOff(): void
    {
        $overrides = ['METRICS_KEYS' => self::METRICS_KEY, 'METRICS_STORE' => 'off'];
        $this->serveAndTerminate($overrides, 'https://cdn.example.test/js/app.js');

        $text = (string) $this->kernel($overrides)->handle(self::request('https://cdn.example.test/metrics', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::METRICS_KEY]))->getContent();

        self::assertStringContainsString("proxy_cdn_up 1\n", $text);
        self::assertStringNotContainsString('proxy_cdn_requests_total', $text);
        self::assertDirectoryDoesNotExist($this->directory->path('var/metrics'));
    }

    /**
     * Any other request is ProxyKernel's: same status, headers and body.
     */
    public function testOtherRequestsAreAnsweredByTheProxyKernel(): void
    {
        $response = $this->kernel()->handle(self::request('https://cdn.example.test/js/app.js'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('MISS', $response->headers->get('X-Cache-Status'));
        self::assertSame('console.log(1);', self::body($response));
    }

    /**
     * MISS_RATE_LIMIT bounds the origin fetches of one client; its hits are
     * never limited, and another client has its own bucket.
     */
    public function testTheMissRateLimitAppliesToMissesOnly(): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $this->origin->serve(self::ORIGIN . '/js/' . $name . '.js', $name, ['content-type' => 'text/javascript']);
        }
        $overrides = ['MISS_RATE_LIMIT' => '6', 'MISS_RATE_LIMIT_BURST' => '2', 'METRICS_KEYS' => self::METRICS_KEY];
        $kernel = $this->kernel($overrides);
        $client = ['REMOTE_ADDR' => '192.0.2.10'];

        $first = $kernel->handle(self::request('https://cdn.example.test/js/a.js', $client));
        $second = $kernel->handle(self::request('https://cdn.example.test/js/b.js', $client));
        $limited = $kernel->handle(self::request('https://cdn.example.test/js/c.js', $client));
        $hit = $kernel->handle(self::request('https://cdn.example.test/js/a.js', $client));
        $otherClient = $kernel->handle(self::request('https://cdn.example.test/js/c.js', ['REMOTE_ADDR' => '192.0.2.11']));
        $kernel->terminate();

        self::assertSame([200, 200, 429, 200, 200], [
            $first->getStatusCode(), $second->getStatusCode(), $limited->getStatusCode(), $hit->getStatusCode(), $otherClient->getStatusCode(),
        ]);
        self::assertSame('10', $limited->headers->get('Retry-After'));
        self::assertSame('HIT', $hit->headers->get('X-Cache-Status'));
        self::assertSame([self::ORIGIN . '/js/a.js', self::ORIGIN . '/js/b.js', self::ORIGIN . '/js/c.js'], $this->origin->getRequestedUrls());
        $text = (string) $this->kernel($overrides)->handle(self::request('https://cdn.example.test/metrics', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::METRICS_KEY]))->getContent();
        self::assertStringContainsString("proxy_cdn_miss_rate_limited_total 1\n", $text);
        self::assertStringContainsString("proxy_cdn_requests_total{method=\"GET\",cache=\"none\",status=\"429\"} 1\n", $text);
    }

    /**
     * The "done when" of PRX-9: with CLUSTER_PEERS empty the edge behaves
     * like a single box. The same requests get the same statuses, bodies
     * and headers (but the request ID) as from ProxyKernel alone, leave the
     * same cache behind, fetch the same origin URLs, and no peer is known
     * to send anything to.
     */
    public function testWithoutPeersTheEdgeBehavesLikeASingleBox(): void
    {
        $overrides = ['PURGE_KEYS' => 'origin.example.test=' . self::PURGE_SECRET];
        $config = TestConfig::create($overrides);
        self::assertNull(PeerNotifier::fromConfig($config, $this->logger));
        $requests = static function (): array {
            return [
                self::request('https://cdn.example.test/js/app.js'),
                self::request('https://cdn.example.test/js/app.js'),
                self::request('https://cdn.example.test/js/app.js', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::PURGE_SECRET], 'PURGE'),
                self::request('https://cdn.example.test/js/app.js', ['HTTP_AUTHORIZATION' => 'Bearer wrong-secret-0123456789'], 'PURGE'),
                self::request('https://cdn.example.test/js/app.js', [], 'HEAD'),
                self::request('https://cdn.example.test/js/missing.js'),
                self::request('https://unknown.example.test/js/app.js'),
            ];
        };

        $edgeOrigin = $this->origin;
        $singleOrigin = new FakeOriginFetcher();
        $singleOrigin->serve(self::ORIGIN . '/js/app.js', 'console.log(1);', ['content-type' => 'text/javascript']);
        $edge = EdgeKernelFactory::create(
            $config,
            $this->directory->path('edge/public'),
            $this->directory->path('edge/var'),
            $edgeOrigin,
            null,
            $this->logger,
            Metrics::fromConfig($config, $this->directory->path('edge/var'))
        );
        $single = ProxyKernelFactory::create($config, $this->directory->path('single/public'), $this->directory->path('single/var'), $singleOrigin, null, $this->logger);
        $edgeAnswers = [];
        $singleAnswers = [];
        // One request to each in turn, so that both see the same clock.
        foreach ($requests() as $index => $request) {
            $edgeAnswers[] = self::comparable($edge->handle($request));
            $singleAnswers[] = self::comparable($single->handle($requests()[$index]));
        }
        $edge->terminate();
        $single->terminate();

        self::assertSame($singleAnswers, $edgeAnswers);
        self::assertSame([200, 200, 200, 401, 200, 404, 421], array_column($edgeAnswers, 0));
        self::assertSame($singleOrigin->getRequestedUrls(), $edgeOrigin->getRequestedUrls());
        self::assertNotSame([], self::tree($this->directory->path('edge/public')));
        self::assertSame(self::tree($this->directory->path('single/public')), self::tree($this->directory->path('edge/public')));
        self::assertSame([], $this->logger->recordsWithMessage('peer purge failed'));
    }

    /**
     * The cache files of the edge and of the single box may be written on
     * either side of the end of a second (a loaded machine): the answers
     * still compare equal, their validators aside, which differ.
     */
    public function testTheComparisonOfTheAnswersIgnoresOnlyTheTimeOfTheFiles(): void
    {
        $config = TestConfig::create();
        $singleOrigin = new FakeOriginFetcher();
        $singleOrigin->serve(self::ORIGIN . '/js/app.js', 'console.log(1);', ['content-type' => 'text/javascript']);
        $edge = EdgeKernelFactory::create(
            $config,
            $this->directory->path('edge/public'),
            $this->directory->path('edge/var'),
            $this->origin,
            null,
            $this->logger,
            Metrics::fromConfig($config, $this->directory->path('edge/var'))
        );
        $single = ProxyKernelFactory::create($config, $this->directory->path('single/public'), $this->directory->path('single/var'), $singleOrigin, null, $this->logger);
        $edge->handle(self::request('https://cdn.example.test/js/app.js'));
        // The edge wrote its file in the previous second.
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory->path('edge/public'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                touch($file->getPathname(), time() - 1);
            }
        }
        $single->handle(self::request('https://cdn.example.test/js/app.js'));
        clearstatcache();

        $edgeHit = $edge->handle(self::request('https://cdn.example.test/js/app.js'));
        $singleHit = $single->handle(self::request('https://cdn.example.test/js/app.js'));

        self::assertNotSame($singleHit->headers->get('ETag'), $edgeHit->headers->get('ETag'));
        self::assertNotSame($singleHit->headers->get('Last-Modified'), $edgeHit->headers->get('Last-Modified'));
        self::assertSame(self::comparable($singleHit), self::comparable($edgeHit));
        self::assertSame(['"<mtime>-f"'], self::comparable($edgeHit)[2]['etag']);
        $edgeHit->headers->set('ETag', '"1-10"');
        self::assertNotSame(self::comparable($singleHit), self::comparable($edgeHit));
    }

    /**
     * An accepted purge reaches every peer once the response is sent
     * (terminate()); a refused purge and a purge that came from a peer are
     * not sent on, so purges cannot loop.
     */
    public function testAnAcceptedPurgeIsFannedOutAfterTheResponse(): void
    {
        $overrides = ['PURGE_KEYS' => 'origin.example.test=' . self::PURGE_SECRET, 'METRICS_KEYS' => self::METRICS_KEY];
        $transport = new MockTransport();
        $transport->respond(self::PEER . '/js/app.js', 200);
        $kernel = $this->kernelWithPeers($overrides, $transport);
        $timestamp = (string) time();
        $fromPeer = self::request('https://cdn.example.test/js/app.js', [
            'HTTP_X_PURGE_FANOUT' => '1',
            'HTTP_X_PURGE_TIMESTAMP' => $timestamp,
            'HTTP_X_PURGE_SIGNATURE' => hash_hmac('sha256', PurgeAuthenticator::fanoutMessage('cdn.example.test', '/js/app.js', $timestamp, ''), self::PURGE_SECRET),
        ], 'PURGE');

        $accepted = $kernel->handle(self::request('https://cdn.example.test/js/app.js', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::PURGE_SECRET, 'HTTP_X_REQUEST_ID' => 'purge-1'], 'PURGE'));
        $refused = $kernel->handle(self::request('https://cdn.example.test/js/app.js', ['HTTP_AUTHORIZATION' => 'Bearer wrong-secret-0123456789'], 'PURGE'));
        $fannedIn = $kernel->handle($fromPeer);
        $sentBeforeTerminate = $transport->getRequests();
        $kernel->terminate();
        $sent = $transport->getRequests();

        self::assertSame([200, 401, 200], [$accepted->getStatusCode(), $refused->getStatusCode(), $fannedIn->getStatusCode()]);
        self::assertCount(1, $sent);
        self::assertSame('PURGE', $sent[0]->getMethod());
        self::assertSame(self::PEER . '/js/app.js', $sent[0]->getUrl());
        self::assertSame('purge-1', $sent[0]->getHeader('x-request-id'));
        $text = (string) $this->kernel($overrides)->handle(self::request('https://cdn.example.test/metrics', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::METRICS_KEY]))->getContent();
        self::assertStringContainsString("proxy_cdn_purges_total{result=\"purged\"} 2\n", $text);
        self::assertStringContainsString("proxy_cdn_purges_total{result=\"refused\"} 1\n", $text);
        self::assertStringContainsString("proxy_cdn_peer_purges_total{result=\"ok\"} 1\n", $text);
        self::assertSame([], $sentBeforeTerminate);
    }

    /**
     * The fan-out sends the URL of the purge, never a path of the cache:
     * the pending copy and the staged variants of an image (PRX-8) go
     * with the local purge, and each peer purges its own; a request for
     * a pending path itself is refused, so it is never sent on.
     */
    public function testTheFanOutNeverCarriesAPendingPath(): void
    {
        $overrides = ['PURGE_KEYS' => 'origin.example.test=' . self::PURGE_SECRET];
        $pending = [
            $this->directory->write('public/_cache/cdn.example.test/.pending/images/a.png', 'png'),
            $this->directory->write('public/_cache/cdn.example.test/.pending/.webp/images/a.png.webp', 'webp'),
        ];
        $transport = new MockTransport();
        $transport->respond(self::PEER . '/images/a.png', 200);
        $kernel = $this->kernelWithPeers($overrides, $transport);
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer ' . self::PURGE_SECRET];

        $purged = $kernel->handle(self::request('https://cdn.example.test/images/a.png', $auth, 'PURGE'));
        $direct = $kernel->handle(self::request('https://cdn.example.test/.pending/images/a.png', $auth, 'PURGE'));
        $kernel->terminate();

        self::assertSame(200, $purged->getStatusCode());
        self::assertStringNotContainsString('.pending', (string) $purged->getContent());
        foreach ($pending as $file) {
            self::assertFileDoesNotExist($file);
        }
        self::assertGreaterThanOrEqual(400, $direct->getStatusCode());
        $sent = $transport->getRequests();
        self::assertCount(1, $sent);
        self::assertSame(self::PEER . '/images/a.png', $sent[0]->getUrl());
    }

    /**
     * A peer that fails is logged and counted; the local purge stands.
     */
    public function testAFailingPeerDoesNotUndoTheLocalPurge(): void
    {
        $overrides = ['PURGE_KEYS' => 'origin.example.test=' . self::PURGE_SECRET, 'METRICS_KEYS' => self::METRICS_KEY];
        $transport = new MockTransport();
        $transport->respond(self::PEER . '/js/app.js', 503);
        $kernel = $this->kernelWithPeers($overrides, $transport);
        $kernel->handle(self::request('https://cdn.example.test/js/app.js'));

        $purge = $kernel->handle(self::request('https://cdn.example.test/js/app.js', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::PURGE_SECRET], 'PURGE'));
        $kernel->terminate();
        $after = $kernel->handle(self::request('https://cdn.example.test/js/app.js'));

        self::assertSame(200, $purge->getStatusCode());
        self::assertStringContainsString('"removed":', (string) $purge->getContent());
        self::assertSame('MISS', $after->headers->get('X-Cache-Status'));
        self::assertCount(1, $this->logger->recordsWithMessage('peer purge failed'));
        $text = (string) $this->kernel($overrides)->handle(self::request('https://cdn.example.test/metrics', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::METRICS_KEY]))->getContent();
        self::assertStringContainsString("proxy_cdn_peer_purges_total{result=\"failed\"} 1\n", $text);
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private function kernelWithPeers(array $overrides, MockTransport $transport): EdgeKernel
    {
        $config = TestConfig::create($overrides);
        $metrics = Metrics::fromConfig($config, $this->directory->path('var'));

        return new EdgeKernel(
            ProxyKernelFactory::create($config, $this->directory->path('public'), $this->directory->path('var'), $this->origin, null, $this->logger),
            new HealthCheck($this->directory->path('public/_cache'), $this->directory->path('var')),
            $metrics,
            new MetricsAuthenticator($config->getOperations()->getMetricsKeys()),
            new FilesystemStorage(),
            $this->directory->path('var/' . CacheUsage::FILE_NAME),
            $this->logger,
            new PeerNotifier([self::PEER], PurgeAuthenticator::fromConfig($config), $config->getHostAllowlist(), $transport, null, $this->logger)
        );
    }

    /**
     * Status, body and headers of a response, without the headers that
     * differ from one run to the next. The headers are only compared,
     * never read, so their type is that of HeaderBag::all() in every
     * supported symfony/http-foundation version.
     *
     * @return array{int, string, array<mixed>}
     */
    private static function comparable(Response $response): array
    {
        $headers = $response->headers->all();
        unset($headers['date'], $headers['x-request-id']);
        // The validators of a cache file carry its time, which two stores
        // written a moment apart do not share when a second ends between
        // them: compare their form and the size, not the time.
        $etag = $response->headers->get('ETag');
        if (is_string($etag)) {
            $headers['etag'] = [(string) preg_replace('/^"[0-9a-f]+-/', '"<mtime>-', $etag)];
        }
        $lastModified = $response->headers->get('Last-Modified');
        if (is_string($lastModified)) {
            $headers['last-modified'] = [\DateTime::createFromFormat('D, d M Y H:i:s \G\M\T', $lastModified) !== false ? '<mtime>' : $lastModified];
        }
        ksort($headers);
        $body = self::body($response);
        // The Ray ID of an error without path is random.
        $body = (string) preg_replace('/Ray ID: [0-9a-f]{32}/', 'Ray ID: x', $body);

        return [$response->getStatusCode(), $body, $headers];
    }

    /**
     * The relative paths and contents of the files under $root.
     *
     * @return array<string, string>
     */
    private static function tree(string $root): array
    {
        $files = [];
        if (!is_dir($root)) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                // The time of validation of the metadata is the only content that depends on the clock.
                $files[substr($file->getPathname(), strlen($root))] = (string) preg_replace('/"validated_at":[0-9]+/', '"validated_at":0', (string) file_get_contents($file->getPathname()));
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private function serveAndTerminate(array $overrides, string $url): void
    {
        $kernel = $this->kernel($overrides);
        $kernel->handle(self::request($url));
        $kernel->terminate();
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private function kernel(array $overrides = []): EdgeKernel
    {
        $config = TestConfig::create($overrides);

        return EdgeKernelFactory::create(
            $config,
            $this->directory->path('public'),
            $this->directory->path('var'),
            $this->origin,
            null,
            $this->logger,
            Metrics::fromConfig($config, $this->directory->path('var'))
        );
    }

    /**
     * @param array<string, string> $server
     */
    private static function request(string $url, array $server = [], string $method = 'GET'): Request
    {
        return Request::create($url, $method, [], [], [], $server);
    }

    private static function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
