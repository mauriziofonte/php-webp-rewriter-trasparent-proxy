<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Functional\Http;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Cache\EntryMetadata;
use Mfonte\ProxyCdn\Cache\RequestCoalescer;
use Mfonte\ProxyCdn\Http\ProxyKernel;
use Mfonte\ProxyCdn\Origin\OriginFetcher;
use Mfonte\ProxyCdn\Origin\OriginResponse;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\CoalescerSeam;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\TestKernel;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\ProxyCdn\Tests\Fixtures\Origin\FakeOriginFetcher;
use Mfonte\ProxyCdn\Tests\Fixtures\Origin\LockProbingOrigin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * What the origin says about caching, honoured by the kernel (finding P-15,
 * opportunity O-D): no-store and private are never stored; a lifetime
 * below CACHE_REVALIDATE_BELOW puts the entry in the tier PHP revalidates,
 * with ETag and Last-Modified, stale-while-revalidate and stale-if-error;
 * Range and conditional requests are answered on the PHP path (finding
 * S-6).
 *
 * @covers \Mfonte\ProxyCdn\Http\ProxyKernel
 *
 * @group regression
 */
#[CoversClass(ProxyKernel::class)]
#[Group('regression')]
final class OriginSemanticsTest extends TestCase
{
    private const ORIGIN = 'https://origin.example.test';

    private const URL = 'https://cdn.example.test/js/app.js';

    private const ORIGIN_URL = self::ORIGIN . '/js/app.js';

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

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->origin = new FakeOriginFetcher();
        $this->logger = new MemoryLogger();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testNoStoreIsStreamedAndNeverStored(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'secret();', ['cache-control' => 'no-store', 'set-cookie' => 'a=b']);

