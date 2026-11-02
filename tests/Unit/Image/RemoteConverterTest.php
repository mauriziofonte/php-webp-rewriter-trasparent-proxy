<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Image;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Image\ApiClient;
use Mfonte\ProxyCdn\Image\ApiResponse;
use Mfonte\ProxyCdn\Image\CapabilityCache;
use Mfonte\ProxyCdn\Image\RemoteConverter;
use Mfonte\ProxyCdn\Origin\OriginCircuitBreaker;
use Mfonte\ProxyCdn\Tests\Fixtures\FakeApi\FakeApiServer;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Image\FakeImageConverter;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The capability discovery of RemoteConverter against a fake conversion
 * API v2 over HTTP, which answers what each test sets.
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

    public function testTheCapabilitiesAreDiscoveredOncePerTtl(): void
    {
        $this->healthz();

        self::assertNotNull($this->converter()->getCapabilities());
        self::assertNotNull($this->converter()->getCapabilities());

        $requests = $this->api->getRequests();
        self::assertSame(['/healthz'], array_column($requests, 'path'));
        self::assertSame('Bearer ' . self::KEY, $requests[0]['headers']['authorization'] ?? null);
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
    public function testUnreadableCapabilitiesMeanUnavailable(int $status, string $body, string $errorCode): void
    {
        $this->api->answer('/healthz', $status, ['Content-Type' => 'application/json'], $body);
        $converter = $this->converter();

        self::assertNull($converter->getCapabilities());
        self::assertSame($errorCode, $this->logger->getRecords()[0]['context']['error_code'] ?? null);
        // Remembered: a new request does not ask again.
        self::assertNull($this->converter()->getCapabilities());
        self::assertSame(['/healthz'], array_column($this->api->getRequests(), 'path'));
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function provideUnreadableHealthz(): array
    {
        $capabilities = FakeImageConverter::capabilities(['webp'])->toArray();

        return [
            'key not accepted' => [200, '{"status":"ok"}', 'invalid_capabilities'],
            'another schema' => [200, (string) json_encode(['status' => 'ok', 'capabilities' => ['schema' => 2] + $capabilities]), 'invalid_capabilities'],
            'unknown key' => [200, (string) json_encode(['status' => 'ok', 'capabilities' => $capabilities + ['lossless' => true]]), 'invalid_capabilities'],
            'not JSON' => [200, '<html>', 'invalid_capabilities'],
            'unavailable' => [503, '{"status":"unavailable","error":{"code":"internal_error","message":"down"}}', 'internal_error'],
        ];
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

        self::assertNull($this->converter()->getCapabilities());

        self::assertSame('invalid_api_key', $this->logger->getRecords()[0]['context']['error_code'] ?? null);
        self::assertStringNotContainsString(self::KEY, (string) json_encode($this->logger->getRecords()));
    }

    private function healthz(?Capabilities $capabilities = null): void
    {
        $capabilities = $capabilities ?? FakeImageConverter::capabilities(['webp', 'avif']);
        $this->api->answer('/healthz', 200, ['Content-Type' => 'application/json'], (string) json_encode(['status' => 'ok', 'capabilities' => $capabilities->toArray()]));
    }

    private function converter(): RemoteConverter
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
            $this->logger
        );
    }
}
