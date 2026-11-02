<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Functional\Http;

use Mfonte\ProxyCdn\Http\ProxyKernel;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
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
 * The image variants through ProxyKernel, with a fake converter that
 * writes small valid images.
 *
 * @covers \Mfonte\ProxyCdn\Http\ProxyKernel
 */
#[CoversClass(ProxyKernel::class)]
final class ImageConversionTest extends TestCase
{
    private const ORIGIN = 'https://origin.example.test';

    private const FIXTURES = __DIR__ . '/../../Fixtures/Image/files/';

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
