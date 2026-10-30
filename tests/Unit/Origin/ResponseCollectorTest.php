<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Origin;

use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Origin\HttpRequest;
use Mfonte\ProxyCdn\Origin\HttpResponse;
use Mfonte\ProxyCdn\Origin\ResponseCollector;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The rules every transport shares, fed line by line as libcurl's header
 * callback does.
 *
 * @covers \Mfonte\ProxyCdn\Origin\ResponseCollector
 */
#[CoversClass(ResponseCollector::class)]
final class ResponseCollectorTest extends TestCase
{
    private const URL = 'https://origin.test/a.css';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testA200IsStoredInATemporaryFile(): void
    {
        $collector = $this->collector();

        self::assertTrue($this->head($collector, "HTTP/2 200 \r\n", ['content-type: text/css', 'ETag:  "abc" ', 'Content-Length: 6']));
        self::assertTrue($collector->wantsBody());
        self::assertTrue($collector->bodyChunk('abc'));
        self::assertTrue($collector->bodyChunk('def'));
        self::assertCount(1, $this->temporaryFiles());
        $response = $collector->finish(null, 0, '', self::URL, 0.5);

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertSame(200, $response->getStatus());
        self::assertSame('2', $response->getProtocolVersion());
        self::assertSame(['content-type' => 'text/css', 'etag' => '"abc"', 'content-length' => '6'], $response->getHeaders());
        $body = $response->getBody();
        self::assertNotNull($body);
        self::assertSame('abcdef', $body->getContents());
        self::assertSame(6, $body->getSize());
        self::assertSame($this->directory->path(), dirname($body->getPath()));

        unset($body, $response);
        self::assertSame([], $this->temporaryFiles());
    }

    public function testRepeatedFieldsAreJoinedAndFoldedLinesAppended(): void
    {
        $collector = $this->collector();

        $this->head($collector, 'HTTP/1.1 200 OK', ['Vary: Accept', 'X-Long: a', "\tb", 'vary: Origin', 'no colon here', ': empty name']);
        $response = $collector->finish(null, 0, '', self::URL, 0.0);

        self::assertSame('Accept, Origin', $response->getHeader('vary'));
        self::assertSame('a b', $response->getHeader('x-long'));
        self::assertSame('0', $response->getHeader('content-length'));
    }

    public function testInterimResponsesAreSkipped(): void
    {
        $collector = $this->collector();

        $this->head($collector, 'HTTP/1.1 100 Continue', []);
        self::assertFalse($collector->isFinal());
        $this->head($collector, 'HTTP/1.1 103 Early Hints', ['Link: </a.css>; rel=preload']);
        self::assertFalse($collector->isFinal());
        $this->head($collector, 'HTTP/1.1 200 OK', ['Content-Type: text/css']);
        $response = $collector->finish(null, 0, '', self::URL, 0.0);

        self::assertSame(200, $response->getStatus());
        self::assertArrayNotHasKey('link', $response->getHeaders());
    }

    public function testARedirectIsFollowedOnlyWhenEnabled(): void
    {
        $following = $this->collector((new HttpClientOptions())->withRedirects(true));
        $this->head($following, 'HTTP/1.1 301 Moved Permanently', ['Location: /b.css', 'X-First: yes']);

        self::assertTrue($following->isFollowingRedirect());
        self::assertFalse($following->isFinal());
        self::assertTrue($following->bodyChunk('<html>moved</html>'), 'the body of a followed redirect is ignored');

        $this->head($following, 'HTTP/1.1 200 OK', ['Content-Type: text/css']);
        $following->bodyChunk('b');
        $response = $following->finish(null, 0, '', 'https://origin.test/b.css', 0.0);
        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertArrayNotHasKey('x-first', $response->getHeaders(), 'only the final response counts');
        self::assertSame('b', (string) ($response->getBody() !== null ? $response->getBody()->getContents() : ''));

        $notFollowing = $this->collector();
        $this->head($notFollowing, 'HTTP/1.1 301 Moved Permanently', ['Location: /b.css']);
        self::assertFalse($notFollowing->isFollowingRedirect());
        self::assertTrue($notFollowing->isFinal());
        self::assertFalse($notFollowing->wantsBody());
        $response = $notFollowing->finish(null, 0, '', self::URL, 0.0);
        self::assertSame(HttpResponse::OUTCOME_REDIRECT, $response->getOutcome());
        self::assertSame('/b.css', $response->getRedirectLocation());
        self::assertNull($response->getBody());
    }

