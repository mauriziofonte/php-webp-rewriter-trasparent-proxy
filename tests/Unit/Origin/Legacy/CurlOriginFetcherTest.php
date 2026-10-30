<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Origin\Legacy;

use Mfonte\ProxyCdn\Origin\Legacy\CurlOriginFetcher;
use Mfonte\ProxyCdn\Origin\OriginRequest;
use Mfonte\ProxyCdn\Origin\OriginResponse;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\FakeOrigin\FakeOriginServer;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Talks to the fake origin over HTTP.
 *
 * @covers \Mfonte\ProxyCdn\Origin\Legacy\CurlOriginFetcher
 */
#[CoversClass(CurlOriginFetcher::class)]
final class CurlOriginFetcherTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var FakeOriginServer
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
        $this->origin = FakeOriginServer::start($this->directory->path());
        $this->logger = new MemoryLogger();
    }

    protected function tear_down(): void
    {
        $this->origin->stop();
        $this->directory->remove();
        parent::tear_down();
    }

    public function testA200IsASuccessWithEveryHeader(): void
    {
        $response = $this->fetcher()->fetch(new OriginRequest($this->origin->getBaseUrl() . '/css/site.css'));

        $body = (string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/origin/css/site.css');
        self::assertTrue($response->isSuccessful());
        self::assertSame(200, $response->getStatus());
        self::assertSame($body, $response->getBody());
        $headers = $response->getHeaders();
        self::assertStringStartsWith('text/css', $headers['content-type'] ?? '');
        self::assertSame((string) strlen($body), $headers['content-length'] ?? null);
        self::assertSame('"' . md5($body) . '"', $headers['etag'] ?? null);
        self::assertSame('origin-session=secret', $headers['set-cookie'] ?? null, 'the whitelist is applied by the kernel');
        self::assertSame('not-for-clients', $headers['x-origin-only'] ?? null);
        self::assertSame([], $this->logger->getRecords());
    }

    public function testThe2xRequestHeadersAreSent(): void
    {
        $this->fetcher()->fetch(new OriginRequest(
            $this->origin->getBaseUrl() . '/css/site.css?v=1',
            ['Referer' => 'https://cdn.example.test']
        ));

        $request = $this->origin->getLastRequest();
        self::assertSame('GET', $request['method']);
        self::assertSame('/css/site.css?v=1', $request['uri']);
        self::assertSame('https://cdn.example.test', $request['headers']['referer'] ?? null);
        self::assertSame('no-cache', $request['headers']['pragma'] ?? null);
        self::assertSame('no-cache', $request['headers']['cache-control'] ?? null);
        self::assertStringContainsString('Firefox/81.0', $request['headers']['user-agent'] ?? '', 'finding P-22');
        self::assertArrayNotHasKey('expect', $request['headers']);
    }

    public function testA404IsNotFoundAndLoggedAsInfo(): void
    {
        $url = $this->origin->getBaseUrl() . '/missing.css';

        $response = $this->fetcher()->fetch(new OriginRequest($url));

        self::assertFalse($response->isSuccessful());
        self::assertTrue($response->isNotFound());
        self::assertSame(404, $response->getStatus());
        self::assertSame([['level' => 'info', 'message' => 'upstream 404', 'context' => ['url' => $url]]], $this->logger->getRecords());
    }

    /**
     * Every status but 200 and 404 is a failure (finding P-13).
     *
     * @dataProvider provideFailingStatuses
     */
    #[DataProvider('provideFailingStatuses')]
    public function testOtherStatusesAreFailures(int $status, string $reason): void
    {
        // PHP turns any other status into a 302 when a Location is set.
        $this->origin->setRule('/css/site.css', $status, $status === 301 || $status === 302 ? '/elsewhere.css' : null);
        $url = $this->origin->getBaseUrl() . '/css/site.css';

        $response = $this->fetcher()->fetch(new OriginRequest($url));

        self::assertFalse($response->isSuccessful());
        self::assertSame($reason, $response->getFailureReason());
        self::assertSame($status, $response->getStatus());
        self::assertSame('', $response->getBody());
        self::assertSame(
            [['level' => 'error', 'message' => 'upstream non-200', 'context' => ['url' => $url, 'response_code' => $status]]],
            $this->logger->getRecords()
        );
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function provideFailingStatuses(): array
    {
        return [
            'status 201' => [201, OriginResponse::FAILURE_OTHER],
            'status 301' => [301, OriginResponse::FAILURE_OTHER],
            'status 302' => [302, OriginResponse::FAILURE_OTHER],
            'status 403' => [403, OriginResponse::FAILURE_OTHER],
            'status 410' => [410, OriginResponse::FAILURE_OTHER],
            'status 500' => [500, OriginResponse::FAILURE_SERVER_ERROR],
            'status 503' => [503, OriginResponse::FAILURE_SERVER_ERROR],
        ];
    }

    public function testRedirectsAreFollowedWhenEnabled(): void
    {
        $this->origin->setRule('/old.css', 301, '/css/site.css');

        $response = $this->fetcher(true)->fetch(new OriginRequest($this->origin->getBaseUrl() . '/old.css'));

        self::assertTrue($response->isSuccessful());
        self::assertSame((string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/origin/css/site.css'), $response->getBody());
        self::assertSame('/css/site.css', $response->getHeaders()['location'] ?? null, 'the headers of every response are read, the last one wins');
    }

    public function testAConnectionFailureIsLogged(): void
    {
        $url = 'http://127.0.0.1:' . self::closedPort() . '/css/site.css';

        $response = $this->fetcher()->fetch(new OriginRequest($url));

        self::assertFalse($response->isSuccessful());
        self::assertSame(OriginResponse::FAILURE_CONNECT, $response->getFailureReason());
        self::assertSame(0, $response->getStatus());
        self::assertSame(7, $response->getTransportErrorCode());
        self::assertNotSame('', $response->getTransportError());
        $records = $this->logger->recordsWithMessage('upstream fetch failed');
        self::assertCount(1, $records);
        self::assertSame('error', $records[0]['level']);
        self::assertSame($url, $records[0]['context']['url'] ?? null);
        self::assertSame('connect', $records[0]['context']['reason'] ?? null);
        self::assertSame(7, $records[0]['context']['curl_errno'] ?? null);
    }

    public function testAPinnedServerBypassesDns(): void
    {
        $fetcher = new CurlOriginFetcher(2, 10, false, false, '127.0.0.1:' . $this->origin->getPort(), $this->logger);

        $response = $fetcher->fetch(new OriginRequest('http://origin.invalid:' . $this->origin->getPort() . '/css/site.css'));

        self::assertTrue($response->isSuccessful());
    }

    /**
     * Without a port in the URL the pin assumes 443, even for http
     * (finding P-22).
     */
    public function testAPinnedServerAssumesPort443P22(): void
    {
        $fetcher = new CurlOriginFetcher(2, 10, false, false, '192.0.2.10:8080', $this->logger);

        $options = $fetcher->buildOptions(new OriginRequest('http://origin.example.test/a.css'));

        self::assertSame(['origin.example.test:443:192.0.2.10:8080'], $options[CURLOPT_CONNECT_TO]);
    }

    public function testTheOptionsOf2x(): void
    {
        $fetcher = new CurlOriginFetcher(3, 40, false, false, null, $this->logger);

        $options = $fetcher->buildOptions(new OriginRequest('https://origin.example.test/a.css', ['Referer' => 'https://cdn.example.test']));

        self::assertSame('https://origin.example.test/a.css', $options[CURLOPT_URL]);
        self::assertSame([
            'Expect:',
            'Referer: https://cdn.example.test',
            'Pragma: no-cache',
            'Cache-Control: no-cache',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:81.0) Gecko/20100101 Firefox/81.0',
        ], $options[CURLOPT_HTTPHEADER]);
        self::assertSame(3, $options[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(40, $options[CURLOPT_TIMEOUT]);
        self::assertFalse($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(0, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $options[CURLOPT_MAXREDIRS]);
        self::assertSame(CURL_IPRESOLVE_V4, $options[CURLOPT_IPRESOLVE]);
        self::assertSame(1024, $options[CURLOPT_LOW_SPEED_LIMIT]);
        self::assertSame(10, $options[CURLOPT_LOW_SPEED_TIME]);
        self::assertArrayNotHasKey(CURLOPT_CONNECT_TO, $options);
    }

    public function testFromConfig(): void
    {
        $config = TestConfig::create([
            'UPSTREAM_CONNECT_TIMEOUT' => '4',
            'UPSTREAM_TIMEOUT' => '50',
            'UPSTREAM_VERIFY_TLS' => 'true',
            'UPSTREAM_FOLLOW_REDIRECTS' => 'true',
            'SOURCE_ORIGIN_SERVER' => '192.0.2.10:443',
        ]);

        $options = CurlOriginFetcher::fromConfig($config, $this->logger)->buildOptions(new OriginRequest('https://origin.example.test:8443/a.css'));

        self::assertSame(4, $options[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(50, $options[CURLOPT_TIMEOUT]);
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertTrue($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(10, $options[CURLOPT_MAXREDIRS]);
        self::assertSame(['origin.example.test:8443:192.0.2.10:443'], $options[CURLOPT_CONNECT_TO]);
    }

    /**
     * @dataProvider provideErrorNumbers
     */
    #[DataProvider('provideErrorNumbers')]
    public function testReasonOf(int $errno, string $reason): void
    {
        self::assertSame($reason, CurlOriginFetcher::reasonOf($errno));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function provideErrorNumbers(): array
    {
        return [
            'resolve' => [CURLE_COULDNT_RESOLVE_HOST, OriginResponse::FAILURE_DNS],
            'connect' => [CURLE_COULDNT_CONNECT, OriginResponse::FAILURE_CONNECT],
            'timeout' => [CURLE_OPERATION_TIMEOUTED, OriginResponse::FAILURE_TIMEOUT],
            'tls' => [CURLE_SSL_CONNECT_ERROR, OriginResponse::FAILURE_TLS],
            'nothing' => [CURLE_GOT_NOTHING, OriginResponse::FAILURE_CONNECT],
            'receive' => [CURLE_RECV_ERROR, OriginResponse::FAILURE_NETWORK],
            'unknown' => [9999, OriginResponse::FAILURE_OTHER],
        ];
    }

    public function testParseHeaders(): void
    {
        $block = "HTTP/1.1 301 Moved Permanently\r\nLocation: /new.css\r\nContent-Type: text/html\r\n\r\n"
            . "HTTP/1.1 200 OK\r\nContent-Type: text/css\r\nETag:  \"abc\" \r\nX-Empty:\r\nX-Colon: a:b\r\n\r\n";

        self::assertSame([
            'location' => '/new.css',
            'content-type' => 'text/css',
            'etag' => '"abc"',
            'x-empty' => '',
            'x-colon' => 'a:b',
        ], CurlOriginFetcher::parseHeaders($block));
    }

    private function fetcher(bool $followRedirects = false): CurlOriginFetcher
    {
        return new CurlOriginFetcher(2, 10, false, $followRedirects, null, $this->logger);
    }

    /**
     * A local port nothing listens on.
     */
    private static function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
