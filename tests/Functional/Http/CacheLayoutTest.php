<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Functional\Http;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Http\ProxyKernel;
use Mfonte\ProxyCdn\Origin\OriginResponse;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\CoalescerSeam;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\TestKernel;
use Mfonte\ProxyCdn\Tests\Fixtures\Image\FakeImageConverter;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\ProxyCdn\Tests\Fixtures\Origin\FakeOriginFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The cache layout and the hit path of PRX-6, through ProxyKernel: hosts,
 * format variants, encodings and the legacy WEBP URLs of 2.x.
 *
 * @covers \Mfonte\ProxyCdn\Http\ProxyKernel
 */
#[CoversClass(ProxyKernel::class)]
final class CacheLayoutTest extends TestCase
{
    private const ORIGIN = 'https://origin.example.test';

    private const OTHER_ORIGIN = 'https://other.example.test';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var FakeOriginFetcher
     */
    private $origin;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->origin = new FakeOriginFetcher();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    /**
     * A host outside PROXY_HOSTS gets 421 and creates nothing (risk L-13:
     * a random Host header would otherwise create a cache directory).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testAnUnknownHostIsMisdirectedL13(): void
    {
        $this->origin->serve(self::ORIGIN . '/a.css', 'a');

        foreach (['GET', 'HEAD', 'OPTIONS', 'PURGE'] as $method) {
            $response = $this->kernel()->handle(Request::create('https://random-' . $method . '.example.test/a.css', $method));
            self::assertSame(421, $response->getStatusCode(), $method);
        }
        self::assertSame([], $this->origin->getRequests());
        self::assertDirectoryDoesNotExist($this->directory->path('_cache'));
    }

    /**
     * Each host has its own directory and origin (opportunity O-J).
     */
    public function testEachHostHasItsOriginAndDirectory(): void
    {
        $this->origin->serve(self::ORIGIN . '/a.css', 'first site');
        $this->origin->serve(self::OTHER_ORIGIN . '/a.css', 'second site');
        $kernel = $this->kernel(['PROXY_HOSTS' => 'cdn.example.test,static.other.test=' . self::OTHER_ORIGIN]);

        $first = $kernel->handle(Request::create('https://cdn.example.test/a.css'));
        $second = $kernel->handle(Request::create('https://STATIC.other.test:8443/a.css'));

        self::assertSame('first site', self::body($first));
        self::assertSame('second site', self::body($second));
        self::assertStringEqualsFile($this->directory->path('_cache/cdn.example.test/a.css'), 'first site');
        self::assertStringEqualsFile($this->directory->path('_cache/static.other.test/a.css'), 'second site');
    }

    /**
     * Finding P-1: the variant is chosen by Accept, with "Vary: Accept",
     * and never by a redirect.
     */
    public function testTheVariantIsNegotiatedOnAcceptP1(): void
    {
        $this->write('images/a.png', 'PNG');
        $this->write('.webp/images/a.png.webp', 'WEBP');
        $this->write('.avif/images/a.png.avif', 'AVIF');

        $cases = [
            'image/avif,image/webp,*/*' => ['AVIF', 'image/avif'],
            'image/avif;q=0,image/webp' => ['WEBP', 'image/webp'],
            'image/webp,*/*' => ['WEBP', 'image/webp'],
            '*/*' => ['PNG', 'image/png'],
            '' => ['PNG', 'image/png'],
        ];
        foreach ($cases as $accept => $expected) {
            $response = $this->kernel()->handle(Request::create('https://cdn.example.test/images/a.png', 'GET', [], [], [], ['HTTP_ACCEPT' => $accept]));
            self::assertSame(200, $response->getStatusCode(), $accept);
            self::assertSame($expected[0], self::body($response), $accept);
            self::assertSame($expected[1], $response->headers->get('content-type'), $accept);
            self::assertSame('Accept', $response->headers->get('vary'), $accept);
            self::assertFalse($response->headers->has('location'));
        }
    }

    /**
     * Compressible entries get gzip siblings, which the clients that accept
     * them receive (finding S-6).
     */
    public function testAStylesheetIsStoredWithItsGzipSibling(): void
    {
        if (!function_exists('gzencode')) {
            self::markTestSkipped('ext-zlib is not loaded');
        }
        $css = str_repeat("a { color: red; }\n", 50);
        $this->origin->serve(self::ORIGIN . '/css/a.css', $css);

        $miss = $this->kernel()->handle(Request::create('https://cdn.example.test/css/a.css', 'GET', [], [], [], ['HTTP_ACCEPT_ENCODING' => 'gzip']));
        $plain = $this->kernel()->handle(Request::create('https://cdn.example.test/css/a.css'));

        self::assertSame('gzip', $miss->headers->get('content-encoding'));
        self::assertSame($css, gzdecode(self::body($miss)));
        self::assertSame('Accept-Encoding', $miss->headers->get('vary'));
        self::assertSame($css, self::body($plain));
        self::assertFileExists($this->directory->path('_cache/cdn.example.test/.gz/css/a.css'));
    }

