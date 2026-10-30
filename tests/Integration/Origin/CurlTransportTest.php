<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Origin;

use Mfonte\ProxyCdn\Origin\CurlTransport;
use Mfonte\ProxyCdn\Origin\HttpClient;
use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Origin\HttpRequest;
use Mfonte\ProxyCdn\Origin\HttpResponse;
use Mfonte\ProxyCdn\Origin\ResponseCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The transport contract for CurlTransport, plus what only libcurl does:
 * connection reuse and the share handle.
 *
 * @covers \Mfonte\ProxyCdn\Origin\CurlTransport
 * @covers \Mfonte\ProxyCdn\Origin\ResponseCollector
 */
#[CoversClass(CurlTransport::class)]
#[CoversClass(ResponseCollector::class)]
final class CurlTransportTest extends TransportContractTestCase
{
    protected function set_up(): void
    {
        if (!CurlTransport::isAvailable()) {
            self::markTestSkipped('ext-curl is not available');
        }
        parent::set_up();
    }

    protected function createClient(HttpClientOptions $options): HttpClient
    {
        return new CurlTransport($options);
    }

    public function testConsecutiveRequestsReuseTheConnection(): void
    {
        $client = $this->createClient($this->options());

        $first = $client->send(new HttpRequest($this->url('/echo')));
        $second = $client->send(new HttpRequest($this->url('/bytes/1000')));
        $third = $client->send(new HttpRequest($this->url('/echo')));

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $second->getOutcome());
        self::assertSame(self::json($first)['connection'], self::json($third)['connection']);
    }

    public function testTheTransferOptions(): void
    {
        $options = $this->options()
            ->withTimeouts(1.5, 20.25)
            ->withLowSpeedLimit(2048, 7)
            ->withTlsVerification(true, '/etc/ssl/ca.pem')
            ->withRedirects(true, 4);

        $curlOptions = (new CurlTransport($options))->getCurlOptions();

        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $curlOptions[CURLOPT_PROTOCOLS]);
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $curlOptions[CURLOPT_REDIR_PROTOCOLS]);
        self::assertSame(1500, $curlOptions[CURLOPT_CONNECTTIMEOUT_MS]);
        self::assertSame(20250, $curlOptions[CURLOPT_TIMEOUT_MS]);
        self::assertSame(2048, $curlOptions[CURLOPT_LOW_SPEED_LIMIT]);
        self::assertSame(7, $curlOptions[CURLOPT_LOW_SPEED_TIME]);
        self::assertTrue($curlOptions[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $curlOptions[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame('/etc/ssl/ca.pem', $curlOptions[CURLOPT_CAINFO]);
        self::assertTrue($curlOptions[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(4, $curlOptions[CURLOPT_MAXREDIRS]);
        self::assertSame(CURL_IPRESOLVE_WHATEVER, $curlOptions[CURLOPT_IPRESOLVE], 'IPv4 is not forced (finding P-22)');
        self::assertTrue($curlOptions[CURLOPT_TCP_NODELAY]);
        self::assertSame('', $curlOptions[CURLOPT_ENCODING]);
        self::assertSame(
            CurlTransport::supportsHttp2() ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_1_1,
            $curlOptions[CURLOPT_HTTP_VERSION]
        );
        self::assertSame(
            CurlTransport::supportsHttp2() && CurlTransport::waitsForMultiplexingSafely(),
            $curlOptions[CURLOPT_PIPEWAIT] ?? false,
            'PIPEWAIT only where libcurl handles it (7.74 and later)'
        );
        self::assertArrayHasKey(CURLOPT_SHARE, $curlOptions);

        $plain = (new CurlTransport($options->withHttp2(false)->withCompression(false)->withTlsVerification(false), false))->getCurlOptions();

        self::assertSame(CURL_HTTP_VERSION_1_1, $plain[CURLOPT_HTTP_VERSION]);
        self::assertArrayNotHasKey(CURLOPT_ENCODING, $plain);
        self::assertArrayNotHasKey(CURLOPT_PIPEWAIT, $plain);
        self::assertArrayNotHasKey(CURLOPT_CAINFO, $plain);
        self::assertArrayNotHasKey(CURLOPT_SHARE, $plain);
        self::assertFalse($plain[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(0, $plain[CURLOPT_SSL_VERIFYHOST]);
    }

    public function testTheShareHandleIsPersistentFromPhp85(): void
    {
        if (PHP_VERSION_ID >= 80500) {
            $expected = 'CurlSharePersistentHandle';
        } elseif (PHP_VERSION_ID >= 80000) {
            $expected = 'CurlShareHandle';
        } else {
            $expected = 'resource';
        }

        self::assertSame($expected, self::typeOf(CurlTransport::createShareHandle()));
    }

    /**
     * The class of an object, else the type of the value.
     *
     * @param mixed $value
     */
    private static function typeOf($value): string
    {
        return is_object($value) ? get_class($value) : gettype($value);
    }

    /**
     * @dataProvider provideConnectToEntries
     *
     * @param array{0: string, 1: int|null} $address
     */
    #[DataProvider('provideConnectToEntries')]
    public function testConnectToEntry(string $url, array $address, string $expected): void
    {
        self::assertSame($expected, CurlTransport::connectToEntry($url, $address));
    }

    /**
     * @return array<string, array{string, array{0: string, 1: int|null}, string}>
     */
    public static function provideConnectToEntries(): array
    {
        return [
            'http without port (finding P-22)' => ['http://origin.test/a.css', ['192.0.2.10', null], 'origin.test:80:192.0.2.10:'],
            'https without port' => ['https://origin.test/a.css', ['192.0.2.10', 8443], 'origin.test:443:192.0.2.10:8443'],
            'explicit port' => ['http://origin.test:8080/a.css', ['192.0.2.10', 80], 'origin.test:8080:192.0.2.10:80'],
            'IPv6 target' => ['https://origin.test/a.css', ['2001:db8::1', 443], 'origin.test:443:[2001:db8::1]:443'],
        ];
    }
}
