<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Image;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Image\ApiClient;
use Mfonte\ProxyCdn\Image\ApiResponse;
use Mfonte\ProxyCdn\Image\CapabilityCache;
use Mfonte\ProxyCdn\Image\ConversionFailure;
use Mfonte\ProxyCdn\Image\RemoteConverter;
use Mfonte\ProxyCdn\Origin\OriginCircuitBreaker;
use Mfonte\ProxyCdn\Tests\Fixtures\FakeApi\FakeApiServer;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Image\FakeImageConverter;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\SecureImageConverter\Concurrency\KeyedLock;
use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * RemoteConverter and ApiClient against a fake conversion API v2 over
 * HTTP, which answers what each test sets.
 *
 * @covers \Mfonte\ProxyCdn\Image\RemoteConverter
 * @covers \Mfonte\ProxyCdn\Image\ApiClient
 * @covers \Mfonte\ProxyCdn\Image\ApiResponse
 * @covers \Mfonte\ProxyCdn\Image\CapabilityCache
 *
 * @group network
 */
#[CoversClass(RemoteConverter::class)]
#[CoversClass(ApiClient::class)]
#[CoversClass(ApiResponse::class)]
#[CoversClass(CapabilityCache::class)]
#[Group('network')]
final class RemoteConverterTest extends TestCase
{
    private const KEY = 'api-key-0123456789abcdef';

    private const IMAGE = __DIR__ . '/../../Fixtures/Image/files/image.png';

    private const WEBP = __DIR__ . '/../../Fixtures/Image/files/variant.webp';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var FakeApiServer
     */
    private $api;

