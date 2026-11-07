<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Functional\Http;

use Mfonte\ProxyCdn\Cache\RequestCoalescer;
use Mfonte\ProxyCdn\Http\ProxyKernel;
use Mfonte\ProxyCdn\Image\ImageConverter;
use Mfonte\ProxyCdn\Image\ImageConverterFactory;
use Mfonte\ProxyCdn\Image\SignedUrlVerifier;
use Mfonte\ProxyCdn\Origin\OriginResponse;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\FakeApi\FakeApiServer;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\CoalescerSeam;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\TestKernel;
use Mfonte\ProxyCdn\Tests\Fixtures\Image\FakeImageConverter;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\ProxyCdn\Tests\Fixtures\Origin\FakeOriginFetcher;
use Mfonte\ProxyCdn\Tests\Fixtures\Origin\LockProbingOrigin;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ConversionResult;
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

    /**
     * Finding P-10, decision (a) of the review: a miss stores and serves
     * the original at once, with "Vary: Accept" and "max-age=0,
     * must-revalidate" so that no cache keeps it for a client that will
     * get a variant; the variants are made after the response, and served
     * from the next request on. Until they are, the entry is pending, out
     * of the reach of the web server, which would serve the original as
     * a hit for CACHE_EXPIRY: it is published once they are made.
     */
    public function testAMissServesTheOriginalAndTheVariantsFollow(): void
    {
        $kernel = $this->kernel();
        $miss = $kernel->handle(self::request('/images/logo.png', self::ACCEPT_ALL));

        self::assertSame(200, $miss->getStatusCode());
        self::assertSame(['MISS', 'image/png', 'PNG-BYTES'], [$miss->headers->get('x-cache-status'), $miss->headers->get('content-type'), self::body($miss)]);
        self::assertSame('Accept', $miss->headers->get('vary'));
        self::assertSame('max-age=0, must-revalidate, public', $miss->headers->get('cache-control'));
        self::assertSame([], $this->converter->getCalls(), 'nothing is converted before the response');
        self::assertFileDoesNotExist($this->cachePath('images/logo.png'), 'not published before its variants');
        self::assertFileExists($this->cachePath('.pending/images/logo.png'));

        $kernel->terminate();
        self::assertFileExists($this->cachePath('images/logo.png'), 'published with its variants');
        self::assertFileDoesNotExist($this->cachePath('.pending/images/logo.png'));
        $avif = $this->kernel()->handle(self::request('/images/logo.png', self::ACCEPT_ALL));
        $webp = $this->kernel()->handle(self::request('/images/logo.png', 'image/webp,*/*'));

        self::assertSame(['HIT', 'image/avif'], [$avif->headers->get('x-cache-status'), $avif->headers->get('content-type')]);
        self::assertStringEqualsFile(self::FIXTURES . 'variant.avif', self::body($avif));
        self::assertStringNotContainsString('max-age=0', (string) $avif->headers->get('cache-control'));
        self::assertSame('image/webp', $webp->headers->get('content-type'));
        self::assertStringEqualsFile(self::FIXTURES . 'variant.webp', self::body($webp));
        self::assertFileExists($this->cachePath('.avif/images/logo.png.avif'));
        self::assertFileExists($this->cachePath('.webp/images/logo.png.webp'));
    }

    public function testOtherClientsGetTheOriginalAndTheVariantsAreStillMade(): void
    {
        $response = $this->respond(self::request('/images/logo.png', 'image/*'));

        self::assertSame('PNG-BYTES', self::body($response));
        self::assertSame('image/png', $response->headers->get('content-type'));
        self::assertSame('Accept', $response->headers->get('vary'), 'finding P-1: the variant depends on Accept');
        self::assertStringContainsString('max-age=0', (string) $response->headers->get('cache-control'), 'not published yet');
        self::assertFileExists($this->cachePath('.webp/images/logo.png.webp'));

        $hit = $this->kernel()->handle(self::request('/images/logo.png', 'image/*'));

        self::assertSame('image/png', $hit->headers->get('content-type'));
        self::assertStringNotContainsString('max-age=0', (string) $hit->headers->get('cache-control'), 'no variant is for this client');
    }

    /**
     * Carried item: the request ID (PRX-9) reaches the converter.
     */
    public function testTheRequestIdIsForwarded(): void
    {
        $request = self::request('/images/logo.png', '');
        $request->attributes->set(ProxyKernel::REQUEST_ID_ATTRIBUTE, 'req-42');

        $this->respond($request);

        self::assertSame(['req-42', 'req-42'], array_column($this->converter->getCalls(), 3));
    }

    /**
     * Finding P-10: a conversion that fails serves the original, never a
     * 5xx, and is not tried again on every hit PHP serves.
     */
    public function testAFailedConversionServesTheOriginalAndIsNotRetriedP10(): void
    {
        $this->origin->serve(self::ORIGIN . '/images/broken.png?v=1', 'broken PNG', ['content-type' => 'image/png']);

        $miss = $this->respond(self::request('/images/broken.png?v=1', self::ACCEPT_ALL), ['QUERY_STRING_ALLOWLIST' => 'v']);
        $hit = $this->respond(self::request('/images/broken.png?v=1', self::ACCEPT_ALL), ['QUERY_STRING_ALLOWLIST' => 'v']);

        self::assertSame(200, $miss->getStatusCode());
        self::assertSame('broken PNG', self::body($miss));
        self::assertSame('MISS', $miss->headers->get('x-cache-status'));
        self::assertSame(200, $hit->getStatusCode());
        self::assertSame('HIT', $hit->headers->get('x-cache-status'));
        self::assertStringNotContainsString('max-age=0', (string) $hit->headers->get('cache-control'), 'no variant is coming');
        self::assertSame(['avif', 'webp'], $this->converter->getFormats(), 'one attempt per variant');
        self::assertCount(2, $this->logger->recordsWithMessage('image conversion failed, the original is served'));
    }

    public function testAnUnavailableConverterServesTheOriginal(): void
    {
        $this->converter->setUnavailable(true);

        $response = $this->respond(self::request('/images/logo.png', self::ACCEPT_ALL));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('PNG-BYTES', self::body($response));
        self::assertFileExists($this->cachePath('.pending/images/logo.png'), 'kept from the web server');
        self::assertFileDoesNotExist($this->cachePath('images/logo.png'));
    }

    /**
     * Review N1: a variant the converter could not make now is tried again
     * by a later request, which PHP answers since the entry is pending,
     * once the transient TTL has passed; the entry is then published.
     */
    public function testATransientFailureIsRetriedByALaterHitThenPublished(): void
    {
        $this->converter->setUnavailable(true);
        $this->respond(self::request('/images/logo.png', self::ACCEPT_ALL), ['NETWORK_ERROR_TTL' => '1']);
        $this->converter->setUnavailable(false);

        $soon = $this->respond(self::request('/images/logo.png', self::ACCEPT_ALL), ['NETWORK_ERROR_TTL' => '1']);

        self::assertSame(['HIT', 'image/png'], [$soon->headers->get('x-cache-status'), $soon->headers->get('content-type')]);
        self::assertStringContainsString('max-age=0', (string) $soon->headers->get('cache-control'));
        self::assertFileDoesNotExist($this->cachePath('images/logo.png'), 'still within the transient TTL');

        sleep(2);
        $later = $this->respond(self::request('/images/logo.png', 'image/png'), ['NETWORK_ERROR_TTL' => '1']);
        $avif = $this->kernel()->handle(self::request('/images/logo.png', self::ACCEPT_ALL));

        self::assertSame('image/png', $later->headers->get('content-type'));
        self::assertStringContainsString('max-age=0', (string) $later->headers->get('cache-control'), 'answered before publication');
        self::assertFileExists($this->cachePath('images/logo.png'), 'published');
        self::assertFileDoesNotExist($this->cachePath('.pending/images/logo.png'));
        self::assertFileExists($this->cachePath('.avif/images/logo.png.avif'));
        self::assertFileExists($this->cachePath('.webp/images/logo.png.webp'));
        self::assertSame('image/avif', $avif->headers->get('content-type'));
    }

    /**
     * Review F1 (delta): a pending entry whose AVIF variant the converter
     * cannot make now, while its WEBP one is made, serves that WEBP
     * variant with "max-age=0" only, and keeps it next to the pending
     * entry, where the web server cannot serve it to an AVIF client for
     * CACHE_EXPIRY; both are published together once AVIF is made.
     */
    public function testALesserVariantOfAPendingEntryIsNeverServedForCacheExpiry(): void
    {
        $this->converter->setUnavailableFormats(['avif']);
        $overrides = ['NETWORK_ERROR_TTL' => '1'];
        $this->respond(self::request('/images/logo.png', self::ACCEPT_ALL), $overrides);
        sleep(2);
        $this->respond(self::request('/images/logo.png', self::ACCEPT_ALL), $overrides);

        self::assertFileExists($this->cachePath('.pending/.webp/images/logo.png.webp'));
        self::assertFileDoesNotExist($this->cachePath('.webp/images/logo.png.webp'), 'not where the web server serves it');
        self::assertFileDoesNotExist($this->cachePath('images/logo.png'));

        $webp = $this->respond(self::request('/images/logo.png', self::ACCEPT_ALL), $overrides);

        self::assertSame(['HIT', 'image/webp'], [$webp->headers->get('x-cache-status'), $webp->headers->get('content-type')]);
        self::assertStringEqualsFile(self::FIXTURES . 'variant.webp', self::body($webp));
        self::assertStringContainsString('max-age=0', (string) $webp->headers->get('cache-control'), 'the entry is not published');

        $this->converter->setUnavailableFormats([]);
        sleep(2);
        $this->respond(self::request('/images/logo.png', self::ACCEPT_ALL), $overrides);

        self::assertFileExists($this->cachePath('images/logo.png'), 'published');
        self::assertFileExists($this->cachePath('.avif/images/logo.png.avif'));
        self::assertFileExists($this->cachePath('.webp/images/logo.png.webp'));
        self::assertFileDoesNotExist($this->cachePath('.pending/.webp/images/logo.png.webp'));
        $avif = $this->kernel($overrides)->handle(self::request('/images/logo.png', self::ACCEPT_ALL));
        self::assertSame('image/avif', $avif->headers->get('content-type'));
        self::assertStringNotContainsString('max-age=0', (string) $avif->headers->get('cache-control'));
    }

    /**
     * Review N1: an entry whose variants cannot be made for good (a
     * refusal of the image) is published without them at once; one that
     * only failed transiently is published without them after
     * PENDING_HOLD_SECONDS, so that no entry is kept from the web server
     * for ever.
     */
    public function testAnEntryIsPublishedOnceItsVariantsAreSettledOrItsHoldIsOver(): void
    {
        $this->origin->serve(self::ORIGIN . '/images/broken.png', 'broken PNG', ['content-type' => 'image/png']);
        $this->respond(self::request('/images/broken.png', self::ACCEPT_ALL));

        self::assertFileExists($this->cachePath('images/broken.png'), 'settled by final failures');

        $this->converter->setUnavailable(true);
        $this->respond(self::request('/images/photo.png', self::ACCEPT_ALL));
        self::assertFileExists($this->cachePath('.pending/images/photo.png'));
        touch($this->cachePath('.pending/images/photo.png'), time() - ProxyKernel::PENDING_HOLD_SECONDS);

        $this->respond(self::request('/images/photo.png', self::ACCEPT_ALL));

        self::assertFileExists($this->cachePath('images/photo.png'), 'published once the hold is over');
        self::assertFileDoesNotExist($this->cachePath('.avif/images/photo.png.avif'));
        self::assertCount(1, $this->logger->recordsWithMessage('image entry published without all its variants'));
    }

    public function testABucketHitIsConvertedOnce(): void
    {
        $this->origin->serve(self::ORIGIN . '/images/logo.png?v=2', 'PNG-V2', ['content-type' => 'image/png']);
        TestKernel::create(TestConfig::create(['SOURCE_ORIGIN' => self::ORIGIN, 'QUERY_STRING_ALLOWLIST' => 'v']), $this->directory->path(), $this->origin, null, $this->logger)
            ->handle(self::request('/images/logo.png?v=2', ''));

        $first = $this->respond(self::request('/images/logo.png?v=2', 'image/webp'), ['QUERY_STRING_ALLOWLIST' => 'v']);
        $second = $this->respond(self::request('/images/logo.png?v=2', 'image/webp'), ['QUERY_STRING_ALLOWLIST' => 'v']);

        // The first hit is answered at once; its variants follow it, every
        // enabled one, whatever the client accepted.
        self::assertSame('image/png', $first->headers->get('content-type'));
        self::assertSame('HIT', $first->headers->get('x-cache-status'));
        self::assertStringContainsString('max-age=0', (string) $first->headers->get('cache-control'));
        self::assertSame('image/webp', $second->headers->get('content-type'));
        self::assertSame(['avif', 'webp'], $this->converter->getFormats());
        self::assertCount(1, $this->origin->getRequests());
    }

    /**
     * The conversions of one entry are made by one request at a time: a
     * request that finds them under way when its response is sent (here,
     * a hit that misses the AVIF variant while the request that stored
     * the entry makes it) leaves them to that request.
     */
    public function testTheVariantsOfAnEntryAreMadeByOneRequestAtATime(): void
    {
        $config = TestConfig::create(['SOURCE_ORIGIN' => self::ORIGIN, 'IMAGE_CONVERTER' => 'local']);
        $other = TestKernel::create($config, $this->directory->path(), $this->origin, $this->converter, $this->logger);
        $converter = new class ($this->converter, $other) implements ImageConverter {
            /** @var FakeImageConverter */
            private $converter;

            /** @var ProxyKernel|null */
            private $other;

            public function __construct(FakeImageConverter $converter, ProxyKernel $other)
            {
                $this->converter = $converter;
                $this->other = $other;
            }

            public function convert(string $inputPath, string $outputPath, ConversionRequest $request, ?string $requestId = null): ConversionResult
            {
                if ($this->other !== null) {
                    // The other request ends while this one converts.
                    $other = $this->other;
                    $this->other = null;
                    $other->terminate();
                }

                return $this->converter->convert($inputPath, $outputPath, $request, $requestId);
            }

            public function getCapabilities(): ?Capabilities
            {
                return $this->converter->getCapabilities();
            }

            public function getName(): string
            {
                return $this->converter->getName();
            }
        };
        $storing = TestKernel::create($config, $this->directory->path(), $this->origin, $converter, $this->logger);

        $storing->handle(self::request('/images/logo.png', self::ACCEPT_ALL));
        $hit = $other->handle(self::request('/images/logo.png', self::ACCEPT_ALL));
        $storing->terminate();

        self::assertSame(['HIT', 'image/png'], [$hit->headers->get('x-cache-status'), $hit->headers->get('content-type')]);
        self::assertSame(['avif', 'webp'], $this->converter->getFormats(), 'the hit converted nothing');
        self::assertFileExists($this->cachePath('.avif/images/logo.png.avif'));
    }

    /**
     * Review N1: whichever request wins the lock of the conversions of an
     * entry makes every enabled format of it, here a request whose client
     * accepts WEBP only, which ends before the one that stored the entry;
     * the entry is then published.
     */
    public function testTheRequestThatWinsTheLockMakesEveryFormat(): void
    {
        $storing = $this->kernel();
        $storing->handle(self::request('/images/logo.png', self::ACCEPT_ALL));
        $hitting = $this->kernel();
        $hitting->handle(self::request('/images/logo.png', 'image/webp'));

        $hitting->terminate();

        self::assertSame(['avif', 'webp'], $this->converter->getFormats());
        self::assertFileExists($this->cachePath('.avif/images/logo.png.avif'));
        self::assertFileExists($this->cachePath('images/logo.png'), 'published');
        $storing->terminate();
        self::assertFileExists($this->cachePath('images/logo.png'));
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
        $this->respond(self::request('/images/logo.png', self::ACCEPT_ALL));
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
     * A converter without AVIF encoder (GD alone): the hits PHP serves to
     * the clients that accept AVIF ask it nothing once it said so.
     */
    public function testHitsDoNotAskForAFormatTheConverterCannotMake(): void
    {
        $this->converter->setCapabilities(FakeImageConverter::capabilities(['webp']));
        $this->respond(self::request('/images/logo.png', self::ACCEPT_ALL));
        $asked = $this->converter->getCapabilityCalls();

        for ($hit = 0; $hit < 3; ++$hit) {
            $response = $this->respond(self::request('/images/logo.png', 'image/avif'));
            self::assertSame(['HIT', 'image/png'], [$response->headers->get('x-cache-status'), $response->headers->get('content-type')]);
        }

        self::assertSame($asked, $this->converter->getCapabilityCalls());
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

        $kernel = $this->resizingKernel();
        $miss = $kernel->handle(self::request($path, ''));
        $kernel->terminate();
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
        $kernel = $this->resizingKernel();
        $kernel->handle(self::request($path, ''));
        $kernel->terminate();
        self::assertFileExists($this->cachePath(ltrim($path, '/')));

        $response = $this->resizingKernel()->handle(Request::create('https://cdn.example.test/images/photo.png', 'PURGE', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . self::PURGE_SECRET]));

        self::assertSame(200, $response->getStatusCode());
        self::assertFileDoesNotExist($this->cachePath(ltrim($path, '/')));
        self::assertFileDoesNotExist($this->cachePath('.webp' . $path . '.webp'));
    }

    public function testAnImageThatCannotBeResizedIsStoredAsItIs(): void
    {
        $path = $this->resizePath(640, '/images/logo.png');

        $kernel = $this->resizingKernel();
        $response = $kernel->handle(self::request($path, ''));
        $kernel->terminate();

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

    /**
     * Finding P-24 on the resize path, as on the miss path: a request the
     * coalescer sends to look for an outcome that expires before it reads
     * it enters again and fetches with the lock held, never alongside the
     * next leader.
     */
    public function testAResizeWhoseSharedOutcomeExpiresMeanwhileFetchesWithTheLock(): void
    {
        $this->origin->respond(self::ORIGIN . '/images/photo.png', OriginResponse::failure(OriginResponse::FAILURE_SERVER_ERROR, 500));
        $state = $this->directory->path(TestKernel::STATE_DIRECTORY);
        $origin = new LockProbingOrigin($this->origin, $state);
        $kernel = TestKernel::create(
            TestConfig::create(['SOURCE_ORIGIN' => self::ORIGIN, 'IMAGE_CONVERTER' => 'local', 'RESIZE_WIDTHS' => '320,640', 'RESIZE_KEY' => self::RESIZE_KEY, 'CIRCUIT_BREAKER_THRESHOLD' => '10']),
            $this->directory->path(),
            $origin,
            $this->converter,
            $this->logger
        );
        $path = $this->resizePath(320, '/images/photo.png');
        self::assertSame(503, $kernel->handle(self::request($path, ''))->getStatusCode());

        $looks = 0;
        CoalescerSeam::replace($kernel, $state, $this->logger, static function () use (&$looks): int {
            return ++$looks <= 2 ? time() : time() + RequestCoalescer::OUTCOME_WINDOW;
        });
        self::assertSame(503, $kernel->handle(self::request($path, ''))->getStatusCode());

        self::assertGreaterThan(2, $looks, 'the outcome was looked up again');
        self::assertCount(2, $this->origin->getRequests());
        self::assertSame([false, true], $origin->getLocked(), 'the second fetch holds the lock');
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

    /**
     * The response of a kernel, which then runs the work left for after
     * the response, as the front controller does.
     *
     * @param array<string, string|null> $overrides
     */
    private function respond(Request $request, array $overrides = []): Response
    {
        $kernel = $this->kernel($overrides);
        $response = $kernel->handle($request);
        $kernel->terminate();

        return $response;
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
