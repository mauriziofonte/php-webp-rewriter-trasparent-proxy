<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Http;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Http\FileResponder;
use Mfonte\ProxyCdn\Http\ResponseFactory;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
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
     * of the extension, the entity tag nginx computes, CORS and nosniff.
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

    public function testAMissingFileIsAnInternalError(): void
    {
        $response = $this->responder()->respond(Request::create('/missing.css'), $this->directory->path('missing.css'), 'css', 'HIT');

        self::assertSame(500, $response->getStatusCode());
        self::assertSame("The request could not be processed.\nInternal server error: Cannot read cached file from disk.", $response->getContent());
    }

    private function responder(): FileResponder
    {
        return new FileResponder(new FilesystemStorage(), new ResponseFactory(600, 30));
    }

    private static function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
