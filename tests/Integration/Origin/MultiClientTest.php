<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Origin;

use Mfonte\ProxyCdn\Exception\InvalidArgumentException;
use Mfonte\ProxyCdn\Origin\CurlTransport;
use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Origin\HttpRequest;
use Mfonte\ProxyCdn\Origin\HttpResponse;
use Mfonte\ProxyCdn\Origin\MultiClient;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Origin\MultiClient
 */
#[CoversClass(MultiClient::class)]
final class MultiClientTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * Three scripted servers: each answers one request at a time, so only
     * concurrent transfers to different servers overlap.
     *
     * @var list<ScriptedServer>
     */
    private $servers = [];

    protected function set_up(): void
    {
        if (!MultiClient::isAvailable()) {
            self::markTestSkipped('ext-curl is not available');
        }
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        mkdir($this->directory->path('bodies'));
        for ($i = 0; $i < 3; ++$i) {
            mkdir($this->directory->path('server' . $i));
            $this->servers[] = ScriptedServer::start($this->directory->path('server' . $i));
        }
    }

    protected function tear_down(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->directory->remove();
        parent::tear_down();
    }

    public function testResponsesKeepTheKeysAndOrderOfTheRequests(): void
    {
        $client = $this->client(2);
        $base = $this->servers[0]->getBaseUrl();

        $responses = $client->sendAll([
            'big' => new HttpRequest($base . '/bytes/2000000?gzip=1&mode=chunked'),
            7 => new HttpRequest($base . '/status/404'),
            'redirect' => new HttpRequest($base . '/status/301?location=/elsewhere'),
            'dns' => new HttpRequest('http://origin.invalid/a.css'),
            'small' => new HttpRequest($this->servers[1]->getBaseUrl() . '/bytes/1000', [], HttpRequest::METHOD_HEAD),
        ]);

        self::assertSame(['big', 7, 'redirect', 'dns', 'small'], array_keys($responses));
        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $responses['big']->getOutcome());
        $body = $responses['big']->getBody();
        self::assertNotNull($body);
        self::assertSame(ScriptedServer::payloadHash(2000000), hash_file('sha256', $body->getPath()));
        self::assertSame(HttpResponse::OUTCOME_NOT_FOUND, $responses[7]->getOutcome());
        self::assertSame(HttpResponse::OUTCOME_REDIRECT, $responses['redirect']->getOutcome());
        self::assertSame('/elsewhere', $responses['redirect']->getRedirectLocation());
        self::assertSame(HttpResponse::OUTCOME_DNS, $responses['dns']->getOutcome());
        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $responses['small']->getOutcome());
        self::assertNull($responses['small']->getBody());

        unset($responses, $body);
        self::assertSame(['.', '..'], scandir($this->directory->path('bodies')));
    }

    public function testTransfersRunConcurrently(): void
    {
        $requests = [];
        foreach ($this->servers as $i => $server) {
            $requests[$i] = new HttpRequest($server->getBaseUrl() . '/bytes/1000?header_delay=1000');
        }

        $startedAt = microtime(true);
        $responses = $this->client(3)->sendAll($requests);
        $elapsed = microtime(true) - $startedAt;

        self::assertCount(3, $responses);
        foreach ($responses as $response) {
            self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        }
        self::assertLessThan(2.5, $elapsed, 'three 1-second answers overlap');
    }

    public function testTheConcurrencyIsCapped(): void
    {
        $requests = [];
        foreach ($this->servers as $i => $server) {
            $requests[$i] = new HttpRequest($server->getBaseUrl() . '/bytes/1000?header_delay=400');
        }

        $startedAt = microtime(true);
        $responses = $this->client(1)->sendAll($requests);

        self::assertGreaterThanOrEqual(1.2, microtime(true) - $startedAt, 'one transfer at a time');
        self::assertCount(3, $responses);
    }

    public function testHandlesAndConnectionsAreReusedAcrossCalls(): void
    {
        $client = $this->client(2);
        $url = $this->servers[0]->getBaseUrl() . '/echo';

        $first = $client->sendAll([new HttpRequest($url)]);
        $second = $client->sendAll([new HttpRequest($url)]);

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $second[0]->getOutcome());
        $connections = array_column($this->servers[0]->getRequests(), 'connection');
        self::assertSame([1, 1], $connections);
        self::assertSame([], $client->sendAll([]));
        unset($first);
    }

    /**
     * Each redirect is a transfer of its own on the same handle, with the
     * policy of RedirectTarget, as with CurlTransport::send().
     */
    public function testRedirectsAreFollowedTransferByTransfer(): void
    {
        $options = (new HttpClientOptions())->withTemporaryDirectory($this->directory->path('bodies'))->withRedirects(true, 3)
            ->withPrivateRedirectTargets(true);
        $client = new MultiClient(new CurlTransport($options), 2);
        $base = $this->servers[0]->getBaseUrl();
        $away = $this->servers[1]->getBaseUrl() . '/echo';

        $responses = $client->sendAll([
            'chain' => new HttpRequest($base . '/redirect/2'),
            'away' => new HttpRequest($base . '/redirect-to?location=' . rawurlencode($away), ['Authorization' => 'Bearer s3cr3t', 'X-Kept' => 'yes']),
            'loop' => new HttpRequest($base . '/redirect/9'),
            'plain' => new HttpRequest($this->servers[2]->getBaseUrl() . '/echo'),
        ]);

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $responses['chain']->getOutcome());
        self::assertSame($base . '/redirect/0', $responses['chain']->getEffectiveUrl());
        $body = $responses['chain']->getBody();
        self::assertNotNull($body);
        self::assertSame('done', $body->getContents());
        self::assertSame($away, $responses['away']->getEffectiveUrl());
        $echo = $responses['away']->getBody();
        self::assertNotNull($echo);
        /** @var array{headers: array<string, string>} $request */
        $request = json_decode((string) $echo->getContents(), true);
        self::assertSame('yes', $request['headers']['x-kept'] ?? null);
        self::assertArrayNotHasKey('authorization', $request['headers']);
        self::assertSame(HttpResponse::OUTCOME_REDIRECT, $responses['loop']->getOutcome());
        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $responses['plain']->getOutcome());
    }

    public function testTheConcurrencyIsAtLeastOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MultiClient(new CurlTransport(new HttpClientOptions()), 0);
    }

    private function client(int $concurrency): MultiClient
    {
        return new MultiClient(new CurlTransport((new HttpClientOptions())->withTemporaryDirectory($this->directory->path('bodies'))), $concurrency);
    }
}
