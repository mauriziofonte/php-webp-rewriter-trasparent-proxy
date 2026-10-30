<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Origin;

use Mfonte\ProxyCdn\Origin\HttpClient;
use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Origin\HttpRequest;
use Mfonte\ProxyCdn\Origin\HttpResponse;
use Mfonte\ProxyCdn\Origin\ResponseBody;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\ScriptedServer;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\TestCertificate;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The contract every HttpClient transport honours, run against the scripted
 * server of tests/Fixtures/Http/scripted-server.php on the loopback
 * interface. A subclass names the transport.
 */
abstract class TransportContractTestCase extends TestCase
{
    /**
     * @var TemporaryDirectory|null
     */
    private static $serverDirectory;

    /**
     * @var ScriptedServer|null
     */
    private static $server;

    /**
     * A second plain server, another origin on another port.
     *
     * @var ScriptedServer|null
     */
    private static $otherServer;

    /**
     * @var ScriptedServer|null
     */
    private static $tlsServer;

    /**
     * @var TestCertificate|null
     */
    private static $certificate;

    /**
     * Where the transport stores the bodies of the current test.
     *
     * @var TemporaryDirectory
     */
    protected $bodies;

    abstract protected function createClient(HttpClientOptions $options): HttpClient;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        $directory = new TemporaryDirectory('proxy-cdn-scripted-');
        self::$serverDirectory = $directory;
        mkdir($directory->path('plain'));
        self::$server = ScriptedServer::start($directory->path('plain'));
        mkdir($directory->path('other'));
        self::$otherServer = ScriptedServer::start($directory->path('other'));
        if (TestCertificate::isSupported()) {
            mkdir($directory->path('tls'));
            self::$certificate = TestCertificate::create($directory->path('tls'));
            self::$tlsServer = ScriptedServer::start($directory->path('tls'), self::$certificate->getServerPem());
        }
    }

    public static function tear_down_after_class(): void
    {
        foreach ([self::$server, self::$otherServer, self::$tlsServer] as $server) {
            if ($server !== null) {
                $server->stop();
            }
        }
        if (self::$serverDirectory !== null) {
            self::$serverDirectory->remove();
        }
        self::$server = null;
        self::$otherServer = null;
        self::$tlsServer = null;
        self::$certificate = null;
        self::$serverDirectory = null;
        parent::tear_down_after_class();
    }

    protected function set_up(): void
    {
        parent::set_up();
        $this->bodies = new TemporaryDirectory('proxy-cdn-bodies-');
    }

    protected function tear_down(): void
    {
        $this->bodies->remove();
        parent::tear_down();
    }

    public function testAGetStreamsTheBodyToATemporaryFile(): void
    {
        $url = $this->url('/bytes/1000000');

        $response = $this->send($url);

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertTrue($response->isSuccessful());
        self::assertFalse($response->isTransportFailure());
        self::assertSame(200, $response->getStatus());
        self::assertSame('1.1', $response->getProtocolVersion());
        self::assertSame($url, $response->getEffectiveUrl());
        self::assertSame('1000000', $response->getHeader('content-length'));
        self::assertSame('application/octet-stream', $response->getHeader('content-type'));
        self::assertGreaterThan(0.0, $response->getDuration());
        self::assertSame(0, $response->getErrorCode());
        self::assertSame('', $response->getError());
        $body = self::body($response);
        self::assertSame(1000000, $body->getSize());
        self::assertSame($this->bodies->path(), dirname($body->getPath()));
        self::assertSame(ScriptedServer::payloadHash(1000000), hash_file('sha256', $body->getPath()));

        unset($response, $body);

        $this->assertNoBodyFileLeft();
    }

    public function testTheRequestCarriesItsHeadersAndTheUserAgent(): void
    {
        $response = $this->send($this->url('/echo?a=1&b=%20'), ['Referer' => 'https://cdn.test/', 'X-Custom' => 'yes']);
        $custom = $this->createClient($this->options()->withUserAgent('custom-agent/1.0'))->send(new HttpRequest($this->url('/echo')));

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        $echo = self::json($response);
        self::assertSame('GET', $echo['method']);
        self::assertSame('/echo?a=1&b=%20', $echo['target']);
        self::assertSame('HTTP/1.1', $echo['version']);
        self::assertSame('127.0.0.1:' . self::server()->getPort(), $echo['headers']['host'] ?? null);
        self::assertSame(HttpClientOptions::DEFAULT_USER_AGENT, $echo['headers']['user-agent'] ?? null);
        self::assertSame('https://cdn.test/', $echo['headers']['referer'] ?? null);
        self::assertSame('yes', $echo['headers']['x-custom'] ?? null);
        self::assertArrayNotHasKey('expect', $echo['headers']);
        self::assertSame('custom-agent/1.0', self::json($custom)['headers']['user-agent'] ?? null);
    }

    public function testAHeadHasNoBody(): void
    {
        $response = $this->send($this->url('/bytes/1000'), [], HttpRequest::METHOD_HEAD);

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertSame('1000', $response->getHeader('content-length'));
        self::assertNull($response->getBody());
        self::assertSame('HEAD', self::server()->getLastRequest()['method']);
        $this->assertNoBodyFileLeft();
    }

    /**
     * 3xx, 403, 404, 410 and the other statuses are distinct outcomes, with
     * no body (finding P-13, transport part).
     *
     * @dataProvider provideStatuses
     */
    #[DataProvider('provideStatuses')]
    public function testEachStatusHasItsOutcomeP13(int $status, string $outcome): void
    {
        $response = $this->send($this->url('/status/' . $status . '?location=/elsewhere'));

        self::assertSame($outcome, $response->getOutcome());
        self::assertSame($status, $response->getStatus());
        self::assertFalse($response->isTransportFailure());
        self::assertSame(0, $response->getErrorCode());
        if ($status >= 300) {
            self::assertNull($response->getBody());
            self::assertFalse($response->isSuccessful());
        }
        if ($outcome === HttpResponse::OUTCOME_REDIRECT) {
            self::assertSame('/elsewhere', $response->getRedirectLocation());
        }
        unset($response);
        $this->assertNoBodyFileLeft();
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function provideStatuses(): array
    {
        return [
            'status 200' => [200, HttpResponse::OUTCOME_SUCCESS],
            'status 204' => [204, HttpResponse::OUTCOME_SUCCESS],
            'status 301' => [301, HttpResponse::OUTCOME_REDIRECT],
            'status 302' => [302, HttpResponse::OUTCOME_REDIRECT],
            'status 303' => [303, HttpResponse::OUTCOME_REDIRECT],
            'status 304' => [304, HttpResponse::OUTCOME_NOT_MODIFIED],
            'status 307' => [307, HttpResponse::OUTCOME_REDIRECT],
            'status 308' => [308, HttpResponse::OUTCOME_REDIRECT],
            'status 400' => [400, HttpResponse::OUTCOME_CLIENT_ERROR],
            'status 401' => [401, HttpResponse::OUTCOME_CLIENT_ERROR],
            'status 403' => [403, HttpResponse::OUTCOME_FORBIDDEN],
            'status 404' => [404, HttpResponse::OUTCOME_NOT_FOUND],
            'status 410' => [410, HttpResponse::OUTCOME_GONE],
            'status 429' => [429, HttpResponse::OUTCOME_CLIENT_ERROR],
            'status 500' => [500, HttpResponse::OUTCOME_SERVER_ERROR],
            'status 502' => [502, HttpResponse::OUTCOME_SERVER_ERROR],
            'status 503' => [503, HttpResponse::OUTCOME_SERVER_ERROR],
        ];
    }

    public function testALargeErrorBodyIsNotStored(): void
    {
        $response = $this->send($this->url('/status/500?size=5000000'));

        self::assertSame(HttpResponse::OUTCOME_SERVER_ERROR, $response->getOutcome());
        self::assertSame(500, $response->getStatus());
        self::assertNull($response->getBody());
        self::assertLessThan(5.0, $response->getDuration());
        $this->assertNoBodyFileLeft();
    }

    public function testRedirectsAreFollowedWhenEnabled(): void
    {
        $client = $this->createClient($this->options()->withRedirects(true, 5));

        $response = $client->send(new HttpRequest($this->url('/redirect/3?code=301')));

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertSame($this->url('/redirect/0?code=301'), $response->getEffectiveUrl());
        self::assertSame('final', $response->getHeader('x-hop'));
        self::assertSame('done', self::body($response)->getContents());
        self::assertSame('4', $response->getHeader('content-length'));
        self::assertNull($response->getHeader('location'));
    }

    /**
     * Every 3xx but 304 with a Location is followed, as libcurl does.
     *
     * @dataProvider provideRedirectStatuses
     */
    #[DataProvider('provideRedirectStatuses')]
    public function testEveryRedirectStatusIsFollowed(int $status): void
    {
        $client = $this->createClient($this->options()->withRedirects(true, 5));

        $response = $client->send(new HttpRequest($this->url('/redirect-to?code=' . $status . '&location=/echo')));

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertSame($this->url('/echo'), $response->getEffectiveUrl());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideRedirectStatuses(): array
    {
        return [
            'status 300' => [300],
            'status 301' => [301],
            'status 302' => [302],
            'status 303' => [303],
            'status 305' => [305],
            'status 307' => [307],
            'status 308' => [308],
        ];
    }

    public function testARedirectIsReturnedWhenNotFollowed(): void
    {
        $before = count(self::server()->getRequests());

        $response = $this->send($this->url('/redirect/1'));

        self::assertSame(HttpResponse::OUTCOME_REDIRECT, $response->getOutcome());
        self::assertSame(302, $response->getStatus());
        self::assertSame('/redirect/0', $response->getRedirectLocation());
        self::assertSame($this->url('/redirect/1'), $response->getEffectiveUrl());
        self::assertNull($response->getBody());
        self::assertCount($before + 1, self::server()->getRequests(), 'the location is not requested');
    }

    /**
     * Authorization, Cookie, Proxy-Authorization and a custom Host go to the
     * origin of the request only: a redirect to another host or port drops
     * them, on every transport and libcurl.
     *
     * @dataProvider provideRedirectTargets
     */
    #[DataProvider('provideRedirectTargets')]
    public function testOriginBoundFieldsAreNotSentToAnotherOrigin(string $target, bool $sameOrigin): void
    {
        $port = self::server()->getPort();
        $otherPort = self::otherServer()->getPort();
        $location = strtr($target, ['{port}' => (string) $port, '{other}' => (string) $otherPort]);
        $client = $this->createClient($this->options()->withRedirects(true, 5));
        $headers = [
            'Authorization' => 'Bearer s3cr3t',
            'Cookie' => 'session=abc',
            'Proxy-Authorization' => 'Basic cHJveHk=',
            'Host' => '127.0.0.1:' . $port,
            'X-Kept' => 'yes',
        ];

        $response = $client->send(new HttpRequest($this->url('/redirect-to?location=' . rawurlencode($location)), $headers));

        $echo = self::json($response)['headers'];
        self::assertSame('yes', $echo['x-kept'] ?? null);
        if ($sameOrigin) {
            self::assertSame('Bearer s3cr3t', $echo['authorization'] ?? null);
            self::assertSame('session=abc', $echo['cookie'] ?? null);
            self::assertSame('Basic cHJveHk=', $echo['proxy-authorization'] ?? null);
            self::assertSame('127.0.0.1:' . $port, $echo['host'] ?? null);
        } else {
            self::assertArrayNotHasKey('authorization', $echo);
            self::assertArrayNotHasKey('cookie', $echo);
            self::assertArrayNotHasKey('proxy-authorization', $echo);
            self::assertSame((string) parse_url($location, PHP_URL_HOST) . ':' . (string) parse_url($location, PHP_URL_PORT), $echo['host'] ?? null);
        }
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function provideRedirectTargets(): array
    {
        return [
            'same origin' => ['/echo', true],
            'same origin, absolute' => ['http://127.0.0.1:{port}/echo', true],
            'another host' => ['http://localhost:{port}/echo', false],
            'another port' => ['http://127.0.0.1:{other}/echo', false],
        ];
    }

    public function testFieldsDroppedOnceAreNotSentAgain(): void
    {
        $port = self::server()->getPort();
        $back = 'http://127.0.0.1:' . $port . '/echo';
        $away = 'http://localhost:' . $port . '/redirect-to?location=' . rawurlencode($back);
        $client = $this->createClient($this->options()->withRedirects(true, 5));

        $response = $client->send(new HttpRequest($this->url('/redirect-to?location=' . rawurlencode($away)), ['Authorization' => 'Bearer s3cr3t']));

        self::assertSame($back, $response->getEffectiveUrl());
        self::assertArrayNotHasKey('authorization', self::json($response)['headers']);
    }

    public function testA304WithALocationIsNotFollowed(): void
    {
        $client = $this->createClient($this->options()->withRedirects(true, 5));

        $response = $client->send(new HttpRequest($this->url('/status/304?location=/echo')));

        self::assertSame(HttpResponse::OUTCOME_NOT_MODIFIED, $response->getOutcome());
        self::assertSame($this->url('/status/304?location=/echo'), $response->getEffectiveUrl());
    }

    public function testTooManyRedirectsAreARedirectOutcome(): void
    {
        $client = $this->createClient($this->options()->withRedirects(true, 2));

        $response = $client->send(new HttpRequest($this->url('/redirect/5')));

        self::assertSame(HttpResponse::OUTCOME_REDIRECT, $response->getOutcome());
        self::assertNull($response->getBody());
        $this->assertNoBodyFileLeft();
    }

    /**
     * @dataProvider provideUnsupportedLocations
     */
    #[DataProvider('provideUnsupportedLocations')]
    public function testARedirectOnlyLeadsToHttpOrHttps(string $location): void
    {
        $client = $this->createClient($this->options()->withRedirects(true, 5));

        $response = $client->send(new HttpRequest($this->url('/redirect-to?location=' . rawurlencode($location))));

        self::assertSame(HttpResponse::OUTCOME_OTHER, $response->getOutcome());
        self::assertTrue($response->isTransportFailure());
        self::assertNull($response->getBody());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnsupportedLocations(): array
    {
        return [
            'file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://127.0.0.1/file'],
            'gopher' => ['gopher://127.0.0.1:70/_x'],
        ];
    }

    public function testConditionalRequestsAre304WhenTheValidatorsMatch(): void
    {
        $request = new HttpRequest($this->url('/conditional'));
        $client = $this->createClient($this->options());

        $byEtag = $client->send($request->withConditions('"v1"', null));
        $etagHeaders = self::server()->getLastRequest()['headers'];
        $byDate = $client->send($request->withConditions(null, 'Tue, 01 Sep 2026 10:00:00 GMT'));
        $dateHeaders = self::server()->getLastRequest()['headers'];
        $changed = $client->send($request->withConditions('"v0"', null));

        self::assertSame(HttpResponse::OUTCOME_NOT_MODIFIED, $byEtag->getOutcome());
        self::assertSame(304, $byEtag->getStatus());
        self::assertSame('"v1"', $byEtag->getHeader('etag'));
        self::assertNull($byEtag->getBody());
        self::assertSame('"v1"', $etagHeaders['if-none-match'] ?? null);
        self::assertArrayNotHasKey('if-modified-since', $etagHeaders);
        self::assertSame(HttpResponse::OUTCOME_NOT_MODIFIED, $byDate->getOutcome());
        self::assertSame('Tue, 01 Sep 2026 10:00:00 GMT', $dateHeaders['if-modified-since'] ?? null);
        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $changed->getOutcome());
        self::assertSame('fresh content', self::body($changed)->getContents());
    }

    /**
     * @dataProvider provideFramings
     */
    #[DataProvider('provideFramings')]
    public function testAGzipBodyIsStoredDecoded(string $mode): void
    {
        $response = $this->send($this->url('/bytes/300000?gzip=1&mode=' . $mode));

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertStringContainsString('gzip', self::server()->getLastRequest()['headers']['accept-encoding'] ?? '');
        self::assertNull($response->getHeader('content-encoding'));
        self::assertNull($response->getHeader('transfer-encoding'));
        self::assertSame('300000', $response->getHeader('content-length'));
        self::assertSame(ScriptedServer::payloadHash(300000), hash_file('sha256', self::body($response)->getPath()));
    }

    /**
     * @dataProvider provideFramings
     */
    #[DataProvider('provideFramings')]
    public function testAnIdentityBodyIsStoredWhateverItsFraming(string $mode): void
    {
        $response = $this->send($this->url('/bytes/200000?mode=' . $mode));

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertNull($response->getHeader('transfer-encoding'));
        self::assertSame('200000', $response->getHeader('content-length'));
        self::assertSame(ScriptedServer::payloadHash(200000), hash_file('sha256', self::body($response)->getPath()));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideFramings(): array
    {
        return [
            'Content-Length' => ['length'],
            'chunked' => ['chunked'],
            'until close' => ['close'],
        ];
    }

    public function testWithoutCompressionNoEncodingIsRequested(): void
    {
        $client = $this->createClient($this->options()->withCompression(false));

        $response = $client->send(new HttpRequest($this->url('/bytes/1000?gzip=1')));

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertArrayNotHasKey('accept-encoding', self::server()->getLastRequest()['headers']);
        self::assertSame(ScriptedServer::payload(1000), self::body($response)->getContents());
    }

    public function testAnAnnouncedBodyOverTheLimitIsRefusedBeforeItArrives(): void
    {
        $client = $this->createClient($this->options()->withMaxBodySize(1000));

        $response = $client->send(new HttpRequest($this->url('/bytes/100000?body_delay=1500')));

        self::assertSame(HttpResponse::OUTCOME_TOO_LARGE, $response->getOutcome());
        self::assertTrue($response->isTransportFailure());
        self::assertSame(200, $response->getStatus());
        self::assertNull($response->getBody());
        self::assertLessThan(1.2, $response->getDuration());
        $this->assertNoBodyFileLeft();
    }

    public function testAStreamedBodyOverTheLimitIsCutOff(): void
    {
        $client = $this->createClient($this->options()->withMaxBodySize(100000));

        $response = $client->send(new HttpRequest($this->url('/bytes/300000?mode=chunked')));

        self::assertSame(HttpResponse::OUTCOME_TOO_LARGE, $response->getOutcome());
        self::assertNull($response->getBody());
        $this->assertNoBodyFileLeft();
    }

    public function testTheLimitAppliesToTheDecodedBody(): void
    {
        $client = $this->createClient($this->options()->withMaxBodySize(1000000));

        $response = $client->send(new HttpRequest($this->url('/zeros/50000000')));

        self::assertSame(HttpResponse::OUTCOME_TOO_LARGE, $response->getOutcome());
        self::assertNull($response->getBody());
        $this->assertNoBodyFileLeft();
    }

    public function testABodyOfExactlyTheLimitIsAccepted(): void
    {
        $client = $this->createClient($this->options()->withMaxBodySize(100000));

        $response = $client->send(new HttpRequest($this->url('/bytes/100000?mode=chunked')));

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertSame(100000, self::body($response)->getSize());
    }

    /**
     * @dataProvider provideFramings
     */
    #[DataProvider('provideFramings')]
    public function testATruncatedBodyIsANetworkFailure(string $mode): void
    {
        if ($mode === 'close') {
            // Without framing, an early close cannot be told from the end.
            $mode = 'length&gzip=1';
        }

        $response = $this->send($this->url('/bytes/300000?truncate=100000&mode=' . $mode));

        self::assertSame(HttpResponse::OUTCOME_NETWORK, $response->getOutcome());
        self::assertTrue($response->isTransportFailure());
        self::assertSame(200, $response->getStatus());
        self::assertNotSame(0, $response->getErrorCode() + strlen($response->getError()));
        self::assertNull($response->getBody());
        $this->assertNoBodyFileLeft();
    }

    /**
     * A gzip stream cut short inside complete HTTP framing is a truncated
     * body for every transport.
     */
    public function testAGzipStreamCutShortInsideItsFramingIsANetworkFailure(): void
    {
        $response = $this->send($this->url('/bytes/300000?gzip=1&gzip_cut=1000'));

        self::assertSame(HttpResponse::OUTCOME_NETWORK, $response->getOutcome());
        self::assertSame('The compressed body is truncated', $response->getError());
        self::assertSame(200, $response->getStatus());
        self::assertNull($response->getBody());
        $this->assertNoBodyFileLeft();
    }

    public function testAConnectionClosedWithoutAnswerIsAConnectFailure(): void
    {
        $response = $this->send($this->url('/close'));

        self::assertSame(HttpResponse::OUTCOME_CONNECT, $response->getOutcome());
        self::assertSame(0, $response->getStatus());
        self::assertSame([], $response->getHeaders());
        self::assertSame('', $response->getProtocolVersion());
    }

    public function testAnAnswerThatIsNotHttpIsAnotherFailure(): void
    {
        $response = $this->send($this->url('/garbage'));

        self::assertSame(HttpResponse::OUTCOME_OTHER, $response->getOutcome());
        self::assertSame(0, $response->getStatus());
        self::assertNull($response->getBody());
        $this->assertNoBodyFileLeft();
    }

    public function testARefusedConnectionIsAConnectFailure(): void
    {
        $response = $this->send('http://127.0.0.1:' . self::closedPort() . '/echo');

        self::assertSame(HttpResponse::OUTCOME_CONNECT, $response->getOutcome());
        self::assertTrue($response->isTransportFailure());
        self::assertSame(0, $response->getStatus());
        self::assertNotSame('', $response->getError());
    }

    public function testAnUnresolvableHostIsADnsFailure(): void
    {
        $response = $this->send('http://origin.invalid/echo');

        self::assertSame(HttpResponse::OUTCOME_DNS, $response->getOutcome());
        self::assertNotSame('', $response->getError());
    }

    public function testASlowAnswerTimesOut(): void
    {
        $client = $this->createClient($this->options()->withTimeouts(0.3, 0.6));

        $response = $client->send(new HttpRequest($this->url('/bytes/10?header_delay=1500')));

        self::assertSame(HttpResponse::OUTCOME_TIMEOUT, $response->getOutcome());
        self::assertSame(0, $response->getStatus());
        self::assertLessThan(1.4, $response->getDuration());
    }

    public function testAStalledBodyHitsTheLowSpeedLimit(): void
    {
        // libcurl averages the speed over its last five seconds: a limit
        // well above the first block makes the stall count at once.
        $client = $this->createClient($this->options()->withLowSpeedLimit(1000000, 1));

        $response = $client->send(new HttpRequest($this->url('/bytes/300000?stall=2500')));

        self::assertSame(HttpResponse::OUTCOME_TIMEOUT, $response->getOutcome());
        self::assertSame(200, $response->getStatus());
        self::assertNull($response->getBody());
        self::assertLessThan(2.4, $response->getDuration());
        $this->assertNoBodyFileLeft();
    }

    /**
     * Bytes that trickle in inside a line, five a second, pass a low speed
     * limit of one byte a second: the deadline still ends the transfer.
     *
     * @dataProvider provideTrickledParts
     */
    #[DataProvider('provideTrickledParts')]
    public function testATrickledLineEndsAtTheDeadline(string $part): void
    {
        $client = $this->createClient($this->options()->withTimeouts(1.0, 1.2)->withLowSpeedLimit(1, 30));

        $startedAt = microtime(true);
        $response = $client->send(new HttpRequest($this->url('/trickle/' . $part . '?for=6000')));
        $elapsed = microtime(true) - $startedAt;

        self::assertSame(HttpResponse::OUTCOME_TIMEOUT, $response->getOutcome(), $response->getError());
        self::assertLessThan(2.5, $elapsed);
        self::assertNull($response->getBody());
        $this->assertNoBodyFileLeft();
    }

    /**
     * The same bytes are under a low speed limit of 1000 bytes a second,
     * which ends the transfer long before the deadline.
     *
     * @dataProvider provideTrickledParts
     */
    #[DataProvider('provideTrickledParts')]
    public function testATrickledLineHitsTheLowSpeedLimit(string $part): void
    {
        $client = $this->createClient($this->options()->withTimeouts(1.0, 30.0)->withLowSpeedLimit(1000, 1));

        $startedAt = microtime(true);
        $response = $client->send(new HttpRequest($this->url('/trickle/' . $part . '?for=6000')));
        $elapsed = microtime(true) - $startedAt;

        self::assertSame(HttpResponse::OUTCOME_TIMEOUT, $response->getOutcome(), $response->getError());
        self::assertLessThan(4.0, $elapsed);
        self::assertNull($response->getBody());
        $this->assertNoBodyFileLeft();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideTrickledParts(): array
    {
        return [
            'header field' => ['header'],
            'chunk size line' => ['chunk'],
            'trailer field' => ['trailer'],
        ];
    }

    public function testInterimResponsesAreSkipped(): void
    {
        $response = $this->send($this->url('/early-hints'));

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertSame(200, $response->getStatus());
        self::assertSame('yes', $response->getHeader('x-final'));
        self::assertNull($response->getHeader('link'));
        self::assertSame('final', self::body($response)->getContents());
    }

    public function testRepeatedFieldsAreJoined(): void
    {
        $response = $this->send($this->url('/repeated-headers'));

        self::assertSame('Accept, Origin', $response->getHeader('vary'));
        self::assertSame('one, two', $response->getHeader('x-repeated'));
    }

    public function testTheConnectToPinKeepsTheOriginalHost(): void
    {
        $port = self::server()->getPort();
        $client = $this->createClient($this->options()->withConnectTo('127.0.0.1:' . $port));

        $response = $client->send(new HttpRequest('http://origin.invalid:' . $port . '/echo'));

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertSame('origin.invalid:' . $port, self::json($response)['headers']['host'] ?? null);
    }

    /**
     * An http origin without a port is pinned on port 80 of its URL, not on
     * port 443 (finding P-22).
     */
    public function testTheConnectToPinUsesTheDefaultPortOfTheSchemeP22(): void
    {
        $client = $this->createClient($this->options()->withConnectTo('127.0.0.1:' . self::server()->getPort()));

        $response = $client->send(new HttpRequest('http://origin.invalid/echo'));

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertSame('origin.invalid', self::json($response)['headers']['host'] ?? null);
    }

    public function testAConnectToPinWithoutPortKeepsThePortOfTheUrl(): void
    {
        $port = self::server()->getPort();
        $client = $this->createClient($this->options()->withConnectTo('127.0.0.1'));

        $response = $client->send(new HttpRequest('http://origin.invalid:' . $port . '/echo'));

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertSame('origin.invalid:' . $port, self::json($response)['headers']['host'] ?? null);
    }

    public function testTheConnectToPinDoesNotFollowARedirectToAnotherHost(): void
    {
        $port = self::server()->getPort();
        $client = $this->createClient($this->options()->withConnectTo('127.0.0.1:' . $port)->withRedirects(true, 5));
        $location = rawurlencode('http://other.invalid:' . $port . '/echo');

        $response = $client->send(new HttpRequest('http://origin.invalid:' . $port . '/redirect-to?location=' . $location));

        self::assertSame(HttpResponse::OUTCOME_DNS, $response->getOutcome());
    }

    public function testAnUntrustedCertificateIsATlsFailure(): void
    {
        $response = $this->createClient($this->options())->send(new HttpRequest($this->tlsUrl('/echo')));

        self::assertSame(HttpResponse::OUTCOME_TLS, $response->getOutcome());
        self::assertTrue($response->isTransportFailure());
        self::assertSame(0, $response->getStatus());
        self::assertNotSame('', $response->getError());
    }

    public function testATrustedCertificateIsAccepted(): void
    {
        $client = $this->createClient($this->options()->withTlsVerification(true, self::certificate()->getCaPem()));

        $response = $client->send(new HttpRequest($this->tlsUrl('/bytes/100000?gzip=1&mode=chunked')));

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertSame(ScriptedServer::payloadHash(100000), hash_file('sha256', self::body($response)->getPath()));
    }

    public function testVerificationCanBeDisabled(): void
    {
        $client = $this->createClient($this->options()->withTlsVerification(false));

        $response = $client->send(new HttpRequest($this->tlsUrl('/echo')));

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
    }

    public function testAPinnedTlsOriginIsVerifiedAgainstTheNameInTheUrl(): void
    {
        $port = $this->tlsServer()->getPort();
        $trusted = $this->options()->withTlsVerification(true, self::certificate()->getCaPem())->withConnectTo('127.0.0.1:' . $port);

        $response = $this->createClient($trusted)->send(new HttpRequest('https://localhost:' . $port . '/echo'));
        $wrongName = $this->createClient($trusted)->send(new HttpRequest('https://origin.invalid:' . $port . '/echo'));

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertSame('localhost:' . $port, self::json($response)['headers']['host'] ?? null);
        self::assertSame(HttpResponse::OUTCOME_TLS, $wrongName->getOutcome());
    }

    public function testAnIpv6OriginIsReached(): void
    {
        $directory = new TemporaryDirectory('proxy-cdn-scripted-');
        try {
            $server = ScriptedServer::start($directory->path(), null, '::1');
        } catch (\RuntimeException $exception) {
            $directory->remove();
            self::markTestSkipped('IPv6 loopback is not available: ' . $exception->getMessage());
        }

        try {
            $response = $this->send($server->getBaseUrl() . '/echo');

            self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
            self::assertSame('[::1]:' . $server->getPort(), self::json($response)['headers']['host'] ?? null);
        } finally {
            $server->stop();
            $directory->remove();
        }
    }

    public function testAMissingTemporaryDirectoryIsAnotherFailure(): void
    {
        $client = $this->createClient($this->options()->withTemporaryDirectory($this->bodies->path('missing')));

        $response = $client->send(new HttpRequest($this->url('/bytes/1000')));

        self::assertSame(HttpResponse::OUTCOME_OTHER, $response->getOutcome());
        self::assertStringContainsString('Cannot create a temporary file', $response->getError());
    }

    /**
     * Options that store bodies in the directory of the current test.
     */
    protected function options(): HttpClientOptions
    {
        return (new HttpClientOptions())->withTemporaryDirectory($this->bodies->path());
    }

    /**
     * Sends a request with the default options.
     *
     * @param array<string, string> $headers
     */
    protected function send(string $url, array $headers = [], string $method = HttpRequest::METHOD_GET): HttpResponse
    {
        return $this->createClient($this->options())->send(new HttpRequest($url, $headers, $method));
    }

    /**
     * A URL on the plain HTTP server.
     */
    protected function url(string $path): string
    {
        return self::server()->getBaseUrl() . $path;
    }

    /**
     * A URL on the TLS server; skips the test without ext-openssl.
     */
    protected function tlsUrl(string $path): string
    {
        return $this->tlsServer()->getBaseUrl() . $path;
    }

    protected static function server(): ScriptedServer
    {
        if (self::$server === null) {
            throw new \LogicException('The scripted server is not running');
        }

        return self::$server;
    }

    protected static function otherServer(): ScriptedServer
    {
        if (self::$otherServer === null) {
            throw new \LogicException('The second scripted server is not running');
        }

        return self::$otherServer;
    }

    protected function tlsServer(): ScriptedServer
    {
        if (self::$tlsServer === null) {
            self::markTestSkipped('ext-openssl is required to serve TLS');
        }

        return self::$tlsServer;
    }

    protected static function certificate(): TestCertificate
    {
        if (self::$certificate === null) {
            self::markTestSkipped('ext-openssl is required to serve TLS');
        }

        return self::$certificate;
    }

    protected static function body(HttpResponse $response): ResponseBody
    {
        $body = $response->getBody();
        self::assertNotNull($body, 'the response has a body');

        return $body;
    }

    /**
     * The request /echo answered with.
     *
     * @return array{connection: int, index: int, method: string, target: string, version: string, headers: array<string, string>}
     */
    protected static function json(HttpResponse $response): array
    {
        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome(), $response->getError());
        /** @var array{connection: int, index: int, method: string, target: string, version: string, headers: array<string, string>} $echo */
        $echo = json_decode((string) self::body($response)->getContents(), true);

        return $echo;
    }

    /**
     * A port nothing listens on: bound by the kernel, then released.
     */
    protected static function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    protected function assertNoBodyFileLeft(): void
    {
        self::assertSame(['.', '..'], scandir($this->bodies->path()), 'no temporary file is left');
    }
}
