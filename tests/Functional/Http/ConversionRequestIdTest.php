<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Functional\Http;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Http\EdgeKernelFactory;
use Mfonte\ProxyCdn\Http\ProxyKernel;
use Mfonte\ProxyCdn\Http\RequestId;
use Mfonte\ProxyCdn\Image\ApiClient;
use Mfonte\ProxyCdn\Image\CapabilityCache;
use Mfonte\ProxyCdn\Image\RemoteConverter;
use Mfonte\ProxyCdn\Observability\JsonLogger;
use Mfonte\ProxyCdn\Observability\Metrics;
use Mfonte\ProxyCdn\Origin\OriginCircuitBreaker;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\FakeApi\FakeApiServer;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Image\FakeImageConverter;
use Mfonte\ProxyCdn\Tests\Fixtures\Origin\FakeOriginFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The request ID of PRX-9 on the conversion path of PRX-8, wired as the
 * front controller wires it: one JsonLogger with the ID of the request
 * for the kernel and the converter, and the ID in the request attribute
 * that the kernel hands to the conversions made after the response.
 *
 * @covers \Mfonte\ProxyCdn\Http\EdgeKernel
 * @covers \Mfonte\ProxyCdn\Http\ProxyKernel
 *
 * @group network
 */
#[CoversClass(ProxyKernel::class)]
#[Group('network')]
final class ConversionRequestIdTest extends TestCase
{
    private const ORIGIN = 'https://origin.example.test';

    private const KEY = 'api-key-0123456789abcdef';

    private const IMAGE = __DIR__ . '/../../Fixtures/Image/files/image.png';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var FakeApiServer
     */
    private $api;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        mkdir($this->directory->path('api'));
        $this->api = FakeApiServer::start($this->directory->path('api'));
        $capabilities = FakeImageConverter::capabilities(['webp']);
        $this->api->answer('/healthz', 200, ['Content-Type' => 'application/json'], (string) json_encode(['status' => 'ok', 'capabilities' => $capabilities->toArray()]));
        $this->api->answer('/v2/convert', 500, ['Content-Type' => 'application/json'], '{"error":"internal","message":"down"}');
    }

    protected function tear_down(): void
    {
        $this->api->stop();
        $this->directory->remove();
        parent::tear_down();
    }

    /**
     * The ID the client sends reaches the API in X-Request-Id, from the
     * conversion made after the response, and every log entry of the
     * request, those of the conversion included, carries it.
     */
    public function testTheIdOfTheClientReachesTheApiAndTheLogs(): void
    {
        $request = Request::create('https://cdn.example.test/images/logo.png', 'GET', [], [], [], ['HTTP_ACCEPT' => 'image/webp,*/*', 'HTTP_X_REQUEST_ID' => 'client-7']);

        $response = $this->serve($request);

        self::assertSame('client-7', $response);
        $posts = array_values(array_filter($this->api->getRequests(), static function (array $call): bool {
            return $call['path'] === '/v2/convert';
        }));
        self::assertNotEmpty($posts, 'a variant was tried after the response');
        foreach ($posts as $post) {
            self::assertSame('client-7', $post['headers']['x-request-id'] ?? null);
        }
        $entries = $this->logEntries();
        self::assertContains('image conversion failed, the original is served', array_column($entries, 'message'));
        foreach ($entries as $entry) {
            self::assertSame('client-7', $entry['request_id'] ?? null, (string) json_encode($entry));
        }
    }

    /**
     * Without an ID from the client, the one the proxy generates and sends
     * back is the one the API receives.
     */
    public function testAGeneratedIdReachesTheApi(): void
    {
        $request = Request::create('https://cdn.example.test/images/logo.png', 'GET', [], [], [], ['HTTP_ACCEPT' => 'image/webp,*/*']);

        $sent = $this->serve($request);

        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $sent);
        $posts = array_values(array_filter($this->api->getRequests(), static function (array $call): bool {
            return $call['path'] === '/v2/convert';
        }));
        self::assertNotEmpty($posts);
        self::assertSame($sent, $posts[0]['headers']['x-request-id'] ?? null);
    }

    /**
     * Serves $request and runs the work after the response, as public/
     * index.php does; the X-Request-Id of the response.
     */
    private function serve(Request $request): string
    {
        $origin = new FakeOriginFetcher();
        $origin->serve(self::ORIGIN . '/images/logo.png', (string) file_get_contents(self::IMAGE), ['content-type' => 'image/png']);
        $config = TestConfig::create(['SOURCE_ORIGIN' => self::ORIGIN, 'IMAGE_CONVERTER' => 'local', 'IMAGE_FORMATS' => 'webp']);
        $logger = new JsonLogger($this->directory->path('proxy.log'), 'debug', JsonLogger::DEFAULT_CHANNEL, RequestId::of($request));
        $url = $this->api->getBaseUrl();
        $converter = new RemoteConverter(
            $url,
            self::KEY,
            new ApiClient(2.0, 10.0, null, 'proxy-cdn-test'),
            new OriginCircuitBreaker($this->directory->path('var/conversion-api'), 30, 2),
            new OriginCircuitBreaker($this->directory->path('var/conversion-api'), 30, 1),
            new CapabilityCache(new FilesystemStorage(), $this->directory->path('var/conversion-api'), $url),
            30,
            1048576,
            $logger
        );
        $kernel = EdgeKernelFactory::create($config, $this->directory->path('public'), $this->directory->path('var'), $origin, $converter, $logger, Metrics::fromConfig($config, $this->directory->path('var')));

        $response = $kernel->handle($request);
        $kernel->terminate();

        return (string) $response->headers->get(RequestId::HEADER);
    }

    /**
     * @return list<array<mixed>>
     */
    private function logEntries(): array
    {
        $entries = [];
        foreach (file($this->directory->path('proxy.log'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