    public function testPrecompressionCanBeTurnedOff(): void
    {
        $this->origin->serve(self::ORIGIN . '/css/a.css', str_repeat("a { color: red; }\n", 50));

        $this->kernel(['PRECOMPRESS' => 'false'])->handle(Request::create('https://cdn.example.test/css/a.css'));

        self::assertDirectoryDoesNotExist($this->directory->path('_cache/cdn.example.test/.gz'));
    }

    /**
     * A new version of an entry replaces the siblings of the old one.
     */
    public function testANewVersionDropsTheOldSiblings(): void
    {
        $this->write('.gz/css/a.css', 'old gzip');
        $this->write('.webp/css/a.css.webp', 'stale');
        $this->origin->serve(self::ORIGIN . '/css/a.css', 'new');

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/css/a.css', 'GET', [], [], [], ['HTTP_ACCEPT_ENCODING' => 'gzip']));

        self::assertSame('new', self::body($response));
        self::assertFileDoesNotExist($this->directory->path('_cache/cdn.example.test/.gz/css/a.css'));
        self::assertFileDoesNotExist($this->directory->path('_cache/cdn.example.test/.webp/css/a.css.webp'));
    }

    /**
     * Risk L-4: "X.webp" from a 301 of 2.x is answered from the cached
     * raster sibling, as its WEBP variant when there is one.
     *
     * @group regression
     */
    #[Group('regression')]
    public function testALegacyWebpUrlIsAnsweredFromTheCachedSiblingL4(): void
    {
        $this->write('images/a.jpg', 'JPEG');
        $this->write('images/b.png', 'PNG');
        $this->write('.webp/images/b.png.webp', 'WEBP-OF-B');

        $a = $this->kernel()->handle(Request::create('https://cdn.example.test/images/a.webp'));
        $b = $this->kernel()->handle(Request::create('https://cdn.example.test/images/b.webp'));

        self::assertSame('JPEG', self::body($a));
        self::assertSame('image/jpeg', $a->headers->get('content-type'));
        self::assertSame('WEBP-OF-B', self::body($b));
        self::assertSame('image/webp', $b->headers->get('content-type'));
        self::assertSame('HIT', $b->headers->get('x-cache-status'));
        self::assertSame([], $this->origin->getRequests());
    }

    /**
     * Risk L-4: when the origin has no "X.webp", its raster siblings are
     * fetched, stored under their own URL and converted.
     *
     * @group regression
     */
    #[Group('regression')]
    public function testALegacyWebpUrlFetchesAndConvertsTheSiblingL4(): void
    {
        $this->origin->serve(self::ORIGIN . '/images/a.png', 'PNG');
        $converter = new FakeImageConverter();
        $webp = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Image/files/variant.webp');

        $first = $this->kernel(['IMAGE_CONVERTER' => 'local'], $converter)->handle(Request::create('https://cdn.example.test/images/a.webp'));
        $second = $this->kernel(['IMAGE_CONVERTER' => 'local'], $converter)->handle(Request::create('https://cdn.example.test/images/a.webp'));

        self::assertSame($webp, self::body($first));
        self::assertSame('image/webp', $first->headers->get('content-type'));
        self::assertSame('MISS', $first->headers->get('x-cache-status'));
        self::assertSame($webp, self::body($second));
        self::assertSame('HIT', $second->headers->get('x-cache-status'));
        self::assertSame([
            self::ORIGIN . '/images/a.webp',
            self::ORIGIN . '/images/a.jpg',
            self::ORIGIN . '/images/a.jpeg',
            self::ORIGIN . '/images/a.png',
        ], $this->origin->getRequestedUrls());
        self::assertFileExists($this->directory->path('_cache/cdn.example.test/images/a.png'));
        self::assertFileDoesNotExist($this->directory->path('_cache/cdn.example.test/images/a.webp'));
    }

