<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Origin;

use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Origin\HttpOriginFetcher;
use Mfonte\ProxyCdn\Origin\HttpResponse;
use Mfonte\ProxyCdn\Origin\MockTransport;
use Mfonte\ProxyCdn\Origin\OriginRequest;
use Mfonte\ProxyCdn\Origin\OriginResponse;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Origin\HttpOriginFetcher
 */
#[CoversClass(HttpOriginFetcher::class)]
final class HttpOriginFetcherTest extends TestCase
{
    private const URL = 'https://origin.example.test/css/site.css';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var MockTransport
     */
    private $transport;

    /**
     * @var MemoryLogger
     */
    private $logger;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->transport = new MockTransport((new HttpClientOptions())->withTemporaryDirectory($this->directory->path())->withMaxBodySize(1000));
        $this->logger = new MemoryLogger();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testA200IsASuccessWithTheBodyAndHeaders(): void
    {
        $this->transport->respond(self::URL, 200, ['Content-Type' => 'text/css', 'ETag' => '"v1"', 'Set-Cookie' => 'a=b'], 'body {}');

        $response = $this->fetcher()->fetch(new OriginRequest(self::URL, ['Referer' => 'https://cdn.example.test']));

        self::assertTrue($response->isSuccessful());
        self::assertSame(200, $response->getStatus());
        self::assertSame('body {}', $response->getBody());
        self::assertSame(
            ['content-type' => 'text/css', 'etag' => '"v1"', 'set-cookie' => 'a=b', 'content-length' => '7'],
            $response->getHeaders(),
            'the whitelist is applied by the kernel'
        );
        self::assertSame(['.', '..'], scandir($this->directory->path()), 'the temporary file is deleted');
        self::assertSame([], $this->logger->getRecords());
    }

    public function testThe2xRequestHeadersAreSent(): void
    {
        $this->transport->respond(self::URL, 200, [], 'body {}');

        $this->fetcher()->fetch(new OriginRequest(self::URL, ['Referer' => 'https://cdn.example.test']));

        $requests = $this->transport->getRequests();
        self::assertCount(1, $requests);
        self::assertSame('GET', $requests[0]->getMethod());
        self::assertSame(
            ['referer' => 'https://cdn.example.test', 'pragma' => 'no-cache', 'cache-control' => 'no-cache'],
            $requests[0]->getHeaders()
        );
    }

    public function testA404IsNotFoundAndLoggedAsInformation(): void
    {
        $url = 'https://origin.example.test/missing.css';

        $response = $this->fetcher()->fetch(new OriginRequest($url));

        self::assertTrue($response->isNotFound());
        self::assertSame(404, $response->getStatus());
        self::assertSame([['level' => 'info', 'message' => 'upstream 404', 'context' => ['url' => $url]]], $this->logger->getRecords());
    }

    /**
     * Each status keeps a reason of its own (finding P-13, transport part).
     *
     * @dataProvider provideStatuses
     */
    #[DataProvider('provideStatuses')]
    public function testEveryOtherStatusIsAFailureWithItsReasonP13(int $status, string $reason): void
    {
        $this->transport->respond(self::URL, $status, $status >= 300 && $status < 400 ? ['Location' => '/elsewhere.css'] : [], 'body');

        $response = $this->fetcher()->fetch(new OriginRequest(self::URL));

        self::assertFalse($response->isSuccessful());
        self::assertSame($reason, $response->getFailureReason());
        self::assertSame($status, $response->getStatus());
        self::assertSame('', $response->getBody());
        self::assertSame(
            [['level' => 'error', 'message' => 'upstream non-200', 'context' => ['url' => self::URL, 'response_code' => $status, 'reason' => $reason]]],
            $this->logger->getRecords()
        );
    }

    /**
     * @return array<string, array{int, string, string|null}>
     */
    public static function provideRedirects(): array
    {
        return [
            'status 301, relative' => [301, '/elsewhere.css', 'https://origin.example.test/elsewhere.css'],
            'status 302, absolute' => [302, 'https://other.example.test/a.css', 'https://other.example.test/a.css'],
            'status 307, relative to the directory' => [307, 'b.css', 'https://origin.example.test/css/b.css'],
            'status 308, unsupported scheme' => [308, 'ftp://origin.example.test/a.css', null],
        ];
    }