    /**
     * @var MemoryLogger
     */
    private $logger;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        mkdir($this->directory->path('api'));
        $this->api = FakeApiServer::start($this->directory->path('api'));
        $this->logger = new MemoryLogger();
    }

    protected function tear_down(): void
    {
        $this->api->stop();
        $this->directory->remove();
        parent::tear_down();
    }

    public function testAConversionIsPostedAsTheDocumentedRequest(): void
    {
        $this->healthz();
        $this->converted();

        $result = $this->converter()->convert(self::IMAGE, $this->outputFile(), new ConversionRequest('webp'), 'req-0123.abc');

        self::assertSame('webp', $result->getOutputFormat());
        self::assertSame([4, 3, 1], [$result->getWidth(), $result->getHeight(), $result->getFrameCount()]);
        self::assertSame(['vips', 'process', false, ['effort lowered']], [$result->getBackend(), $result->getIsolationLevel(), $result->isPassthrough(), $result->getWarnings()]);
        self::assertFileEquals(self::WEBP, $this->outputFile());
        $requests = $this->api->getRequests();
        self::assertSame(['/healthz', '/v2/convert'], array_column($requests, 'path'));
        self::assertSame('GET', $requests[0]['method']);
        self::assertSame('Bearer ' . self::KEY, $requests[0]['headers']['authorization'] ?? null);
        $post = $requests[1];
        self::assertSame('POST', $post['method']);
        self::assertSame('/v2/convert?' . http_build_query((new ConversionRequest('webp'))->toParameters(), '', '&', PHP_QUERY_RFC3986), $post['target']);
        self::assertSame('Bearer ' . self::KEY, $post['headers']['authorization'] ?? null);
        self::assertSame('application/octet-stream', $post['headers']['content-type'] ?? null);
        self::assertSame('req-0123.abc', $post['headers']['x-request-id'] ?? null);
        self::assertSame(hash_file('sha256', self::IMAGE), $post['body_sha256']);
        self::assertArrayNotHasKey('expect', $post['headers']);
    }

    public function testAnInvalidRequestIdIsNotSent(): void
    {
        $this->healthz();
        $this->converted();

        $this->converter()->convert(self::IMAGE, $this->outputFile(), new ConversionRequest('webp'), "bad id\r\nX-Injected: 1");

        $post = $this->api->getRequests()[1];
        self::assertArrayNotHasKey('x-request-id', $post['headers']);
        self::assertArrayNotHasKey('x-injected', $post['headers']);
    }

    public function testTheCapabilitiesAreDiscoveredOncePerTtl(): void
    {
        $this->healthz();
        $this->converted();

        $this->converter()->convert(self::IMAGE, $this->outputFile(), new ConversionRequest('webp'));
        unlink($this->outputFile());
        $this->converter()->convert(self::IMAGE, $this->outputFile(), new ConversionRequest('webp'));

        self::assertSame(['/healthz', '/v2/convert', '/v2/convert'], array_column($this->api->getRequests(), 'path'));
        $files = glob($this->directory->path('state/capabilities-*.json'));
        self::assertIsArray($files);
        self::assertCount(1, $files);
    }

    /**
     * Carried item: a /healthz payload that Capabilities::fromArray()
     * refuses means "remote conversion unavailable", not an exception.
     *
     * @dataProvider provideUnreadableHealthz
     */
    #[DataProvider('provideUnreadableHealthz')]
    public function testUnreadableCapabilitiesMeanUnavailable(int $status, string $body, string $errorCode, string $logKey = 'error_code'): void
    {
        $this->api->answer('/healthz', $status, ['Content-Type' => 'application/json'], $body);
        $converter = $this->converter();

        self::assertNull($converter->getCapabilities());
        $failure = $this->convertFailure($converter);
        self::assertTrue($failure->isTransient());
        self::assertSame('capabilities_unavailable', $failure->getErrorCode());
        self::assertSame($errorCode, $this->logger->getRecords()[0]['context'][$logKey] ?? null);
        // Remembered: a new request does not ask again.
        self::assertNull($this->converter()->getCapabilities());
        self::assertSame(['/healthz'], array_column($this->api->getRequests(), 'path'));
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string, 3?: string}>
     */
    public static function provideUnreadableHealthz(): array
    {
        $capabilities = FakeImageConverter::capabilities(['webp'])->toArray();

        return [
            'key not accepted' => [200, '{"status":"ok"}', 'invalid_capabilities'],
            'another schema' => [200, (string) json_encode(['status' => 'ok', 'capabilities' => ['schema' => 2] + $capabilities]), 'invalid_capabilities'],
            'unknown key' => [200, (string) json_encode(['status' => 'ok', 'capabilities' => $capabilities + ['lossless' => true]]), 'invalid_capabilities'],
            'not JSON' => [200, '<html>', 'invalid_capabilities'],
            'unavailable' => [503, '{"status":"unavailable","error":{"code":"internal_error","message":"down"}}', 'internal_error', 'conversion_error_code'],
        ];
    }

    /**
     * Carried item: an image above the max_bytes of /healthz is not sent.
     */
    public function testAnImageAboveTheLimitOfTheApiIsNotSent(): void
    {
        $this->healthz(new Capabilities(['png'], ['webp'], [], ['gd'], 'none', [
            Capabilities::LIMIT_MAX_BYTES => 50,
            Capabilities::LIMIT_MAX_WIDTH => 100,
            Capabilities::LIMIT_MAX_HEIGHT => 100,
            Capabilities::LIMIT_MAX_MEGAPIXELS => 1,
            Capabilities::LIMIT_MAX_FRAMES => 1,
            Capabilities::LIMIT_MAX_TOTAL_MEGAPIXELS => 1,
        ]));

        $failure = $this->convertFailure($this->converter());

        self::assertFalse($failure->isTransient());
        self::assertSame('file_too_large', $failure->getConversionErrorCode());
        self::assertSame(['/healthz'], array_column($this->api->getRequests(), 'path'));
    }

    public function testAnImageOfAFormatTheApiRefusesIsNotSent(): void
    {
        $this->healthz(new Capabilities(['jpeg'], ['webp'], [], ['gd'], 'none', FakeImageConverter::capabilities(['webp'])->getLimits()));

        $failure = $this->convertFailure($this->converter());

        self::assertSame('unsupported_format', $failure->getConversionErrorCode());
        self::assertSame(['/healthz'], array_column($this->api->getRequests(), 'path'));
    }

    /**
     * Carried item: the API's 503 codes that mean "no capacity" open the
     * breaker at once; the code is read from the JSON body.
     *
     * @dataProvider provideTrippingAnswers
     */
    #[DataProvider('provideTrippingAnswers')]
    public function testAnApiThatCannotTakeConversionsOpensTheBreakerAtOnce(int $status, string $code): void
    {
        $this->healthz();
        $this->api->answer('/v2/convert', $status, ['Content-Type' => 'application/json'], (string) json_encode(['error' => ['code' => $code, 'message' => 'Try later']]));

        $failure = $this->convertFailure($this->converter());
        $again = $this->convertFailure($this->converter());

        self::assertTrue($failure->isTransient());
        self::assertSame($code, $failure->getErrorCode() ?? $failure->getConversionErrorCode());
        self::assertSame($status, $failure->getStatus());
        self::assertSame('circuit_open', $again->getErrorCode());
        self::assertSame(['/healthz', '/v2/convert'], array_column($this->api->getRequests(), 'path'));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function provideTrippingAnswers(): array
    {
        return [
            'concurrency' => [503, 'concurrency_unavailable'],
            'rate limit store' => [503, 'rate_limit_unavailable'],
            'isolation missing' => [503, 'isolation_unavailable'],
            'key refused' => [401, 'invalid_api_key'],
        ];
    }

    public function testAServerErrorCountsTowardsTheBreaker(): void
    {
        $this->healthz();
        $this->api->answer('/v2/convert', 500, ['Content-Type' => 'application/json'], '{"error":{"code":"internal_error","message":"Backend exploded"}}');

        $first = $this->convertFailure($this->converter());
        $second = $this->convertFailure($this->converter());
        $third = $this->convertFailure($this->converter());

        self::assertSame(['internal_error', 'internal_error', 'circuit_open'], [$first->getConversionErrorCode(), $second->getConversionErrorCode(), $third->getErrorCode()]);
        self::assertTrue($first->isTransient());
        self::assertSame('The conversion API answered 500: Backend exploded', $first->getMessage());
    }

    /**
     * @dataProvider provideRejectedAnswers
     */
    #[DataProvider('provideRejectedAnswers')]
    public function testAnAnswerAboutTheImageIsARejection(int $status, string $code): void
    {
        $this->healthz();
        $this->api->answer('/v2/convert', $status, ['Content-Type' => 'application/json'], (string) json_encode(['error' => ['code' => $code, 'message' => 'No']]));

        $failure = $this->convertFailure($this->converter());

        self::assertFalse($failure->isTransient());
        self::assertSame($code, $failure->getErrorCode() ?? $failure->getConversionErrorCode());
        self::assertFileDoesNotExist($this->outputFile());
        // The API is up: the next conversion is sent.
        $this->convertFailure($this->converter());
        self::assertCount(3, $this->api->getRequests());
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function provideRejectedAnswers(): array
    {
        return [
            'invalid image' => [422, 'invalid_image'],
            'conversion failed' => [500, 'conversion_failed'],
            'body too large' => [413, 'body_too_large'],
        ];
    }

    public function testARedirectIsNotFollowed(): void
    {
        $this->healthz();
        $this->api->answer('/v2/convert', 301, ['Location' => 'https://elsewhere.example.test/v2/convert']);

        $failure = $this->convertFailure($this->converter());

        self::assertTrue($failure->isTransient());
        self::assertSame('redirected', $failure->getErrorCode());
        self::assertSame(['/healthz', '/v2/convert'], array_column($this->api->getRequests(), 'path'));
    }

    /**
     * Carried item: the body is bounded by the client, without
     * Content-Length too.
     */
    public function testAnAnswerAboveTheLimitIsCutAndRefused(): void
    {
        $this->healthz();
        $this->api->answer('/v2/convert', 200, ['Content-Type' => 'image/webp', 'X-Conversion-Format' => 'webp'], null, null, 3 * 65536);

        $failure = $this->convertFailure($this->converter(65536));

        self::assertFalse($failure->isTransient());
        self::assertSame('response_too_large', $failure->getErrorCode());
        self::assertFileDoesNotExist($this->outputFile());
    }

    public function testAnErrorBodyAboveTheLimitIsCut(): void
    {
        $this->healthz();
        $this->api->answer('/v2/convert', 502, ['Content-Type' => 'application/json'], null, null, RemoteConverter::MAX_JSON_BYTES + 65536);

        $failure = $this->convertFailure($this->converter());

        self::assertSame('response_too_large', $failure->getErrorCode());
    }

    public function testA200ThatIsNotAConversionResultIsRefused(): void
    {
        $this->healthz();
        $this->api->answer('/v2/convert', 200, ['Content-Type' => 'text/html'], '<html>');

        $failure = $this->convertFailure($this->converter());

        self::assertTrue($failure->isTransient());
        self::assertSame('invalid_response', $failure->getErrorCode());
        self::assertFileDoesNotExist($this->outputFile());
    }

    public function testAnUnreachableApiIsUnavailable(): void
    {
        $this->api->stop();
        $converter = new RemoteConverter(
            'http://127.0.0.1:9',
            self::KEY,
            new ApiClient(1.0, 2.0, null, 'proxy-cdn-test'),
            new OriginCircuitBreaker($this->directory->path('state/breaker'), 30, 2),
            new OriginCircuitBreaker($this->directory->path('state/breaker'), 30, 1),
            new CapabilityCache(new FilesystemStorage(), $this->directory->path('state'), 'http://127.0.0.1:9'),
            30,
            1048576,
            $this->logger
        );

        self::assertNull($converter->getCapabilities());
        self::assertSame('unreachable', $this->logger->getRecords()[0]['context']['error_code'] ?? null);
    }

    /**
     * Carried item: the key never appears in a message or a log entry.
     */
    public function testTheKeyIsNeverLogged(): void
    {
        $this->api->answer('/healthz', 401, ['Content-Type' => 'application/json'], '{"error":{"code":"invalid_api_key","message":"Invalid API key"}}');

        $failure = $this->convertFailure($this->converter());

        self::assertStringNotContainsString(self::KEY, $failure->getMessage() . json_encode($this->logger->getRecords()));
    }

    /**
     * While the breaker is open, /healthz is not asked: a hanging API is
     * not waited for again by every cold request.
     */
    public function testNoDiscoveryWhileTheBreakerIsOpen(): void
    {
        $this->healthz();
        (new OriginCircuitBreaker($this->directory->path('state/breaker'), 30, 1))->recordFailure($this->api->getBaseUrl());

        self::assertNull($this->converter()->getCapabilities());
        self::assertSame('circuit_open', $this->convertFailure($this->converter())->getErrorCode());
        self::assertSame([], $this->api->getRequests());
    }

    /**
     * One request discovers at a time: the others do not wait for
     * /healthz, see remote conversion as unavailable, and their failure
     * is not remembered.
     */
    public function testOneRequestDiscoversAtATime(): void
    {
        $this->healthz();
        $this->converted();
        $lock = $this->discoveryLock();
        $held = $lock->tryAcquire('discovery-' . hash('sha256', $this->api->getBaseUrl()));
        self::assertNotNull($held);

        $waiting = $this->converter(1048576, $lock);
        self::assertNull($waiting->getCapabilities());
        $failure = $this->convertFailure($waiting);
        self::assertSame([], $this->api->getRequests());
        self::assertTrue($failure->isTransient());
        self::assertFalse($failure->shouldBeRemembered());
        self::assertSame('discovery_pending', $failure->getErrorCode());

        $held->release();
        self::assertNotNull($this->converter(1048576, $lock)->getCapabilities());
        self::assertSame(['/healthz'], array_column($this->api->getRequests(), 'path'));
    }

    private function discoveryLock(): KeyedLock
    {
        return new KeyedLock(new LockDirectory($this->directory->path('state/locks')), RemoteConverter::DISCOVERY_LOCK_NAME);
    }

    private function healthz(?Capabilities $capabilities = null): void
    {
        $capabilities = $capabilities ?? FakeImageConverter::capabilities(['webp', 'avif']);
        $this->api->answer('/healthz', 200, ['Content-Type' => 'application/json'], (string) json_encode(['status' => 'ok', 'capabilities' => $capabilities->toArray()]));
    }

    private function converted(): void
    {
        $this->api->answer('/v2/convert', 200, [
            'Content-Type' => 'image/webp',
            'Content-Length' => (string) filesize(self::WEBP),
            'X-Conversion-Format' => 'webp',
            'X-Conversion-Width' => '4',
            'X-Conversion-Height' => '3',
            'X-Conversion-Frames' => '1',
            'X-Conversion-Backend' => 'vips',
            'X-Conversion-Isolation' => 'process',
            'X-Conversion-Duration-Ms' => '12',
            'X-Conversion-Original-Kept' => '0',
            'X-Conversion-Warnings' => '1',
            'X-Conversion-Warning' => ['effort lowered'],
        ], null, self::WEBP);
    }

    private function converter(int $maxOutputBytes = 1048576, ?KeyedLock $lock = null): RemoteConverter
    {
        $url = $this->api->getBaseUrl();

        return new RemoteConverter(
            $url,
            self::KEY,
            new ApiClient(2.0, 10.0, null, 'proxy-cdn-test'),
            new OriginCircuitBreaker($this->directory->path('state/breaker'), 30, 2),
            new OriginCircuitBreaker($this->directory->path('state/breaker'), 30, 1),
            new CapabilityCache(new FilesystemStorage(), $this->directory->path('state'), $url),
            30,
            $maxOutputBytes,
            $this->logger,
            $lock
        );
    }

    private function convertFailure(RemoteConverter $converter): ConversionFailure
    {
        if (is_file($this->outputFile())) {
            unlink($this->outputFile());
        }
        try {
            $converter->convert(self::IMAGE, $this->outputFile(), new ConversionRequest('webp'));
        } catch (ConversionFailure $failure) {
            return $failure;
        }
        self::fail('The conversion did not fail');
    }

    private function outputFile(): string
    {
        return $this->directory->path('output');
    }
}