    public function testALegacyWebpUrlWithoutSiblingIsA404(): void
    {
        $first = $this->kernel()->handle(Request::create('https://cdn.example.test/images/none.webp'));
        $count = count($this->origin->getRequests());
        $second = $this->kernel()->handle(Request::create('https://cdn.example.test/images/none.webp'));

        self::assertSame(404, $first->getStatusCode());
        self::assertSame(404, $second->getStatusCode());
        self::assertSame(6, $count, 'the URL and its five raster siblings');
        self::assertCount(6, $this->origin->getRequests(), 'remembered by the negative cache');
    }

    /**
     * The negative cache keeps one entry per slot (the first three hex
     * digits of the SHA-256 of the cache file). When the entries of two
     * siblings share a slot, each evicts the other: before the URL was
     * remembered on its own, every request fetched both again (the
     * failure that a random test directory produced about once in 270
     * runs). The directory is chosen so that two of them collide.
     */
    public function testALegacyWebpUrlWithoutSiblingIsRememberedWhenSiblingsShareASlot(): void
    {
        $public = $this->collidingPublicDirectory();
        $kernel = TestKernel::create(TestConfig::create(), $public, $this->origin, null, new MemoryLogger());

        $first = $kernel->handle(Request::create('https://cdn.example.test/images/none.webp'));
        $count = count($this->origin->getRequests());
        $second = $kernel->handle(Request::create('https://cdn.example.test/images/none.webp'));

        self::assertSame(404, $first->getStatusCode());
        self::assertSame(404, $second->getStatusCode());
        self::assertSame(6, $count, 'the URL and its five raster siblings');
        self::assertCount(5, (array) glob($public . '/' . TestKernel::STATE_DIRECTORY . '/negative/*.neg'), 'six entries in five slots');
        self::assertCount(6, $this->origin->getRequests(), 'remembered by the negative cache');
    }

    public function testAnExistingWebpIsNeverReplacedByASibling(): void
    {
        $this->origin->serve(self::ORIGIN . '/images/a.webp', 'REAL-WEBP');
        $this->write('images/a.png', 'PNG');

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/images/a.webp'));

        self::assertSame('PNG', self::body($response), 'the cached sibling answers first');
        $this->kernel(['LEGACY_WEBP_URLS' => 'false'])->handle(Request::create('https://cdn.example.test/images/a.webp'));
        self::assertSame([self::ORIGIN . '/images/a.webp'], $this->origin->getRequestedUrls());
    }

