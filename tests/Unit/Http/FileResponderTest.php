<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Http;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Http\FileResponder;
use Mfonte\ProxyCdn\Http\ResponseFactory;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
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

    public function testAMissingFileIsAnInternalError(): void
    {
        $response = $this->responder()->respond(Request::create('/missing.css'), $this->directory->path('missing.css'), 'css', 'HIT');

        self::assertSame(500, $response->getStatusCode());
        self::assertSame("The request could not be processed.\nInternal server error: Cannot read cached file from disk.", $response->getContent());
    }

    /**
     * A request whose web server declares that it takes the files with $mode.
     */
    private static function fromServer(string $uri, string $mode): Request
    {
        return Request::create($uri, 'GET', [], [], [], ['PROXYCDN_SENDFILE' => $mode]);
    }

    private function responder(string $mode = Config::SENDFILE_NONE): FileResponder
    {
        return new FileResponder(new FilesystemStorage(), new ResponseFactory(600, 30), $mode, $this->directory->path('_cache'));
    }

    private static function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
