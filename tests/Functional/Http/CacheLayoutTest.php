<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Functional\Http;

use Mfonte\ProxyCdn\Http\ProxyKernel;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\TestKernel;
use Mfonte\ProxyCdn\Tests\Fixtures\Image\FakeWebpConverter;
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
     * A new version of an entry replaces the siblings of the old one.
     */
    public function testANewVersionDropsTheOldSiblings(): void
    {
        $this->write('.webp/css/a.css.webp', 'stale');
        $this->origin->serve(self::ORIGIN . '/css/a.css', 'new');

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/css/a.css'));

        self::assertSame('new', self::body($response));
        self::assertFileDoesNotExist($this->directory->path('_cache/cdn.example.test/.webp/css/a.css.webp'));
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private function kernel(array $overrides = [], ?FakeWebpConverter $converter = null): ProxyKernel
    {
        return TestKernel::create(TestConfig::create($overrides), $this->directory->path(), $this->origin, $converter, new MemoryLogger());
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
