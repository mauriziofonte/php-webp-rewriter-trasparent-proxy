<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Functional\Http;

use Mfonte\ProxyCdn\Http\ProxyKernel;
use Mfonte\ProxyCdn\Image\ImageConverterFactory;
use Mfonte\ProxyCdn\Image\SignedUrlVerifier;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\FakeApi\FakeApiServer;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\TestKernel;
use Mfonte\ProxyCdn\Tests\Fixtures\Image\FakeImageConverter;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\ProxyCdn\Tests\Fixtures\Origin\FakeOriginFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The image variants and the signed resize URLs through ProxyKernel, with
 * a fake converter that writes small valid images.
 *
 * @covers \Mfonte\ProxyCdn\Http\ProxyKernel
 */
#[CoversClass(ProxyKernel::class)]
final class ImageConversionTest extends TestCase
{
    private const ORIGIN = 'https://origin.example.test';

    private const FIXTURES = __DIR__ . '/../../Fixtures/Image/files/';

    private const RESIZE_KEY = 'resize-key-0123456789abcdef0123456789';

    private const PURGE_SECRET = 'purge-secret-0123456789';

    private const ACCEPT_ALL = 'image/avif,image/webp,*/*';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var FakeOriginFetcher
     */
    private $origin;

    /**
     * @var MemoryLogger
     */
    private $logger;

    /**
     * @var FakeImageConverter
     */
    private $converter;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->origin = new FakeOriginFetcher();
        $this->logger = new MemoryLogger();
        $this->converter = new FakeImageConverter();
        $this->origin->serve(self::ORIGIN . '/images/logo.png', 'PNG-BYTES', ['content-type' => 'image/png']);
        $this->origin->serve(self::ORIGIN . '/images/photo.png', (string) file_get_contents(self::FIXTURES . 'image.png'), ['content-type' => 'image/png']);
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testAMissIsServedAsTheBestVariantTheClientAccepts(): void
    {
        $avif = $this->kernel()->handle(self::request('/images/logo.png', self::ACCEPT_ALL));
        $webp = $this->kernel()->handle(self::request('/images/photo.png', 'image/webp,*/*'));

        self::assertSame(200, $avif->getStatusCode());
        self::assertSame('image/avif', $avif->headers->get('content-type'));
        self::assertStringEqualsFile(self::FIXTURES . 'variant.avif', self::body($avif));
        self::assertSame('Accept', $avif->headers->get('vary'));
        self::assertSame('MISS', $avif->headers->get('x-cache-status'));
        self::assertSame('image/webp', $webp->headers->get('content-type'));
        self::assertStringEqualsFile(self::FIXTURES . 'variant.webp', self::body($webp));
        self::assertFileExists($this->cachePath('.avif/images/logo.png.avif'));
        self::assertFileExists($this->cachePath('.webp/images/logo.png.webp'));
        self::assertSame('PNG-BYTES', file_get_contents($this->cachePath('images/logo.png')));
    }

    public function testOtherClientsGetTheOriginalAndTheVariantsAreStillMade(): void
    {
        $response = $this->kernel()->handle(self::request('/images/logo.png', 'image/*'));

        self::assertSame('PNG-BYTES', self::body($response));
        self::assertSame('image/png', $response->headers->get('content-type'));
        self::assertSame('Accept', $response->headers->get('vary'), 'finding P-1: the variant depends on Accept');
        self::assertFileExists($this->cachePath('.webp/images/logo.png.webp'));
    }

    /**
     * Carried item: the request ID (PRX-9) reaches the converter.
     */
    public function testTheRequestIdIsForwarded(): void
    {
        $request = self::request('/images/logo.png', '');
        $request->attributes->set(ProxyKernel::REQUEST_ID_ATTRIBUTE, 'req-42');

        $this->kernel()->handle($request);

        self::assertSame(['req-42', 'req-42'], array_column($this->converter->getCalls(), 3));
    }

    /**
     * Finding P-10: a conversion that fails serves the original, never a
     * 5xx, and is not tried again on every hit PHP serves.
     */
    public function testAFailedConversionServesTheOriginalAndIsNotRetriedP10(): void
    {
        $this->origin->serve(self::ORIGIN . '/images/broken.png?v=1', 'broken PNG', ['content-type' => 'image/png']);

        $miss = $this->kernel(['QUERY_STRING_ALLOWLIST' => 'v'])->handle(self::request('/images/broken.png?v=1', self::ACCEPT_ALL));
        $hit = $this->kernel(['QUERY_STRING_ALLOWLIST' => 'v'])->handle(self::request('/images/broken.png?v=1', self::ACCEPT_ALL));

        self::assertSame(200, $miss->getStatusCode());
        self::assertSame('broken PNG', self::body($miss));
        self::assertSame('MISS', $miss->headers->get('x-cache-status'));
        self::assertSame(200, $hit->getStatusCode());
        self::assertSame('HIT', $hit->headers->get('x-cache-status'));
        self::assertSame(['avif', 'webp'], $this->converter->getFormats(), 'one attempt per variant');
        self::assertCount(2, $this->logger->recordsWithMessage('image conversion failed, the original is served'));
    }

