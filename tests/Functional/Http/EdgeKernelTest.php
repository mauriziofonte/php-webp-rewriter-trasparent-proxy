<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Functional\Http;

use Mfonte\ProxyCdn\Http\EdgeKernel;
use Mfonte\ProxyCdn\Http\EdgeKernelFactory;
use Mfonte\ProxyCdn\Http\RequestId;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\ProxyCdn\Tests\Fixtures\Origin\FakeOriginFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * EdgeKernel in-process: /healthz and the request ID around ProxyKernel.
 *
 * @covers \Mfonte\ProxyCdn\Http\EdgeKernel
 * @covers \Mfonte\ProxyCdn\Http\EdgeKernelFactory
 */
#[CoversClass(EdgeKernel::class)]
#[CoversClass(EdgeKernelFactory::class)]
final class EdgeKernelTest extends TestCase
{
    private const ORIGIN = 'https://origin.example.test';

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
            $this->logger
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