    public function testOneRedirectTooManyIsARedirect(): void
    {
        $collector = $this->collector((new HttpClientOptions())->withRedirects(true, 1));
        $this->head($collector, 'HTTP/1.1 302 Found', ['Location: /c.css']);

        $response = $collector->finish(HttpResponse::OUTCOME_REDIRECT, 47, 'Maximum (1) redirects followed', self::URL, 0.0);

        self::assertSame(HttpResponse::OUTCOME_REDIRECT, $response->getOutcome());
        self::assertSame(302, $response->getStatus());
        self::assertSame('/c.css', $response->getRedirectLocation());
        self::assertSame(47, $response->getErrorCode());
    }

    public function testAnAnnouncedBodyOverTheLimitIsRefusedBeforeItArrives(): void
    {
        $collector = $this->collector((new HttpClientOptions())->withMaxBodySize(10));

        self::assertFalse($this->head($collector, 'HTTP/1.1 200 OK', ['Content-Length: 11']));
        self::assertSame([], $this->temporaryFiles());
        $response = $collector->finish(HttpResponse::OUTCOME_OTHER, 23, 'Failed writing header', self::URL, 0.0);

        self::assertSame(HttpResponse::OUTCOME_TOO_LARGE, $response->getOutcome());
        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString('11 bytes', $response->getError());
        self::assertNull($response->getBody());
    }

    public function testAHugeContentLengthIsRefused(): void
    {
        $collector = $this->collector();

        self::assertFalse($this->head($collector, 'HTTP/1.1 200 OK', ['Content-Length: 99999999999999999999999']));
        self::assertSame(HttpResponse::OUTCOME_TOO_LARGE, $collector->finish(null, 0, '', self::URL, 0.0)->getOutcome());
    }

    public function testGzipIsRequestedUnlessTheRequestChoosesItsEncodings(): void
    {
        $options = (new HttpClientOptions())->withTemporaryDirectory($this->directory->path());

        self::assertTrue($this->collector()->requestsGzip());
        self::assertFalse($this->collector($options->withCompression(false))->requestsGzip());
        self::assertFalse((new ResponseCollector(new HttpRequest(self::URL, ['Accept-Encoding' => 'br']), $options))->requestsGzip());
    }

    public function testAnEncodedBodyIsMeasuredAfterDecoding(): void
    {
        $collector = $this->collector((new HttpClientOptions())->withMaxBodySize(10));
        $encoded = (string) gzencode(str_repeat('x', 11));

        self::assertTrue($this->head($collector, 'HTTP/1.1 200 OK', ['Content-Length: ' . strlen($encoded) * 10, 'Content-Encoding: gzip']), 'the encoded length is not the size');
        self::assertFalse($collector->bodyChunk($encoded));
        self::assertFalse($collector->bodyChunk('b'), 'nothing more is accepted after an abort');
        $response = $collector->finish(HttpResponse::OUTCOME_OTHER, 23, 'Failure writing output', self::URL, 0.0);

        self::assertSame(HttpResponse::OUTCOME_TOO_LARGE, $response->getOutcome());
        self::assertSame([], $this->temporaryFiles());
    }

    public function testAGzipBodyIsInflatedAndLosesItsContentEncoding(): void
    {
        $collector = $this->collector();
        $encoded = (string) gzencode(str_repeat('decoded!', 1000));

        $this->head($collector, 'HTTP/1.1 200 OK', ['Content-Length: ' . strlen($encoded), 'Content-Encoding: gzip', 'Transfer-Encoding: chunked']);
        foreach (str_split($encoded, 7) as $piece) {
            self::assertTrue($collector->bodyChunk($piece));
        }
        $response = $collector->finish(null, 0, '', self::URL, 0.0);

        self::assertSame(['content-length' => '8000'], $response->getHeaders());
        $body = $response->getBody();
        self::assertNotNull($body);
        self::assertSame(str_repeat('decoded!', 1000), $body->getContents());
    }

    /**
     * A gzip stream cut short inside complete HTTP framing: libcurl cannot
     * tell, the collector can.
     */
    public function testATruncatedGzipStreamIsANetworkFailure(): void
    {
        $collector = $this->collector();
        $encoded = (string) gzencode(str_repeat('x', 5000));

        $this->head($collector, 'HTTP/1.1 200 OK', ['Content-Length: 10', 'Content-Encoding: gzip']);
        self::assertTrue($collector->bodyChunk(substr($encoded, 0, 10)));
        $response = $collector->finish(null, 0, '', self::URL, 0.0);

        self::assertSame(HttpResponse::OUTCOME_NETWORK, $response->getOutcome());
        self::assertSame('The compressed body is truncated', $response->getError());
        self::assertSame(200, $response->getStatus());
        self::assertNull($response->getBody());
        self::assertSame([], $this->temporaryFiles());
    }

