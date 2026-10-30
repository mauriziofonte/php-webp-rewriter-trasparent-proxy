<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Origin;

use Mfonte\ProxyCdn\Origin\BodyReader;
use Mfonte\ProxyCdn\Origin\HttpClient;
use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Origin\HttpRequest;
use Mfonte\ProxyCdn\Origin\HttpResponse;
use Mfonte\ProxyCdn\Origin\ResponseCollector;
use Mfonte\ProxyCdn\Origin\StreamTransport;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The transport contract for StreamTransport, the fallback without ext-curl.
 *
 * @covers \Mfonte\ProxyCdn\Origin\BodyReader
 * @covers \Mfonte\ProxyCdn\Origin\ResponseCollector
 * @covers \Mfonte\ProxyCdn\Origin\StreamTransport
 */
#[CoversClass(BodyReader::class)]
#[CoversClass(ResponseCollector::class)]
#[CoversClass(StreamTransport::class)]
final class StreamTransportTest extends TransportContractTestCase
{
    protected function createClient(HttpClientOptions $options): HttpClient
    {
        return new StreamTransport($options);
    }

    public function testEachRequestHasItsOwnConnection(): void
    {
        $client = $this->createClient($this->options());

        $first = $client->send(new HttpRequest($this->url('/echo')));
        $second = $client->send(new HttpRequest($this->url('/echo')));

        self::assertSame('close', self::json($first)['headers']['connection'] ?? null);
        self::assertNotSame(self::json($first)['connection'], self::json($second)['connection']);
    }

    public function testARequestHostHeaderIsKept(): void
    {
        $response = $this->send($this->url('/echo'), ['Host' => 'cdn.test']);

        self::assertSame('cdn.test', self::json($response)['headers']['host'] ?? null);
        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
    }

    public function testPhpWarningsDoNotEscape(): void
    {
        $raised = [];
        set_error_handler(static function (int $level, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });
        try {
            $refused = $this->send('http://127.0.0.1:' . self::closedPort() . '/echo');
            $unresolved = $this->send('http://origin.invalid/echo');
        } finally {
            restore_error_handler();
        }

        self::assertSame(HttpResponse::OUTCOME_CONNECT, $refused->getOutcome());
        self::assertSame(HttpResponse::OUTCOME_DNS, $unresolved->getOutcome());
        self::assertSame([], $raised);
    }
}