    public function testLegacyResolutionCanBeTurnedOff(): void
    {
        $this->write('images/a.png', 'PNG');

        $response = $this->kernel(['LEGACY_WEBP_URLS' => 'false'])->handle(Request::create('https://cdn.example.test/images/a.webp'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([self::ORIGIN . '/images/a.webp'], $this->origin->getRequestedUrls());
    }

    public function testALegacySiblingFailureIsAnsweredByItsKind(): void
    {
        $this->origin->respond(self::ORIGIN . '/images/a.jpg', OriginResponse::failure(OriginResponse::FAILURE_SERVER_ERROR, 500));

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/images/a.webp'));

        self::assertSame(503, $response->getStatusCode());
    }

    /**
     * The sibling of a legacy URL goes where its lifetime puts it, as any
     * fetched asset: a short one in the tier PHP revalidates, served with
     * the max-age it has left, and fetched again once expired.
     */
    public function testALegacySiblingWithAShortLifetimeIsManaged(): void
    {
        $this->origin->serve(self::ORIGIN . '/images/a.png', 'PNG', ['cache-control' => 'max-age=60']);

        $first = $this->kernel()->handle(Request::create('https://cdn.example.test/images/a.webp'));
        $second = $this->kernel()->handle(Request::create('https://cdn.example.test/images/a.webp'));

        self::assertSame('MISS', $first->headers->get('x-cache-status'));
        self::assertSame('HIT', $second->headers->get('x-cache-status'));
        self::assertSame('PNG', self::body($second));
        self::assertMatchesRegularExpression('/\bmax-age=(60|59)\b/', (string) $second->headers->get('cache-control'));
        self::assertFileExists($this->directory->path('_cache/cdn.example.test/.m/images/a.png'));
        self::assertFileDoesNotExist($this->directory->path('_cache/cdn.example.test/images/a.png'), 'never served by the web server');
        $fetches = count($this->origin->getRequests());

        $metadata = $this->directory->path('_cache/cdn.example.test/.m/.meta/images/a.png.json');
        $data = json_decode((string) file_get_contents($metadata), true);
        self::assertIsArray($data);
        self::assertIsInt($data['validated_at'] ?? null);
        $data['validated_at'] -= 3600;
        file_put_contents($metadata, json_encode($data));

        $third = $this->kernel()->handle(Request::create('https://cdn.example.test/images/a.webp'));
        self::assertSame('MISS', $third->headers->get('x-cache-status'), 'expired: fetched again');
        self::assertGreaterThan($fetches, count($this->origin->getRequests()));
    }

    /**
     * Finding P-24: while a request leads the fetch of "X.webp", the
     * others wait for it; an expired sibling in the tier PHP revalidates
     * is not ready for them (it is fetched again, serveLegacyHit() does not
     * serve it), so they keep waiting instead of waking at once to nothing
     * they can serve. Here the leader is the test, which holds the lock
     * longer than the wait: the request answers 503 once its wait as a
     * waiter is over, without having woken to nothing and gone on to wait
     * to lead (RequestCoalescer::lead(), which logs "leading").
     */
    public function testAnExpiredLegacySiblingIsNotReadyForTheWaiters(): void
    {
        $this->origin->serve(self::ORIGIN . '/images/a.png', 'PNG', ['cache-control' => 'max-age=60']);
        self::assertSame('MISS', $this->kernel()->handle(Request::create('https://cdn.example.test/images/a.webp'))->headers->get('x-cache-status'));
        $metadata = $this->directory->path('_cache/cdn.example.test/.m/.meta/images/a.png.json');
        $data = json_decode((string) file_get_contents($metadata), true);
        self::assertIsArray($data);
        self::assertIsInt($data['validated_at'] ?? null);
        $data['validated_at'] -= 3600;
        file_put_contents($metadata, json_encode($data));
        $fetches = count($this->origin->getRequests());

        $state = $this->directory->path(TestKernel::STATE_DIRECTORY);
        $key = (new CacheKeyResolver($this->directory->path('_cache')))->resolve('cdn.example.test', '/images/a.webp', '')->getKey();
        $leader = CoalescerSeam::lock($state)->tryAcquire($key);
        self::assertNotNull($leader);
        $logger = new MemoryLogger();
        $kernel = $this->kernel();
        CoalescerSeam::replace($kernel, $state, $logger, static function (): int {
            return time();
        }, 0.3);
        $response = $kernel->handle(Request::create('https://cdn.example.test/images/a.webp'));
        $leader->release();

        self::assertSame(503, $response->getStatusCode());
        self::assertCount($fetches, $this->origin->getRequests());
        $records = $logger->getRecords();
        self::assertCount(1, $records);
        self::assertSame('request coalescing timed out', $records[0]['message']);
        self::assertArrayNotHasKey('leading', $records[0]['context'], 'it timed out as a waiter, and never went on to lead');
    }

    /**
     * A sibling the origin forbids storing is streamed, never stored
     * (finding P-15).
     */
    public function testANoStoreLegacySiblingIsNeverStored(): void
    {
        $this->origin->serve(self::ORIGIN . '/images/a.png', 'PNG', ['cache-control' => 'no-store']);

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/images/a.webp'));

        self::assertSame('PNG', self::body($response));
        self::assertSame('BYPASS', $response->headers->get('x-cache-status'));
        self::assertSame('no-store, private', $response->headers->get('cache-control'));
        self::assertFileDoesNotExist($this->directory->path('_cache/cdn.example.test/images/a.png'));
        self::assertFileDoesNotExist($this->directory->path('_cache/cdn.example.test/.m/images/a.png'));
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private function kernel(array $overrides = [], ?FakeImageConverter $converter = null): ProxyKernel
    {
        return TestKernel::create(TestConfig::create($overrides), $this->directory->path(), $this->origin, $converter, new MemoryLogger());
    }

    /**
     * A directory of the test directory, to serve as the public directory,
     * where the cache files of two raster siblings of "/images/none.webp"
     * share a slot of the negative cache, and no other entry does.
     */
    private function collidingPublicDirectory(): string
    {
        for ($attempt = 0; $attempt < 100000; ++$attempt) {
            $public = $this->directory->path('public-' . $attempt);
            $slots = [];
            foreach (['webp', 'jpg', 'jpeg', 'png', 'gif', 'bmp'] as $extension) {
                $slots[] = substr(hash('sha256', $public . '/_cache/cdn.example.test/images/none.' . $extension), 0, 3);
            }
            if (count(array_unique($slots)) === 5 && array_search($slots[0], array_slice($slots, 1), true) === false) {
                mkdir($public);

                return $public;
            }
        }
        self::fail('no colliding directory found');
    }

    private function write(string $relativePath, string $contents): void
    {
        $this->directory->write('_cache/cdn.example.test/' . $relativePath, $contents);
    }

    private static function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