    /**
     * Bytes after the end of the gzip stream are ignored wherever they
     * fall: in the chunk that ends the stream or in later ones.
     *
     * @dataProvider bytesAfterTheStream
     */
    #[DataProvider('bytesAfterTheStream')]
    public function testBytesAfterTheGzipStreamAreIgnored(string $after, int $chunkSize): void
    {
        $collector = $this->collector();
        $encoded = (string) gzencode(str_repeat('decoded!', 1000)) . $after;

        $this->head($collector, 'HTTP/1.1 200 OK', ['Content-Length: ' . strlen($encoded), 'Content-Encoding: gzip']);
        for ($offset = 0; $offset < strlen($encoded); $offset += $chunkSize) {
            self::assertTrue($collector->bodyChunk((string) substr($encoded, $offset, $chunkSize)));
        }
        $response = $collector->finish(null, 0, '', self::URL, 0.0);

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        $body = $response->getBody();
        self::assertNotNull($body);
        self::assertSame(str_repeat('decoded!', 1000), $body->getContents());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function bytesAfterTheStream(): array
    {
        $member = (string) gzencode(str_repeat('second member', 100));
        $garbage = str_repeat('garbage after the stream ', 200);

        return [
            'a second member, one chunk' => [$member, 1 << 20],
            'a second member, small chunks' => [$member, 100],
            'garbage, one chunk' => [$garbage, 1 << 20],
            'garbage, small chunks' => [$garbage, 100],
        ];
    }

    public function testAnEmptyGzipBodyIsAnEmptyBody(): void
    {
        $collector = $this->collector();

        $this->head($collector, 'HTTP/1.1 200 OK', ['Content-Length: 0', 'Content-Encoding: gzip']);
        $response = $collector->finish(null, 0, '', self::URL, 0.0);

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertSame(0, self::bodySize($response));
    }

    public function testACorruptGzipBodyIsAnotherFailure(): void
    {
        $collector = $this->collector();

        $this->head($collector, 'HTTP/1.1 200 OK', ['Content-Encoding: gzip']);
        self::assertFalse($collector->bodyChunk('this is not gzip data'));
        $response = $collector->finish(HttpResponse::OUTCOME_OTHER, 23, 'Failure writing output', self::URL, 0.0);

        self::assertSame(HttpResponse::OUTCOME_OTHER, $response->getOutcome());
        self::assertSame('The compressed body is corrupt', $response->getError());
        self::assertSame([], $this->temporaryFiles());
    }

    public function testAnotherContentCodingIsRefusedWhenGzipWasRequested(): void
    {
        $collector = $this->collector();

        self::assertFalse($this->head($collector, 'HTTP/1.1 200 OK', ['Content-Encoding: br']));
        $response = $collector->finish(HttpResponse::OUTCOME_OTHER, 23, '', self::URL, 0.0);

        self::assertSame(HttpResponse::OUTCOME_OTHER, $response->getOutcome());
        self::assertSame('Unsupported content encoding: br', $response->getError());
        self::assertSame([], $this->temporaryFiles());
    }

    public function testAnUndecodedBodyKeepsItsContentEncoding(): void
    {
        $collector = $this->collector((new HttpClientOptions())->withCompression(false));

        $this->head($collector, 'HTTP/1.1 200 OK', ['Content-Encoding: br']);
        $collector->bodyChunk('raw');
        $response = $collector->finish(null, 0, '', self::URL, 0.0);

        self::assertSame('br', $response->getHeader('content-encoding'));
        self::assertSame(3, self::bodySize($response));
    }

    public function testTheBodyOfAnErrorIsDiscardedUpToALimit(): void
    {
        $collector = $this->collector();

        $this->head($collector, 'HTTP/1.1 404 Not Found', ['Content-Type: text/html']);
        self::assertFalse($collector->wantsBody());
        self::assertTrue($collector->bodyChunk(str_repeat('x', ResponseCollector::DISCARD_LIMIT)));
        self::assertFalse($collector->bodyChunk('x'));
        $response = $collector->finish(HttpResponse::OUTCOME_OTHER, 23, 'Failure writing output', self::URL, 0.0);

        self::assertSame(HttpResponse::OUTCOME_NOT_FOUND, $response->getOutcome(), 'the status is the answer');
        self::assertSame(0, $response->getErrorCode());
        self::assertSame([], $this->temporaryFiles());
    }

    public function testAFailureAfterAnUnwantedBodyKeepsTheStatus(): void
    {
        $collector = $this->collector();
        $this->head($collector, 'HTTP/1.1 403 Forbidden', []);

        $response = $collector->finish(HttpResponse::OUTCOME_NETWORK, 18, 'transfer closed', self::URL, 0.0);

        self::assertSame(HttpResponse::OUTCOME_FORBIDDEN, $response->getOutcome());
    }

    public function testAHeadHasNoBody(): void
    {
        $collector = new ResponseCollector(
            new HttpRequest(self::URL, [], HttpRequest::METHOD_HEAD),
            (new HttpClientOptions())->withTemporaryDirectory($this->directory->path())
        );

        $this->head($collector, 'HTTP/1.1 200 OK', ['Content-Length: 123456789012']);
        $response = $collector->finish(null, 0, '', self::URL, 0.0);

        self::assertSame(HttpResponse::OUTCOME_SUCCESS, $response->getOutcome());
        self::assertNull($response->getBody());
        self::assertSame('123456789012', $response->getHeader('content-length'));
        self::assertSame([], $this->temporaryFiles());
    }

    public function testATruncatedBodyIsANetworkFailureWithoutFile(): void
    {
        $collector = $this->collector();
        $this->head($collector, 'HTTP/1.1 200 OK', ['Content-Length: 10']);
        $collector->bodyChunk('12345');

        $response = $collector->finish(HttpResponse::OUTCOME_NETWORK, 18, 'transfer closed with 5 bytes remaining', self::URL, 0.0);

        self::assertSame(HttpResponse::OUTCOME_NETWORK, $response->getOutcome());
        self::assertSame(200, $response->getStatus(), 'the status of the response is kept');
        self::assertNull($response->getBody());
        self::assertSame([], $this->temporaryFiles());
    }

    public function testAFailureBeforeTheFinalResponseHasNoStatus(): void
    {
        $collector = $this->collector((new HttpClientOptions())->withRedirects(true));
        $this->head($collector, 'HTTP/1.1 302 Found', ['Location: https://down.test/']);

        $response = $collector->finish(HttpResponse::OUTCOME_CONNECT, 7, 'Failed to connect', 'https://down.test/', 0.0);

        self::assertSame(HttpResponse::OUTCOME_CONNECT, $response->getOutcome());
        self::assertSame(0, $response->getStatus());
        self::assertSame([], $response->getHeaders());
        self::assertSame('', $response->getProtocolVersion());
    }

    public function testAProxyAnswerToConnectIsNotTheResponse(): void
    {
        $collector = $this->collector();

        $this->head($collector, 'HTTP/1.1 200 Connection established', []);
        $this->head($collector, 'HTTP/2 404', ['content-type: text/plain']);
        $response = $collector->finish(null, 0, '', self::URL, 0.0);

        self::assertSame(HttpResponse::OUTCOME_NOT_FOUND, $response->getOutcome());
        self::assertSame([], $this->temporaryFiles());
    }

    public function testTrailerFieldsAreIgnored(): void
    {
        $collector = $this->collector();
        $this->head($collector, 'HTTP/1.1 200 OK', ['Transfer-Encoding: chunked']);
        $collector->bodyChunk('abc');

        self::assertTrue($collector->headerLine("X-Checksum: 123\r\n"));
        self::assertTrue($collector->headerLine("\r\n"));
        $response = $collector->finish(null, 0, '', self::URL, 0.0);

        self::assertArrayNotHasKey('x-checksum', $response->getHeaders());
        self::assertSame('3', $response->getHeader('content-length'));
    }

    public function testAMalformedStatusLineAborts(): void
    {
        $collector = $this->collector();

        self::assertFalse($collector->headerLine("HTTP/1.1 OK\r\n"));
        $response = $collector->finish(HttpResponse::OUTCOME_OTHER, 23, 'Failed writing header', self::URL, 0.0);

        self::assertSame(HttpResponse::OUTCOME_OTHER, $response->getOutcome());
        self::assertStringContainsString('Malformed status line', $response->getError());
    }

    public function testAnOversizedHeaderBlockAborts(): void
    {
        $collector = $this->collector();
        $collector->headerLine("HTTP/1.1 200 OK\r\n");

        $accepted = true;
        for ($i = 0; $accepted && $i < 200; ++$i) {
            $accepted = $collector->headerLine('X-Filler-' . $i . ': ' . str_repeat('y', 1000) . "\r\n");
        }

        self::assertFalse($accepted);
        self::assertStringContainsString('header block exceeds', $collector->finish(HttpResponse::OUTCOME_OTHER, 23, '', self::URL, 0.0)->getError());
    }

    public function testNoFinalResponseIsAFailure(): void
    {
        $response = $this->collector()->finish(null, 0, '', self::URL, 0.0);

        self::assertSame(HttpResponse::OUTCOME_OTHER, $response->getOutcome());
        self::assertSame('No HTTP response was received', $response->getError());
    }

    public function testAMissingTemporaryDirectoryIsAFailure(): void
    {
        $collector = new ResponseCollector(new HttpRequest(self::URL), (new HttpClientOptions())->withTemporaryDirectory($this->directory->path('missing')));

        self::assertFalse($this->head($collector, 'HTTP/1.1 200 OK', []));
        $response = $collector->finish(HttpResponse::OUTCOME_OTHER, 23, '', self::URL, 0.0);

        self::assertSame(HttpResponse::OUTCOME_OTHER, $response->getOutcome());
        self::assertStringContainsString('Cannot create a temporary file', $response->getError());
    }

    public function testTheBodyFileIsPrivateToItsOwner(): void
    {
        $collector = $this->collector();

        $this->head($collector, 'HTTP/1.1 200 OK', []);
        $collector->bodyChunk('body');
        $body = $collector->finish(null, 0, '', self::URL, 0.0)->getBody();

        self::assertNotNull($body);
        self::assertSame($this->directory->path(), dirname($body->getPath()));
        self::assertStringStartsWith(ResponseCollector::FILE_PREFIX, basename($body->getPath()));
        self::assertSame(0600, fileperms($body->getPath()) & 0777);
    }

    /**
     * No silent fallback to the system temporary directory, as tempnam()
     * does.
     */
    public function testAnUnwritableTemporaryDirectoryIsAFailure(): void
    {
        $directory = $this->directory->path('read-only');
        mkdir($directory, 0500);
        if (is_writable($directory)) {
            chmod($directory, 0700);
            self::markTestSkipped('the user can write to read-only directories (root)');
        }
        $system = glob(sys_get_temp_dir() . '/' . ResponseCollector::FILE_PREFIX . '*');
        $collector = new ResponseCollector(new HttpRequest(self::URL), (new HttpClientOptions())->withTemporaryDirectory($directory));

        self::assertFalse($this->head($collector, 'HTTP/1.1 200 OK', []));
        $response = $collector->finish(HttpResponse::OUTCOME_OTHER, 23, '', self::URL, 0.0);
        chmod($directory, 0700);

        self::assertSame(HttpResponse::OUTCOME_OTHER, $response->getOutcome());
        self::assertSame('Cannot create a temporary file in ' . $directory, $response->getError());
        self::assertSame($system, glob(sys_get_temp_dir() . '/' . ResponseCollector::FILE_PREFIX . '*'));
    }

    public function testAnAbandonedCollectorDeletesItsFile(): void
    {
        $collector = $this->collector();
        $this->head($collector, 'HTTP/1.1 200 OK', []);
        $collector->bodyChunk('partial');
        self::assertCount(1, $this->temporaryFiles());

        unset($collector);

        self::assertSame([], $this->temporaryFiles());
    }

    private function collector(?HttpClientOptions $options = null): ResponseCollector
    {
        return new ResponseCollector(
            new HttpRequest(self::URL),
            ($options ?? new HttpClientOptions())->withTemporaryDirectory($this->directory->path())
        );
    }

    private static function bodySize(HttpResponse $response): int
    {
        $body = $response->getBody();
        self::assertNotNull($body);

        return $body->getSize();
    }

    /**
     * Feeds a status line, fields and the empty line that ends the block.
     *
     * @param list<string> $fields
     */
    private function head(ResponseCollector $collector, string $statusLine, array $fields): bool
    {
        $accepted = $collector->headerLine($statusLine);
        foreach ($fields as $field) {
            $accepted = $collector->headerLine($field . "\r\n") && $accepted;
        }

        return $collector->headerLine("\r\n") && $accepted;
    }

    /**
     * @return list<string>
     */
    private function temporaryFiles(): array
    {
        $entries = scandir($this->directory->path());
        self::assertIsArray($entries);

        return array_values(array_diff($entries, ['.', '..', 'missing']));
    }
}