    /**
     * A redirect that was not followed carries its Location, made absolute
     * against the URL that answered it, for the kernel (finding P-13).
     *
     * @dataProvider provideRedirects
     */
    #[DataProvider('provideRedirects')]
    public function testARedirectCarriesItsAbsoluteLocationP13(int $status, string $location, ?string $absolute): void
    {
        $this->transport->respond(self::URL, $status, ['Location' => $location], 'body');

        $response = $this->fetcher()->fetch(new OriginRequest(self::URL));

        self::assertSame(OriginResponse::FAILURE_REDIRECT, $response->getFailureReason());
        self::assertSame($status, $response->getStatus());
        self::assertSame($absolute, $response->getLocation());
        self::assertSame(
            [['level' => 'notice', 'message' => 'upstream redirect', 'context' => ['url' => self::URL, 'response_code' => $status, 'location' => $absolute]]],
            $this->logger->getRecords()
        );
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function provideStatuses(): array
    {
        return [
            'status 201' => [201, OriginResponse::FAILURE_OTHER],
            'status 304' => [304, OriginResponse::FAILURE_OTHER],
            'status 400' => [400, OriginResponse::FAILURE_CLIENT_ERROR],
            'status 403' => [403, OriginResponse::FAILURE_FORBIDDEN],
            'status 410' => [410, OriginResponse::FAILURE_GONE],
            'status 429' => [429, OriginResponse::FAILURE_CLIENT_ERROR],
            'status 500' => [500, OriginResponse::FAILURE_SERVER_ERROR],
            'status 503' => [503, OriginResponse::FAILURE_SERVER_ERROR],
        ];
    }

    /**
     * @dataProvider provideTransportFailures
     */
    #[DataProvider('provideTransportFailures')]
    public function testATransportFailureIsLoggedWithItsError(string $outcome, string $reason): void
    {
        $this->transport->fail(self::URL, $outcome, 28, 'Operation timed out');

        $response = $this->fetcher()->fetch(new OriginRequest(self::URL));

        self::assertSame($reason, $response->getFailureReason());
        self::assertSame(0, $response->getStatus());
        self::assertSame(28, $response->getTransportErrorCode());
        self::assertSame('Operation timed out', $response->getTransportError());
        self::assertSame([[
            'level' => 'error',
            'message' => 'upstream fetch failed',
            'context' => ['url' => self::URL, 'reason' => $reason, 'transport_error_code' => 28, 'transport_error' => 'Operation timed out', 'response_code' => 0],
        ]], $this->logger->getRecords());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideTransportFailures(): array
    {
        return [
            'timeout' => [HttpResponse::OUTCOME_TIMEOUT, OriginResponse::FAILURE_TIMEOUT],
            'dns' => [HttpResponse::OUTCOME_DNS, OriginResponse::FAILURE_DNS],
            'connect' => [HttpResponse::OUTCOME_CONNECT, OriginResponse::FAILURE_CONNECT],
            'tls' => [HttpResponse::OUTCOME_TLS, OriginResponse::FAILURE_TLS],
            'network' => [HttpResponse::OUTCOME_NETWORK, OriginResponse::FAILURE_NETWORK],
            'other' => [HttpResponse::OUTCOME_OTHER, OriginResponse::FAILURE_OTHER],
        ];
    }

    /**
     * A body over UPSTREAM_MAX_SIZE is refused (finding P-17).
     */
    public function testABodyOverTheLimitIsAFailureP17(): void
    {
        $this->transport->respond(self::URL, 200, [], str_repeat('a', 1001));

        $response = $this->fetcher()->fetch(new OriginRequest(self::URL));

        self::assertSame(OriginResponse::FAILURE_TOO_LARGE, $response->getFailureReason());
        self::assertSame(200, $response->getStatus());
        self::assertSame('', $response->getBody());
        self::assertSame(['.', '..'], scandir($this->directory->path()));
        $records = $this->logger->recordsWithMessage('upstream fetch failed');
        self::assertCount(1, $records);
        self::assertSame('too_large', $records[0]['context']['reason'] ?? null);
    }

    public function testAnInvalidUrlIsAFailure(): void
    {
        $response = $this->fetcher()->fetch(new OriginRequest('https://origin.example.test/a b.css'));

        self::assertSame(OriginResponse::FAILURE_OTHER, $response->getFailureReason());
        self::assertSame([], $this->transport->getRequests());
        self::assertCount(1, $this->logger->recordsWithMessage('upstream fetch failed'));
    }

    public function testTheOptionsOfTheConfiguration(): void
    {
        $options = HttpOriginFetcher::optionsFromConfig(TestConfig::create([
            'UPSTREAM_CONNECT_TIMEOUT' => '4',
            'UPSTREAM_TIMEOUT' => '50',
            'UPSTREAM_VERIFY_TLS' => 'true',
            'UPSTREAM_FOLLOW_REDIRECTS' => 'true',
            'UPSTREAM_MAX_SIZE' => '1000',
            'UPSTREAM_USER_AGENT' => 'my-cdn/1.0',
            'SOURCE_ORIGIN_SERVER' => '192.0.2.10:443',
        ]));

        self::assertSame(4.0, $options->getConnectTimeout());
        self::assertSame(50.0, $options->getTimeout());
        self::assertTrue($options->shouldVerifyTls());
        self::assertTrue($options->shouldFollowRedirects());
        self::assertSame(10, $options->getMaxRedirects());
        self::assertSame(1000, $options->getMaxBodySize());
        self::assertSame('my-cdn/1.0', $options->getUserAgent());
        self::assertSame('192.0.2.10:443', $options->getConnectTo());
        self::assertSame(1024, $options->getLowSpeedLimit(), 'the 2.x low speed limit');
        self::assertSame(10, $options->getLowSpeedTime());
    }

    public function testTheDefaultsOfTheConfiguration(): void
    {
        $options = HttpOriginFetcher::optionsFromConfig(TestConfig::create([
            'UPSTREAM_CONNECT_TIMEOUT' => '20',
            'UPSTREAM_TIMEOUT' => '10',
            'UPSTREAM_VERIFY_TLS' => 'false',
        ]));

        self::assertSame(10.0, $options->getConnectTimeout(), 'lowered to the total timeout');
        self::assertSame(10.0, $options->getTimeout());
        self::assertFalse($options->shouldVerifyTls());
        self::assertFalse($options->shouldFollowRedirects());
        self::assertSame(HttpClientOptions::DEFAULT_MAX_BODY_SIZE, $options->getMaxBodySize());
        self::assertSame(HttpClientOptions::DEFAULT_USER_AGENT, $options->getUserAgent());
        self::assertNull($options->getConnectTo());
    }

    public function testFromConfig(): void
    {
        $fetcher = HttpOriginFetcher::fromConfig(TestConfig::create(), $this->logger);

        $response = $fetcher->fetch(new OriginRequest('http://origin.invalid/a.css'));

        self::assertSame(OriginResponse::FAILURE_DNS, $response->getFailureReason());
    }

    private function fetcher(): HttpOriginFetcher
    {
        return new HttpOriginFetcher($this->transport, $this->logger);
    }
}