    public function testAnUnavailableConverterServesTheOriginal(): void
    {
        $this->converter->setUnavailable(true);

        $response = $this->kernel()->handle(self::request('/images/logo.png', self::ACCEPT_ALL));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('PNG-BYTES', self::body($response));
        self::assertFileExists($this->cachePath('images/logo.png'));
    }

    public function testABucketHitIsConvertedOnce(): void
    {
        $this->origin->serve(self::ORIGIN . '/images/logo.png?v=2', 'PNG-V2', ['content-type' => 'image/png']);
        TestKernel::create(TestConfig::create(['SOURCE_ORIGIN' => self::ORIGIN, 'QUERY_STRING_ALLOWLIST' => 'v']), $this->directory->path(), $this->origin, null, $this->logger)
            ->handle(self::request('/images/logo.png?v=2', ''));

        $first = $this->kernel(['QUERY_STRING_ALLOWLIST' => 'v'])->handle(self::request('/images/logo.png?v=2', 'image/webp'));
        $second = $this->kernel(['QUERY_STRING_ALLOWLIST' => 'v'])->handle(self::request('/images/logo.png?v=2', 'image/webp'));

        self::assertSame('image/webp', $first->headers->get('content-type'));
        self::assertSame('HIT', $first->headers->get('x-cache-status'));
        self::assertSame('image/webp', $second->headers->get('content-type'));
        self::assertSame(['webp'], $this->converter->getFormats());
        self::assertCount(1, $this->origin->getRequests());
    }

    /**
     * A hit PHP serves (its variant on disk, or a client that takes no
     * variant) and a miss of an asset that is not a raster never ask the
     * converter: no build of the library's converter, no isolation or
     * capability detection, no /healthz.
     */
    public function testAHitAndANonImageMissNeverAskTheConverter(): void
    {
        $this->origin->serve(self::ORIGIN . '/css/site.css', 'body{}', ['content-type' => 'text/css']);
        $this->kernel()->handle(self::request('/images/logo.png', self::ACCEPT_ALL));
        $asked = [$this->converter->getCapabilityCalls(), count($this->converter->getCalls())];

        $css = $this->kernel()->handle(self::request('/css/site.css', self::ACCEPT_ALL));
        $variant = $this->kernel()->handle(self::request('/images/logo.png', self::ACCEPT_ALL));
        $original = $this->kernel()->handle(self::request('/images/logo.png', 'image/*'));

        self::assertSame(['MISS', 'body{}'], [$css->headers->get('x-cache-status'), self::body($css)]);
        self::assertSame(['HIT', 'image/avif'], [$variant->headers->get('x-cache-status'), $variant->headers->get('content-type')]);
        self::assertSame(['HIT', 'image/png'], [$original->headers->get('x-cache-status'), $original->headers->get('content-type')]);
        self::assertSame($asked, [$this->converter->getCapabilityCalls(), count($this->converter->getCalls())]);
    }

    /**
     * The same paths with the real remote converter send nothing to the
     * conversion API: its capabilities are discovered on the first
     * conversion only.
     */
    public function testAHitAndANonImageMissNeverCallTheConversionApi(): void
    {
        mkdir($this->directory->path('api'));
        $api = FakeApiServer::start($this->directory->path('api'));
        try {
            $config = TestConfig::create([
                'SOURCE_ORIGIN' => self::ORIGIN,
                'IMAGE_CONVERTER' => 'remote',
                'IMAGE_API_URL' => $api->getBaseUrl(),
                'IMAGE_API_KEY' => 'api-key-0123456789abcdef',
            ]);
            $converter = ImageConverterFactory::create($config, $this->directory->path('state'), $this->logger);
            $this->origin->serve(self::ORIGIN . '/css/site.css', 'body{}', ['content-type' => 'text/css']);
            $this->directory->write('_cache/cdn.example.test/images/logo.png', 'PNG-BYTES');
            copy(self::FIXTURES . 'variant.avif', $this->directory->write('_cache/cdn.example.test/.avif/images/logo.png.avif', ''));

            $css = TestKernel::create($config, $this->directory->path(), $this->origin, $converter, $this->logger)->handle(self::request('/css/site.css', self::ACCEPT_ALL));
            $hit = TestKernel::create($config, $this->directory->path(), $this->origin, $converter, $this->logger)->handle(self::request('/images/logo.png', self::ACCEPT_ALL));

            self::assertSame('MISS', $css->headers->get('x-cache-status'));
            self::assertSame(['HIT', 'image/avif'], [$hit->headers->get('x-cache-status'), $hit->headers->get('content-type')]);
            self::assertSame([], $api->getRequests());
        } finally {
            $api->stop();
        }
    }

