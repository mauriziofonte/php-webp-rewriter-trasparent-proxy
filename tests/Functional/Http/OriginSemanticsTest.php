<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Functional\Http;

use Mfonte\ProxyCdn\Http\ProxyKernel;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\TestKernel;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\ProxyCdn\Tests\Fixtures\Origin\FakeOriginFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * What the origin says about caching, honoured by the kernel (finding P-15,
 * opportunity O-D): no-store and private are never stored; Range and
 * conditional requests are answered on the PHP path (finding S-6).
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
    private function kernel(array $overrides = []): ProxyKernel
    {
        return TestKernel::create(
            TestConfig::create(['SOURCE_ORIGIN' => self::ORIGIN] + $overrides),
            $this->directory->path(),
            $this->origin,
            null,
            $this->logger
        );
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

    private static function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
