<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Http;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Http\FileResponder;
use Mfonte\ProxyCdn\Http\ResponseFactory;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @var FileResponder
     */
    private $responder;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->responder = new FileResponder(new FilesystemStorage(), new ResponseFactory(600, 30));
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testTheFileIsServedWithHeadersRebuiltFromTheExtension(): void
    {
        $file = $this->directory->write('images/logo.webp', 'WEBP-BYTES');

        $response = $this->responder->respond($file, 'webp');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('WEBP-BYTES', $response->getContent());
        self::assertSame('image/webp', $response->headers->get('content-type'));
        self::assertSame('10', $response->headers->get('content-length'));
        self::assertSame('hit', $response->headers->get('x-cache-status'));
        self::assertSame('Accept', $response->headers->get('vary'));
        self::assertSame('max-age=600, must-revalidate, public', $response->headers->get('cache-control'));
    }

    public function testAnUnreadableFileIsAnInternalError(): void
    {
        $response = $this->responder->respond($this->directory->path('missing.css'), 'css');

        self::assertSame(500, $response->getStatusCode());
        self::assertSame("The request could not be processed.\nInternal server error: Cannot read cached file from disk.", $response->getContent());
    }

    /**
     * @dataProvider provideMimeTypes
     */
    #[DataProvider('provideMimeTypes')]
    public function testMimeTypeOf(string $extension, string $mimeType): void
    {
        self::assertSame($mimeType, FileResponder::mimeTypeOf($extension));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideMimeTypes(): array
    {
        return [
            'css' => ['css', 'text/css'],
            'js' => ['js', 'application/javascript'],
            'upper case' => ['PNG', 'image/png'],
            'jpeg' => ['jpeg', 'image/jpeg'],
            'svg' => ['svg', 'image/svg+xml'],
            // The 2.x table disagrees with the fetch path (finding P-23).
            'ttf P-23' => ['ttf', 'application/x-font-ttf'],
            'woff2' => ['woff2', 'application/font-woff2'],
            'ico' => ['ico', 'image/x-icon'],
            'unknown' => ['avif', 'application/octet-stream'],
        ];
    }
}