    /**
     * Opportunity O-I, risk L-14: a resize URL that is not signed, or
     * whose width is not listed, is refused without touching the origin.
     */
    public function testAnUnsignedOrUnlistedResizeIsRefusedWithoutFetching(): void
    {
        $signedUnlisted = (new SignedUrlVerifier(self::RESIZE_KEY, [999]))->pathOf('cdn.example.test', 999, '/images/photo.png', '');

        foreach (['/_resize/320/images/photo.png', '/_resize/320/' . str_repeat('0', 32) . '/images/photo.png', $signedUnlisted] as $path) {
            $response = $this->resizingKernel()->handle(self::request($path, ''));

            self::assertSame(403, $response->getStatusCode(), $path);
            self::assertStringContainsString('Resize URL refused', self::body($response));
        }
        self::assertSame([], $this->origin->getRequests());
        self::assertSame([], $this->converter->getCalls());
    }

    public function testASignedResizeIsResizedStoredAndServed(): void
    {
        $path = $this->resizePath(320, '/images/photo.png');

        $miss = $this->resizingKernel()->handle(self::request($path, ''));
        $hit = $this->resizingKernel()->handle(self::request($path, ''));
        $webp = $this->resizingKernel()->handle(self::request($path, 'image/webp'));

        self::assertSame(200, $miss->getStatusCode());
        self::assertSame('MISS', $miss->headers->get('x-cache-status'));
        self::assertSame('image/png', $miss->headers->get('content-type'));
        self::assertStringEqualsFile(self::FIXTURES . 'resized.png', self::body($miss));
        self::assertSame('HIT', $hit->headers->get('x-cache-status'));
        self::assertSame('image/webp', $webp->headers->get('content-type'));
        self::assertSame([self::ORIGIN . '/images/photo.png'], $this->origin->getRequestedUrls());
        self::assertFileEquals(self::FIXTURES . 'resized.png', $this->cachePath(ltrim($path, '/')));
        self::assertSame(['original', 'avif', 'webp'], $this->converter->getFormats());
        // The signature is masked in the logs.
        self::assertStringNotContainsString(explode('/', $path)[3], (string) json_encode($this->logger->getRecords()));
    }

    public function testAPurgeOfTheImageRemovesItsResizedEntries(): void
    {
        $path = $this->resizePath(320, '/images/photo.png');
        $this->resizingKernel()->handle(self::request($path, ''));
        self::assertFileExists($this->cachePath(ltrim($path, '/')));

        $response = $this->resizingKernel()->handle(Request::create('https://cdn.example.test/images/photo.png', 'PURGE', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . self::PURGE_SECRET]));

        self::assertSame(200, $response->getStatusCode());
        self::assertFileDoesNotExist($this->cachePath(ltrim($path, '/')));
        self::assertFileDoesNotExist($this->cachePath('.webp' . $path . '.webp'));
    }

    public function testAnImageThatCannotBeResizedIsStoredAsItIs(): void
    {
        $path = $this->resizePath(640, '/images/logo.png');

        $response = $this->resizingKernel()->handle(self::request($path, ''));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('PNG-BYTES', self::body($response));
        self::assertSame('PNG-BYTES', file_get_contents($this->cachePath(ltrim($path, '/'))));
    }

    public function testAResizeTheConverterCannotMakeNowStreamsTheOriginal(): void
    {
        $this->converter->setUnavailable(true);
        $path = $this->resizePath(320, '/images/photo.png');

        $response = $this->resizingKernel()->handle(self::request($path, ''));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringEqualsFile(self::FIXTURES . 'image.png', self::body($response));
        self::assertFileDoesNotExist($this->cachePath(ltrim($path, '/')));
    }

    public function testAResizeOfAMissingImageIsA404(): void
    {
        $response = $this->resizingKernel()->handle(self::request($this->resizePath(320, '/images/none.png'), ''));

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private function kernel(array $overrides = []): ProxyKernel
    {
        return TestKernel::create(
            TestConfig::create(['SOURCE_ORIGIN' => self::ORIGIN, 'IMAGE_CONVERTER' => 'local'] + $overrides),
            $this->directory->path(),
            $this->origin,
            $this->converter,
            $this->logger
        );
    }

    private function resizingKernel(): ProxyKernel
    {
        return $this->kernel(['RESIZE_WIDTHS' => '320,640', 'RESIZE_KEY' => self::RESIZE_KEY, 'PURGE_KEYS' => '*=' . self::PURGE_SECRET]);
    }

    private function resizePath(int $width, string $path): string
    {
        return (new SignedUrlVerifier(self::RESIZE_KEY, [320, 640]))->pathOf('cdn.example.test', $width, $path, '');
    }

    private static function request(string $path, string $accept): Request
    {
        return Request::create('https://cdn.example.test' . $path, 'GET', [], [], [], $accept === '' ? [] : ['HTTP_ACCEPT' => $accept]);
    }

    private function cachePath(string $relativePath): string
    {
        return $this->directory->path('_cache/cdn.example.test/' . $relativePath);
    }

    private static function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
