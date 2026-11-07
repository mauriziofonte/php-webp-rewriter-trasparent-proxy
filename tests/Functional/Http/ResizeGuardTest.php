<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Functional\Http;

use Mfonte\ProxyCdn\Http\OriginFetchGuard;
use Mfonte\ProxyCdn\Http\ProxyKernel;
use Mfonte\ProxyCdn\Http\ProxyKernelFactory;
use Mfonte\ProxyCdn\Image\SignedUrlVerifier;
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
 * The OriginFetchGuard of PRX-9 (the miss rate limit, Early Hints) on the
 * signed resize URLs of PRX-8: a request that fetches the image passes
 * it, as a miss does, with the signature of its path masked.
 *
 * @covers \Mfonte\ProxyCdn\Http\ProxyKernel
 */
#[CoversClass(ProxyKernel::class)]
final class ResizeGuardTest extends TestCase
{
    private const ORIGIN = 'https://origin.example.test';

    private const RESIZE_KEY = 'resize-key-0123456789abcdef0123456789';

    private const IMAGE = __DIR__ . '/../../Fixtures/Image/files/image.png';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var FakeOriginFetcher
     */
    private $origin;

    /**
     * @var FakeImageConverter
     */
    private $converter;

    /**
     * The paths the guard was asked about, in order.
     *
     * @var list<string>
     */
    private $guarded = [];

    /**
     * @var Response|null
     */
    private $refusal;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->origin = new FakeOriginFetcher();
        $this->converter = new FakeImageConverter();
        $this->origin->serve(self::ORIGIN . '/images/photo.png', (string) file_get_contents(self::IMAGE), ['content-type' => 'image/png']);
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testAResizeMissPassesTheGuardWithItsSignatureMasked(): void
    {
        $path = $this->resizePath();

        $response = $this->kernel()->handle(self::request($path));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([SignedUrlVerifier::mask($path)], $this->guarded);
        self::assertSame(1, preg_match('#/_resize/[0-9]+/([0-9A-Fa-f]+)/#', $path, $signature));
        self::assertStringNotContainsString($signature[1], $this->guarded[0], 'no signature in the guard, nor in its logs');
        self::assertSame('HIT', $this->kernel()->handle(self::request($path))->headers->get('x-cache-status'));
        self::assertCount(1, $this->guarded(), 'a hit never reaches the guard');
    }

    public function testARefusedResizeFetchesNothing(): void
    {
        $this->refusal = new Response('', 429, ['Retry-After' => '5']);

        $response = $this->kernel()->handle(self::request($this->resizePath()));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame([], $this->origin->getRequestedUrls());
        self::assertSame([], $this->converter->getCalls());
    }

    /**
     * Within the window of a resize the converter could not make, the
     * requests are not coalesced: each fetches the image and streams it,
     * so each passes the guard and counts against the miss rate limit of
     * its client, like a miss of a response the origin forbids storing.
     */
    public function testEachFetchOfAnUnavailableResizeWindowPassesTheGuard(): void
    {
        $this->converter->setUnavailable(true);
        $path = $this->resizePath();

        for ($i = 0; $i < 3; ++$i) {
            $response = $this->kernel()->handle(self::request($path));
            self::assertSame('BYPASS', $response->headers->get('x-cache-status'));
        }

        self::assertCount(3, $this->origin->getRequestedUrls());
        self::assertCount(3, $this->guarded);
    }

    private function kernel(): ProxyKernel
    {
        $guard = new class ($this) implements OriginFetchGuard {
            /**
             * @var ResizeGuardTest
             */
            private $test;

            public function __construct(ResizeGuardTest $test)
            {
                $this->test = $test;
            }

            public function beforeFetch(Request $request, string $path): ?Response
            {
                return $this->test->guard($path);
            }
        };
        $config = TestConfig::create(['SOURCE_ORIGIN' => self::ORIGIN, 'IMAGE_CONVERTER' => 'local', 'RESIZE_WIDTHS' => '320,640', 'RESIZE_KEY' => self::RESIZE_KEY]);
        $cache = $this->directory->path();

        return ProxyKernelFactory::create($config, $cache, $cache . '/' . TestKernel::STATE_DIRECTORY, $this->origin, $this->converter, new MemoryLogger(), $guard);
    }

    /**
     * The guard of the kernels: records $path, answers the refusal if any.
     *
     * @internal
     */
    public function guard(string $path): ?Response
    {
        $this->guarded[] = $path;

        return $this->refusal;
    }

    /**
     * @return list<string>
     */
    private function guarded(): array
    {
        return $this->guarded;
    }

    private function resizePath(): string
    {
        return (new SignedUrlVerifier(self::RESIZE_KEY, [320, 640]))->pathOf('cdn.example.test', 320, '/images/photo.png', '');
    }

    private static function request(string $path): Request
    {
        return Request::create('https://cdn.example.test' . $path);
    }
}
