<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Http;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Http\FileResponder;
use Mfonte\ProxyCdn\Http\ResponseFactory;
use Mfonte\ProxyCdn\Origin\ResponseBody;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Http\FileResponder
 */
#[CoversClass(FileResponder::class)]
final class FileResponderTest extends TestCase
{
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

    /**
     * The file is streamed with the headers of a web-server hit: the type
     * of the extension, the entity tag nginx computes, CORS, CORP and
     * Timing-Allow-Origin (opportunity O-G).
     */
    public function testTheFileIsStreamedWithTheHeadersOfAHit(): void
    {
        $file = $this->directory->write('images/logo.webp', 'WEBP-BYTES');
        touch($file, 1700000000);

        $response = $this->responder()->respond(Request::create('/images/logo.webp'), $file, 'webp', 'HIT', [], ['Accept']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('WEBP-BYTES', self::body($response));
        self::assertSame('image/webp', $response->headers->get('content-type'));
        self::assertSame('10', $response->headers->get('content-length'));
        self::assertSame('"6553f100-a"', $response->headers->get('etag'));
        self::assertSame('Tue, 14 Nov 2023 22:13:20 GMT', $response->headers->get('last-modified'));
        self::assertSame('bytes', $response->headers->get('accept-ranges'));
        self::assertSame('HIT', $response->headers->get('x-cache-status'));
        self::assertSame('Accept', $response->headers->get('vary'));
        self::assertSame('max-age=600, must-revalidate, public', $response->headers->get('cache-control'));
        self::assertSame('*', $response->headers->get('access-control-allow-origin'));
        self::assertSame('cross-origin', $response->headers->get('cross-origin-resource-policy'));
        self::assertSame('*', $response->headers->get('timing-allow-origin'));
        self::assertSame('nosniff', $response->headers->get('x-content-type-options'));
        self::assertFalse($response->headers->has('content-security-policy'));
    }

    public function testAnSvgIsSandboxed(): void
    {
        $file = $this->directory->write('logo.svg', '<svg/>');

        $response = $this->responder()->respond(Request::create('/logo.svg'), $file, 'svg', 'MISS');

        self::assertSame(ResponseFactory::SVG_CONTENT_SECURITY_POLICY, $response->headers->get('content-security-policy'));
        self::assertSame('MISS', $response->headers->get('x-cache-status'));
    }

    public function testTheEncodingTheClientPrefersIsSent(): void
    {
        $file = $this->directory->write('a.css', 'plain');
        $brotli = $this->directory->write('.br/a.css', 'brotli');
        $gzip = $this->directory->write('.gz/a.css', 'gzip');
        $encoded = ['br' => $brotli, 'gz' => $gzip];

        $both = $this->responder()->respond(Request::create('/a.css', 'GET', [], [], [], ['HTTP_ACCEPT_ENCODING' => 'gzip, deflate, br']), $file, 'css', 'HIT', $encoded);
        $gzipOnly = $this->responder()->respond(Request::create('/a.css', 'GET', [], [], [], ['HTTP_ACCEPT_ENCODING' => 'gzip, br;q=0']), $file, 'css', 'HIT', $encoded);
        $none = $this->responder()->respond(Request::create('/a.css'), $file, 'css', 'HIT', $encoded);

        self::assertSame('brotli', self::body($both));
        self::assertSame('br', $both->headers->get('content-encoding'));
        self::assertSame('text/css', $both->headers->get('content-type'));
        self::assertSame('Accept-Encoding', $both->headers->get('vary'));
        self::assertSame('gzip', self::body($gzipOnly));
        self::assertSame('gzip', $gzipOnly->headers->get('content-encoding'));
        self::assertSame('plain', self::body($none));
        self::assertFalse($none->headers->has('content-encoding'));
        self::assertSame('Accept-Encoding', $none->headers->get('vary'));
    }

    public function testAMissingSiblingFallsBackToTheFile(): void
    {
        $file = $this->directory->write('a.css', 'plain');

        $response = $this->responder()->respond(Request::create('/a.css', 'GET', [], [], [], ['HTTP_ACCEPT_ENCODING' => 'br']), $file, 'css', 'HIT', ['br' => $this->directory->path('.br/a.css')]);

        self::assertSame('plain', self::body($response));
    }

    public function testHeadSendsTheHeadersOnly(): void
    {
        $file = $this->directory->write('a.css', 'plain');

        $response = $this->responder()->respond(Request::create('/a.css', 'HEAD'), $file, 'css', 'HIT');

        self::assertSame('', $response->getContent());
        self::assertSame('5', $response->headers->get('content-length'));
    }

    /**
     * Opportunity O-F: Apache mod_xsendfile sends the file.
     */
    public function testXSendfileHandsTheFileToApache(): void
    {
        $file = $this->directory->write('_cache/h/a.css', 'plain');

        $response = $this->responder(Config::SENDFILE_X_SENDFILE)->respond(self::fromServer('/a.css', 'x-sendfile'), $file, 'css', 'HIT');

        self::assertSame('', $response->getContent());
        self::assertSame($file, $response->headers->get('x-sendfile'));
        self::assertSame('text/css', $response->headers->get('content-type'));
    }

    /**
     * Without the module, an empty response would reach the client: the
     * web server must declare the mode, and PHP streams the file
     * otherwise. A request header cannot declare it.
     *
     * @dataProvider provideUndeclaredSendfile
     */
    #[DataProvider('provideUndeclaredSendfile')]
    public function testSendfileFallsBackToStreamingUnlessTheServerDeclaresIt(string $mode, Request $request): void
    {
        $file = $this->directory->write('_cache/h/a.css', 'plain');

        $response = $this->responder($mode)->respond($request, $file, 'css', 'HIT');

        self::assertNull($response->headers->get('x-sendfile'));
        self::assertNull($response->headers->get('x-accel-redirect'));
        self::assertSame('plain', self::body($response));
    }

    /**
     * @return array<string, array{string, Request}>
     */
    public static function provideUndeclaredSendfile(): array
    {
        return [
            'x-sendfile, nothing declared' => [Config::SENDFILE_X_SENDFILE, Request::create('/a.css')],
            'x-sendfile, the other mode declared' => [Config::SENDFILE_X_SENDFILE, self::fromServer('/a.css', 'x-accel-redirect')],
            'x-sendfile, a request header' => [Config::SENDFILE_X_SENDFILE, Request::create('/a.css', 'GET', [], [], [], ['HTTP_PROXYCDN_SENDFILE' => 'x-sendfile'])],
            'x-accel-redirect, nothing declared' => [Config::SENDFILE_X_ACCEL_REDIRECT, Request::create('/a.css')],
        ];
    }

    public function testAnApacheInternalRedirectKeepsTheDeclaration(): void
    {
        $file = $this->directory->write('_cache/h/a.css', 'plain');

        $request = Request::create('/a.css', 'GET', [], [], [], ['REDIRECT_PROXYCDN_SENDFILE' => 'x-sendfile']);
        $response = $this->responder(Config::SENDFILE_X_SENDFILE)->respond($request, $file, 'css', 'HIT');

        self::assertSame($file, $response->headers->get('x-sendfile'));
    }

    /**
     * An entry PHP revalidates carries the max-age it has left, and PHP
     * sends it itself: the web server would add the Cache-Control of
     * CACHE_EXPIRY (finding P-15).
     */
    public function testAManagedEntryIsSentByPhpWithItsMaxAge(): void
    {
        $file = $this->directory->write('_cache/h/.m/a.css', 'body {}');

        $response = $this->responder(Config::SENDFILE_X_ACCEL_REDIRECT)->respond(self::fromServer('/a.css', 'x-accel-redirect'), $file, 'css', 'HIT', [], [], 42);

        self::assertNull($response->headers->get('x-accel-redirect'));
        self::assertSame('body {}', self::body($response));
        self::assertSame('max-age=42, must-revalidate, public', $response->headers->get('cache-control'));
    }

    /**
     * A response the origin forbade storing is streamed from the temporary
     * file of the fetch, which is then deleted (finding P-15).
     */
    public function testAnUncachedBodyIsStreamedThenDeleted(): void
    {
        $body = ResponseBody::fromString('secret();', $this->directory->path());
        self::assertNotNull($body);
        $path = $body->getPath();

        $response = $this->responder()->respondUncached(Request::create('/a.js'), $body, 'js', true);

        self::assertSame('secret();', self::body($response));
        self::assertSame('BYPASS', $response->headers->get('x-cache-status'));
        self::assertSame('9', $response->headers->get('content-length'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        self::assertSame('*', $response->headers->get('access-control-allow-origin'));
        self::assertFileDoesNotExist($path);
    }

    /**
     * Opportunity O-F: nginx sends the file from its internal location.
     */
    public function testXAccelRedirectHandsTheFileToNginx(): void
    {
        $file = $this->directory->write('_cache/h/.q/v=1/my file.png', 'png');

        $response = $this->responder(Config::SENDFILE_X_ACCEL_REDIRECT)->respond(self::fromServer('/my%20file.png?v=1', 'x-accel-redirect'), $file, 'png', 'HIT');

        self::assertSame('', $response->getContent());
        self::assertSame('/_cache/h/.q/v%3D1/my%20file.png', $response->headers->get('x-accel-redirect'));
        self::assertSame('image/png', $response->headers->get('content-type'));
    }

    /**
     * Finding S-6: a single byte range of a file PHP streams gets 206 with
     * Content-Range, whether it names both ends, only the first byte
     * (open-ended) or the last bytes (suffix); a last byte past the end is
     * cut to the end.
     *
     * @param array{int, int} $bounds
     *
     * @dataProvider provideSatisfiableRanges
     */
    #[DataProvider('provideSatisfiableRanges')]
    public function testASingleRangeIsPartialContent(string $range, string $body, array $bounds): void
    {
        $response = $this->responder()->respond(self::withRange($range), $this->rangeFile(), 'js', 'HIT');

        self::assertSame(206, $response->getStatusCode());
        self::assertSame($body, self::body($response));
        self::assertSame(sprintf('bytes %d-%d/10', $bounds[0], $bounds[1]), $response->headers->get('content-range'));
        self::assertSame((string) strlen($body), $response->headers->get('content-length'));
        self::assertSame('bytes', $response->headers->get('accept-ranges'));
    }

    /**
     * @return array<string, array{string, string, array{int, int}}>
     */
    public static function provideSatisfiableRanges(): array
    {
        return [
            'both ends' => ['bytes=2-5', '2345', [2, 5]],
            'one byte' => ['bytes=0-0', '0', [0, 0]],
            'open-ended' => ['bytes=7-', '789', [7, 9]],
            'suffix' => ['bytes=-3', '789', [7, 9]],
            'suffix longer than the file' => ['bytes=-50', '0123456789', [0, 9]],
            'last byte past the end' => ['bytes=8-99', '89', [8, 9]],
            'unit in upper case' => ['BYTES=1-2', '12', [1, 2]],
        ];
    }

    /**
     * A range that selects no byte of the file gets 416, no body, and a
     * Content-Range of "bytes", "*", "/" and the length of the file.
     *
     * @dataProvider provideUnsatisfiableRanges
     */
    #[DataProvider('provideUnsatisfiableRanges')]
    public function testAnUnsatisfiableRangeIs416(string $range): void
    {
        $response = $this->responder()->respond(self::withRange($range), $this->rangeFile(), 'js', 'HIT');

        self::assertSame(416, $response->getStatusCode());
        self::assertSame('bytes */10', $response->headers->get('content-range'));
        self::assertSame('', self::body($response));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnsatisfiableRanges(): array
    {
        return [
            'first byte at the end' => ['bytes=10-'],
            'first byte past the end' => ['bytes=20-30'],
            'first byte after the last' => ['bytes=5-2'],
            'empty suffix' => ['bytes=-0'],
        ];
    }

    /**
     * A Range header that is not a single "bytes=" range is ignored, as
     * RFC 9110 allows: the whole file, 200. That includes a multi-range
     * request: PHP never answers multipart/byteranges.
     *
     * @dataProvider provideIgnoredRanges
     */
    #[DataProvider('provideIgnoredRanges')]
    public function testAMalformedOrMultipleRangeGetsTheWholeFile(string $range): void
    {
        $response = $this->responder()->respond(self::withRange($range), $this->rangeFile(), 'js', 'HIT');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('0123456789', self::body($response));
        self::assertNull($response->headers->get('content-range'));
        self::assertSame('10', $response->headers->get('content-length'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideIgnoredRanges(): array
    {
        return [
            'not a number' => ['bytes=abc'],
            'no bounds' => ['bytes=-'],
            'another unit' => ['items=0-1'],
            'no unit' => ['0-1'],
            'two ranges' => ['bytes=0-1,4-5'],
            'two ranges with a space' => ['bytes=0-1, 4-5'],
        ];
    }

    /**
     * If-Range: the range is honoured when it names the current version,
     * by entity tag or by Last-Modified; otherwise the client gets the
     * whole current file.
     *
     * @dataProvider provideIfRange
     */
    #[DataProvider('provideIfRange')]
    public function testIfRangeSendsTheRangeOnlyForTheCurrentVersion(string $ifRange, int $status, string $body): void
    {
        $request = self::withRange('bytes=2-5', ['HTTP_IF_RANGE' => $ifRange]);

        $response = $this->responder()->respond($request, $this->rangeFile(), 'js', 'HIT');

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($body, self::body($response));
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function provideIfRange(): array
    {
        return [
            'matching entity tag' => ['"6553f100-a"', 206, '2345'],
            'matching Last-Modified' => ['Tue, 14 Nov 2023 22:13:20 GMT', 206, '2345'],
            'other entity tag' => ['"6553f100-b"', 200, '0123456789'],
            'weak entity tag' => ['W/"6553f100-a"', 200, '0123456789'],
            'other date' => ['Tue, 14 Nov 2023 22:13:21 GMT', 200, '0123456789'],
        ];
    }

    /**
     * A conditional request whose copy is current gets 304 also when it
     * asks for a range; when its copy is not current, the range applies.
     *
     * @dataProvider provideConditionalRanges
     */
    #[DataProvider('provideConditionalRanges')]
    public function testAConditionalRangeRequest(string $header, string $value, int $status, string $body): void
    {
        $request = self::withRange('bytes=2-5', [$header => $value]);

        $response = $this->responder()->respond($request, $this->rangeFile(), 'js', 'HIT');

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($body, self::body($response));
        if ($status === 304) {
            self::assertNull($response->headers->get('content-range'));
            self::assertSame('"6553f100-a"', $response->headers->get('etag'));
        }
    }

    /**
     * @return array<string, array{string, string, int, string}>
     */
    public static function provideConditionalRanges(): array
    {
        return [
            'If-None-Match current' => ['HTTP_IF_NONE_MATCH', '"6553f100-a"', 304, ''],
            'If-None-Match weak and current' => ['HTTP_IF_NONE_MATCH', 'W/"6553f100-a"', 304, ''],
            'If-None-Match among others' => ['HTTP_IF_NONE_MATCH', '"x", "6553f100-a"', 304, ''],
            'If-None-Match any' => ['HTTP_IF_NONE_MATCH', '*', 304, ''],
            'If-None-Match outdated' => ['HTTP_IF_NONE_MATCH', '"6553f100-b"', 206, '2345'],
            'If-Modified-Since current' => ['HTTP_IF_MODIFIED_SINCE', 'Tue, 14 Nov 2023 22:13:20 GMT', 304, ''],
            'If-Modified-Since outdated' => ['HTTP_IF_MODIFIED_SINCE', 'Tue, 14 Nov 2023 22:13:19 GMT', 206, '2345'],
        ];
    }

    /**
     * If-None-Match takes precedence over If-Modified-Since (RFC 9110).
     */
    public function testIfNoneMatchOverridesIfModifiedSince(): void
    {
        $request = self::withRange('bytes=2-5', [
            'HTTP_IF_NONE_MATCH' => '"6553f100-b"',
            'HTTP_IF_MODIFIED_SINCE' => 'Tue, 14 Nov 2023 22:13:20 GMT',
        ]);

        $response = $this->responder()->respond($request, $this->rangeFile(), 'js', 'HIT');

        self::assertSame(206, $response->getStatusCode());
    }

    /**
     * HEAD ignores Range, as it may: the headers of the whole file, without
     * body.
     */
    public function testHeadIgnoresTheRange(): void
    {
        $request = Request::create('/a.js', 'HEAD', [], [], [], ['HTTP_RANGE' => 'bytes=2-5']);

        $response = $this->responder()->respond($request, $this->rangeFile(), 'js', 'HIT');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', self::body($response));
        self::assertSame('10', $response->headers->get('content-length'));
        self::assertNull($response->headers->get('content-range'));
    }

    /**
     * A purge unlinks the file between the response and its body: the
     * handle opened for the response still holds it, so the body is the
     * length declared.
     */
    public function testAFileUnlinkedBeforeItsBodyIsSentWhole(): void
    {
        $content = str_repeat('a', 1000);
        $file = $this->directory->write('a.js', $content);

        $response = $this->responder()->respond(Request::create('/a.js'), $file, 'js', 'HIT');
        unlink($file);

        self::assertSame('1000', $response->headers->get('content-length'));
        self::assertSame($content, self::body($response));
    }

    /**
     * A revalidation renames a new file over the entry between the
     * response and its body: the body is still the file that was
     * measured, with its entity tag, whole or in the range asked.
     */
    public function testAFileReplacedBeforeItsBodyIsTheOneMeasured(): void
    {
        $content = str_repeat('b', 1000);
        $file = $this->directory->write('a.js', $content);
        touch($file, 1700000000);

        $whole = $this->responder()->respond(Request::create('/a.js'), $file, 'js', 'HIT');
        $range = $this->responder()->respond(self::withRange('bytes=990-'), $file, 'js', 'HIT');
        $replacement = $this->directory->write('a.js.tmp', 'short');
        rename($replacement, $file);

        self::assertSame('1000', $whole->headers->get('content-length'));
        self::assertSame('"6553f100-3e8"', $whole->headers->get('etag'));
        self::assertSame($content, self::body($whole));
        self::assertSame('10', $range->headers->get('content-length'));
        self::assertSame(str_repeat('b', 10), self::body($range));
    }

    /**
     * A file truncated in place leaves less than was declared: the body
     * stops there, without padding, and the cut is logged.
     */
    public function testAFileTruncatedBeforeItsBodyIsCutShortAndLogged(): void
    {
        $file = $this->directory->write('a.js', str_repeat('c', 1000));
        $logger = new MemoryLogger();

        $response = $this->responder(Config::SENDFILE_NONE, $logger)->respond(Request::create('/a.js'), $file, 'js', 'HIT');
        $handle = fopen($file, 'r+');
        self::assertNotFalse($handle);
        ftruncate($handle, 5);
        fclose($handle);

        self::assertSame('1000', $response->headers->get('content-length'));
        self::assertSame('ccccc', self::body($response));
        $records = $logger->recordsWithMessage('response body cut short');
        self::assertCount(1, $records);
        self::assertSame('error', $records[0]['level']);
        self::assertSame(['file' => $file, 'declared' => 1000, 'sent' => 5], $records[0]['context']);
    }

    /**
     * The temporary file of an uncached response is open once the
     * response is built: it is sent whole even if it is gone by then.
     */
    public function testAnUncachedBodyIsSentFromTheHandleOpenedForIt(): void
    {
        $body = ResponseBody::fromString(str_repeat('d', 1000), $this->directory->path());
        self::assertNotNull($body);

        $response = $this->responder()->respondUncached(Request::create('/a.js'), $body, 'js', true);
        unlink($body->getPath());

        self::assertSame('1000', $response->headers->get('content-length'));
        self::assertSame(str_repeat('d', 1000), self::body($response));
    }

    public function testADirectoryIsAnInternalError(): void
    {
        $this->directory->write('dir/a.js', 'x');

        $response = $this->responder()->respond(Request::create('/dir'), $this->directory->path('dir'), 'css', 'HIT');

        self::assertSame(500, $response->getStatusCode());
    }

    public function testAMissingFileIsAnInternalError(): void
    {
        $response = $this->responder()->respond(Request::create('/missing.css'), $this->directory->path('missing.css'), 'css', 'HIT');

        self::assertSame(500, $response->getStatusCode());
        self::assertSame("The request could not be processed.\nInternal server error: Cannot read cached file from disk.", $response->getContent());
    }

    /**
     * A request whose web server declares that it takes the files with $mode.
     */
    /**
     * A GET of "/a.js" with $range, and the other server variables.
     *
     * @param array<string, string> $server
     */
    private static function withRange(string $range, array $server = []): Request
    {
        return Request::create('/a.js', 'GET', [], [], [], ['HTTP_RANGE' => $range] + $server);
    }

    /**
     * The ten bytes "0123456789", whose entity tag is "6553f100-a".
     */
    private function rangeFile(): string
    {
        $file = $this->directory->write('a.js', '0123456789');
        touch($file, 1700000000);

        return $file;
    }

    private static function fromServer(string $uri, string $mode): Request
    {
        return Request::create($uri, 'GET', [], [], [], ['PROXYCDN_SENDFILE' => $mode]);
    }

    private function responder(string $mode = Config::SENDFILE_NONE, ?MemoryLogger $logger = null): FileResponder
    {
        return new FileResponder(new FilesystemStorage(), new ResponseFactory(600, 30), $mode, $this->directory->path('_cache'), $logger);
    }

    private static function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