        $first = $this->kernel()->handle(Request::create(self::URL));
        $second = $this->kernel()->handle(Request::create(self::URL));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame('secret();', self::body($first));
        self::assertSame('BYPASS', $first->headers->get('x-cache-status'));
        self::assertSame('no-store, private', $first->headers->get('cache-control'));
        self::assertNull($first->headers->get('set-cookie'));
        self::assertSame('secret();', self::body($second));
        self::assertCount(2, $this->origin->getRequests());
        self::assertSame([], $this->cacheFiles());
    }

    public function testPrivateIsNotStoredAndStaysPrivate(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'mine();', ['cache-control' => 'private, max-age=600']);

        $response = $this->kernel()->handle(Request::create(self::URL));

        self::assertSame('mine();', self::body($response));
        self::assertSame('no-cache, private', $response->headers->get('cache-control'));
        self::assertSame([], $this->cacheFiles());
    }

    public function testHeadOfAnUncachedResponseHasNoBody(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'secret();', ['cache-control' => 'no-store']);

        $response = $this->kernel()->handle(Request::create(self::URL, 'HEAD'));

        self::assertSame('', self::body($response));
        self::assertSame('9', $response->headers->get('content-length'));
    }

    public function testAShortLifetimeGoesToTheTierPhpRevalidates(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'short();', ['cache-control' => 'max-age=60', 'etag' => '"v1"']);

        $miss = $this->kernel()->handle(Request::create(self::URL));
        $hit = $this->kernel()->handle(Request::create(self::URL));

        self::assertSame('MISS', $miss->headers->get('x-cache-status'));
        self::assertSame('HIT', $hit->headers->get('x-cache-status'));
        self::assertSame('short();', self::body($hit));
        self::assertMatchesRegularExpression('/\bmax-age=(60|59)\b/', (string) $hit->headers->get('cache-control'));
        self::assertCount(1, $this->origin->getRequests());
        self::assertFileExists($this->cachePath('.m/js/app.js'));
        self::assertFileDoesNotExist($this->cachePath('js/app.js'), 'the web server never serves it');
        $metadata = $this->metadata();
        self::assertSame(60, $metadata->getLifetime());
        self::assertSame('"v1"', $metadata->getEtag());
    }

    public function testALongOrUnknownLifetimeStaysWhereTheWebServerServesIt(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'long();', ['cache-control' => 'max-age=86400']);
        $this->origin->serve(self::ORIGIN . '/js/other.js', 'unknown();');

        $this->kernel()->handle(Request::create(self::URL));
        $this->kernel()->handle(Request::create('https://cdn.example.test/js/other.js'));

        self::assertFileExists($this->cachePath('js/app.js'));
        self::assertFileExists($this->cachePath('js/other.js'));
        self::assertFileDoesNotExist($this->cachePath('.m/js/app.js'));
    }

    public function testRevalidationCanBeTurnedOff(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'short();', ['cache-control' => 'max-age=60']);

        $this->kernel(['CACHE_REVALIDATE_BELOW' => '0'])->handle(Request::create(self::URL));

        self::assertFileExists($this->cachePath('js/app.js'));
    }

    public function testAnExpiredEntryIsRevalidatedWithItsValidators(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'short();', [
            'cache-control' => 'max-age=60',
            'etag' => '"v1"',
            'last-modified' => 'Tue, 01 Sep 2026 10:00:00 GMT',
        ]);
        $this->kernel()->handle(Request::create(self::URL));
        $this->age(200);
        $this->origin->respond(self::ORIGIN_URL, OriginResponse::notModified(['cache-control' => 'max-age=120']));

        $response = $this->kernel()->handle(Request::create(self::URL));

        self::assertSame('REVALIDATED', $response->headers->get('x-cache-status'));
        self::assertSame('short();', self::body($response));
        $requests = $this->origin->getRequests();
        self::assertCount(2, $requests);
        self::assertSame('"v1"', $requests[1]->getHeaders()['If-None-Match'] ?? null);
        self::assertSame('Tue, 01 Sep 2026 10:00:00 GMT', $requests[1]->getHeaders()['If-Modified-Since'] ?? null);
        self::assertSame(120, $this->metadata()->getLifetime());
        self::assertTrue($this->metadata()->isFresh(time()));
        self::assertSame('HIT', $this->kernel()->handle(Request::create(self::URL))->headers->get('x-cache-status'));
    }

    public function testAnExpiredEntryIsReplacedByANewVersion(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'old();', ['cache-control' => 'max-age=60', 'etag' => '"v1"']);
        $this->kernel()->handle(Request::create(self::URL));
        $this->age(200);
        $this->origin->serve(self::ORIGIN_URL, 'new();', ['cache-control' => 'max-age=60', 'etag' => '"v2"']);

        $response = $this->kernel()->handle(Request::create(self::URL));

        self::assertSame('MISS', $response->headers->get('x-cache-status'));
        self::assertSame('new();', self::body($response));
        self::assertSame('"v2"', $this->metadata()->getEtag());
    }

    public function testStaleWhileRevalidateServesTheEntryAndRevalidatesAfterTheResponse(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'short();', ['cache-control' => 'max-age=60, stale-while-revalidate=300', 'etag' => '"v1"']);
        $kernel = $this->kernel();
        $kernel->handle(Request::create(self::URL));
        $this->age(100);
        $this->origin->respond(self::ORIGIN_URL, OriginResponse::notModified([]));

        $response = $kernel->handle(Request::create(self::URL));

        self::assertSame('STALE', $response->headers->get('x-cache-status'));
        self::assertSame('short();', self::body($response));
        self::assertCount(1, $this->origin->getRequests(), 'not before the response');
        self::assertFalse($this->metadata()->isFresh(time()));

        $kernel->terminate();

        self::assertCount(2, $this->origin->getRequests());
        self::assertSame('"v1"', $this->origin->getRequests()[1]->getHeaders()['If-None-Match'] ?? null);
        self::assertTrue($this->metadata()->isFresh(time()));
        self::assertSame('HIT', $kernel->handle(Request::create(self::URL))->headers->get('x-cache-status'));
    }

    public function testTheDefaultStaleWhileRevalidateAppliesWhenTheOriginSetsNone(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'short();', ['cache-control' => 'max-age=60']);
        $this->kernel(['STALE_WHILE_REVALIDATE' => '30'])->handle(Request::create(self::URL));
        $this->age(80);

        self::assertSame('STALE', $this->kernel(['STALE_WHILE_REVALIDATE' => '30'])->handle(Request::create(self::URL))->headers->get('x-cache-status'));
    }

    public function testAnUnavailableOriginLeavesTheStaleEntryServed(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'short();', ['cache-control' => 'max-age=60']);
        $this->kernel()->handle(Request::create(self::URL));
        $this->age(500);
        $this->origin->respond(self::ORIGIN_URL, OriginResponse::failure(OriginResponse::FAILURE_SERVER_ERROR, 503));

        $response = $this->kernel()->handle(Request::create(self::URL));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('STALE', $response->headers->get('x-cache-status'));
        self::assertSame('short();', self::body($response));
        self::assertStringContainsString('max-age=0', (string) $response->headers->get('cache-control'));
    }

    public function testMustRevalidateNeverServesAStaleEntry(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'short();', ['cache-control' => 'max-age=60, must-revalidate, stale-while-revalidate=600']);
        $this->kernel()->handle(Request::create(self::URL));
        $this->age(100);
        $this->origin->respond(self::ORIGIN_URL, OriginResponse::failure(OriginResponse::FAILURE_SERVER_ERROR, 503));

        $response = $this->kernel()->handle(Request::create(self::URL));

        self::assertSame(503, $response->getStatusCode());
    }

    public function testStaleIfErrorOfTheOriginBoundsTheStaleUse(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'short();', ['cache-control' => 'max-age=60, stale-if-error=100']);
        $this->kernel()->handle(Request::create(self::URL));
        $this->age(200);
        $this->origin->respond(self::ORIGIN_URL, OriginResponse::failure(OriginResponse::FAILURE_TIMEOUT, 0));

        self::assertSame(503, $this->kernel()->handle(Request::create(self::URL))->getStatusCode());
    }

    /**
     * RFC 9111 5.2.2.4: a no-cache response is never used without a
     * successful validation, so the default stale-while-revalidate does
     * not apply to it, and neither does any stale-if-error.
     */
    public function testANoCacheEntryIsValidatedBeforeEachUse(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'fresh();', ['cache-control' => 'no-cache', 'etag' => '"v1"']);
        $this->kernel()->handle(Request::create(self::URL));
        $this->age(5);
        $this->origin->respond(self::ORIGIN_URL, OriginResponse::notModified([]));

        $revalidated = $this->kernel()->handle(Request::create(self::URL));
        self::assertSame('REVALIDATED', $revalidated->headers->get('x-cache-status'));
        self::assertCount(2, $this->origin->getRequests());

        $this->origin->respond(self::ORIGIN_URL, OriginResponse::failure(OriginResponse::FAILURE_SERVER_ERROR, 503));
        self::assertSame(503, $this->kernel()->handle(Request::create(self::URL))->getStatusCode());
    }

    /**
     * RFC 9111 5.2.2.10: s-maxage implies proxy-revalidate, so an expired
     * entry is not served stale when the origin fails.
     */
    public function testSMaxageForbidsServingStale(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'shared();', ['cache-control' => 's-maxage=60']);
        $this->kernel()->handle(Request::create(self::URL));
        $this->age(500);
        $this->origin->respond(self::ORIGIN_URL, OriginResponse::failure(OriginResponse::FAILURE_SERVER_ERROR, 503));

        self::assertSame(503, $this->kernel()->handle(Request::create(self::URL))->getStatusCode());
    }

    /**
     * Without a stale-if-error of the origin, STALE_IF_ERROR bounds how
     * long an expired entry outlives a failing origin.
     */
    public function testStaleIfErrorDefaultsToTheConfiguredBound(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'short();', ['cache-control' => 'max-age=60']);
        $this->kernel(['STALE_IF_ERROR' => '100'])->handle(Request::create(self::URL));
        $this->origin->respond(self::ORIGIN_URL, OriginResponse::failure(OriginResponse::FAILURE_TIMEOUT, 0));

        $this->age(150);
        self::assertSame('STALE', $this->kernel(['STALE_IF_ERROR' => '100'])->handle(Request::create(self::URL))->headers->get('x-cache-status'));
        $this->age(100);
        self::assertSame(503, $this->kernel(['STALE_IF_ERROR' => '100'])->handle(Request::create(self::URL))->getStatusCode());
    }

    /**
     * Finding P-24 on the revalidation path: a request that the coalescer
     * sends to look for an outcome, which expires before the request reads
     * it, enters again and revalidates the expired entry as the leader,
     * with the lock held. The clock of the coalescer moves past the window
     * right after enter() has seen the outcome fresh.
     */
    public function testARevalidationWhoseSharedOutcomeExpiresMeanwhileHoldsTheLock(): void
    {
        $origin = new LockProbingOrigin($this->origin, $this->stateDirectory());
        $this->origin->serve(self::ORIGIN_URL, 'short();', ['cache-control' => 'max-age=60', 'etag' => '"v1"']);
        $this->kernel([], $origin)->handle(Request::create(self::URL));
        $this->age(500);
        $this->origin->respond(self::ORIGIN_URL, OriginResponse::failure(OriginResponse::FAILURE_SERVER_ERROR, 503));
        self::assertSame('STALE', $this->kernel([], $origin)->handle(Request::create(self::URL))->headers->get('x-cache-status'));
        $this->origin->respond(self::ORIGIN_URL, OriginResponse::notModified(['cache-control' => 'max-age=60']));

        // enter() sees the failure fresh; every later look finds it expired.
        $kernel = $this->kernel([], $origin);
        $looks = 0;
        CoalescerSeam::replace($kernel, $this->stateDirectory(), $this->logger, static function () use (&$looks): int {
            return ++$looks <= 1 ? time() : time() + RequestCoalescer::OUTCOME_WINDOW;
        });
        $response = $kernel->handle(Request::create(self::URL));

        self::assertSame('REVALIDATED', $response->headers->get('x-cache-status'));
        self::assertGreaterThan(1, $looks, 'the outcome was looked up again');
        self::assertSame([false, false, true], $origin->getLocked(), 'the revalidation holds the lock');
    }

    /**
     * An expired entry whose metadata is missing is not taken as ready
     * (as it would be once purged): a request waits for the request that
     * holds the lock of the entry, and serves the entry stale when that
     * takes too long, without asking the origin.
     */
    public function testAnEntryWithoutMetadataWaitsForTheLock(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'short();', ['cache-control' => 'max-age=60']);
        $this->kernel()->handle(Request::create(self::URL));
        self::assertTrue(unlink($this->cachePath('.m/.meta/js/app.js.json')));
        $key = (new CacheKeyResolver($this->directory->path('_cache')))->resolve('cdn.example.test', '/js/app.js', '')->getKey();
        $leader = CoalescerSeam::lock($this->stateDirectory())->tryAcquire($key);
        self::assertNotNull($leader);

        $kernel = $this->kernel();
        CoalescerSeam::replace($kernel, $this->stateDirectory(), $this->logger, static function (): int {
            return time();
        }, 0.3);
        $response = $kernel->handle(Request::create(self::URL));
        $leader->release();

        self::assertSame('STALE', $response->headers->get('x-cache-status'));
        self::assertSame('short();', self::body($response));
        self::assertCount(1, $this->origin->getRequests(), 'no revalidation without the lock');
        self::assertContains('request coalescing timed out', array_column($this->logger->getRecords(), 'message'), 'it waited');
    }

    /**
     * A revalidation that never finds what the coalescer saw gives up after
     * ProxyKernel::MAX_ENTRIES entries and serves the entry stale, without
     * asking the origin.
     */
    public function testARevalidationThatNeverFindsWhatTheCoalescerSawServesStale(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'short();', ['cache-control' => 'max-age=60']);
        $this->kernel()->handle(Request::create(self::URL));
        $this->age(500);
        $this->origin->respond(self::ORIGIN_URL, OriginResponse::failure(OriginResponse::FAILURE_SERVER_ERROR, 503));
        self::assertSame('STALE', $this->kernel()->handle(Request::create(self::URL))->headers->get('x-cache-status'));

        // In each entry, enter() sees the failure fresh, and recentFailure(),
        // recentRedirect() and isRecentlyUncacheable() find it expired.
        $kernel = $this->kernel();
        $looks = 0;
        CoalescerSeam::replace($kernel, $this->stateDirectory(), $this->logger, static function () use (&$looks): int {
            return $looks++ % 4 < 1 ? time() : time() + RequestCoalescer::OUTCOME_WINDOW;
        });
        $response = $kernel->handle(Request::create(self::URL));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('STALE', $response->headers->get('x-cache-status'));
        self::assertSame(12, $looks, 'three entries');
        self::assertCount(2, $this->origin->getRequests(), 'no revalidation without the lock');
    }

    public function testAnEntryGoneFromTheOriginIsRemoved(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'short();', ['cache-control' => 'max-age=60']);
        $this->kernel()->handle(Request::create(self::URL));
        $this->age(200);
        $this->origin->respond(self::ORIGIN_URL, OriginResponse::failure(OriginResponse::FAILURE_NOT_FOUND, 404));

        $response = $this->kernel()->handle(Request::create(self::URL));

        self::assertSame(404, $response->getStatusCode());
        self::assertFileDoesNotExist($this->cachePath('.m/js/app.js'));
        self::assertFileDoesNotExist($this->cachePath('.m/.meta/js/app.js.json'));
    }

    public function testAnEntryTheOriginStopsLettingBeStoredIsRemoved(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'short();', ['cache-control' => 'max-age=60']);
        $this->kernel()->handle(Request::create(self::URL));
        $this->age(200);
        $this->origin->serve(self::ORIGIN_URL, 'now private();', ['cache-control' => 'no-store']);

        $response = $this->kernel()->handle(Request::create(self::URL));

        self::assertSame('BYPASS', $response->headers->get('x-cache-status'));
        self::assertSame('now private();', self::body($response));
        self::assertSame([], $this->cacheFiles());
    }

    public function testABackgroundRevalidationDropsAnEntryTheOriginStopsLettingBeStored(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'short();', ['cache-control' => 'max-age=60, stale-while-revalidate=300']);
        $kernel = $this->kernel();
        $kernel->handle(Request::create(self::URL));
        $this->age(100);
        $this->origin->serve(self::ORIGIN_URL, 'now private();', ['cache-control' => 'private']);

        self::assertSame('STALE', $kernel->handle(Request::create(self::URL))->headers->get('x-cache-status'));
        $kernel->terminate();

        self::assertSame([], $this->cacheFiles());
    }

    public function testAnEntryMovesToTheWebServerTierWhenItsLifetimeGrows(): void
    {
        $this->origin->serve(self::ORIGIN_URL, 'short();', ['cache-control' => 'max-age=60']);
        $this->kernel()->handle(Request::create(self::URL));
        $this->age(200);
        $this->origin->serve(self::ORIGIN_URL, 'long();', ['cache-control' => 'max-age=86400']);

        $this->kernel()->handle(Request::create(self::URL));

        self::assertFileExists($this->cachePath('js/app.js'));
        self::assertFileDoesNotExist($this->cachePath('.m/js/app.js'));
    }

    /**
     * Range and conditional requests of a file PHP serves: a single range
     * gets 206, If-None-Match with the current entity tag gets 304.
     */
    public function testRangeAndConditionalRequestsOnThePhpPath(): void
    {
        $this->origin->serve(self::ORIGIN_URL, '0123456789');
        $this->kernel()->handle(Request::create(self::URL));

        $range = $this->kernel()->handle(Request::create(self::URL, 'GET', [], [], [], ['HTTP_RANGE' => 'bytes=2-5']));
        self::assertSame(206, $range->getStatusCode());
        self::assertSame('2345', self::body($range));
        self::assertSame('bytes 2-5/10', $range->headers->get('content-range'));

        $etag = (string) $range->headers->get('etag');
        $conditional = $this->kernel()->handle(Request::create(self::URL, 'GET', [], [], [], ['HTTP_IF_NONE_MATCH' => $etag]));
        self::assertSame(304, $conditional->getStatusCode());
        self::assertSame('', self::body($conditional));
        self::assertCount(1, $this->origin->getRequests());
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private function kernel(array $overrides = [], ?OriginFetcher $origin = null): ProxyKernel
    {
        return TestKernel::create(
            TestConfig::create(['SOURCE_ORIGIN' => self::ORIGIN] + $overrides),
            $this->directory->path(),
            $origin ?? $this->origin,
            null,
            $this->logger
        );
    }

    private function stateDirectory(): string
    {
        return $this->directory->path(TestKernel::STATE_DIRECTORY);
    }

    private function cachePath(string $relativePath): string
    {
        return $this->directory->path('_cache/cdn.example.test/' . $relativePath);
    }

    /**
     * The files below the cache directory.
     *
     * @return list<string>
     */
    private function cacheFiles(): array
    {
        $root = $this->directory->path('_cache');
        if (!is_dir($root)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function metadata(): EntryMetadata
    {
        $metadata = EntryMetadata::fromJson((string) file_get_contents($this->cachePath('.m/.meta/js/app.js.json')));
        self::assertNotNull($metadata);

        return $metadata;
    }

    /**
     * Moves the validation time of the managed entry $seconds into the past.
     */
    private function age(int $seconds): void
    {
        $file = $this->cachePath('.m/.meta/js/app.js.json');
        $data = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($data);
        self::assertIsInt($data['validated_at'] ?? null);
        $data['validated_at'] = $data['validated_at'] - $seconds;
        file_put_contents($file, json_encode($data));
    }

    private static function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
