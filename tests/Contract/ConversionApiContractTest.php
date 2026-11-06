<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Contract;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Http\ProxyKernel;
use Mfonte\ProxyCdn\Image\ConversionFailure;
use Mfonte\ProxyCdn\Image\ImageConverter;
use Mfonte\ProxyCdn\Image\ImageConverterFactory;
use Mfonte\ProxyCdn\Image\RemoteConverter;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\TestKernel;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\ProxyCdn\Tests\Fixtures\Origin\FakeOriginFetcher;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Validation\FormatSniffer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The proxy against the real conversion API v2, its hardened image behind
 * nginx over TLS (tests/Contract/run.sh sets the environment; without it
 * every test is skipped):
 *
 *   CONTRACT_API_URL       https://images-api.example.com:<port>
 *   CONTRACT_API_HTTP_URL  the plain HTTP server of nginx, which redirects
 *   CONTRACT_API_KEY       an accepted key
 *   CONTRACT_API_CA_FILE   the certificate of the API
 *   CONTRACT_REQUEST_ID    the request ID the API must log
 *
 * @coversNothing
 *
 * @group contract
 */
#[CoversNothing]
#[Group('contract')]
final class ConversionApiContractTest extends TestCase
{
    private const ORIGIN = 'https://origin.example.test';

    private const PHOTO = __DIR__ . '/../Fixtures/Image/files/photo.png';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var MemoryLogger
     */
    private $logger;

    /**
     * @var FakeOriginFetcher
     */
    private $origin;

    protected function set_up(): void
    {
        parent::set_up();
        if (self::env('CONTRACT_API_URL') === '') {
            self::markTestSkipped('Run by tests/Contract/run.sh against the conversion API');
        }
        $this->directory = new TemporaryDirectory('proxy-cdn-contract-');
        $this->logger = new MemoryLogger();
        $this->origin = new FakeOriginFetcher();
        $this->origin->serve(self::ORIGIN . '/images/photo.png', (string) file_get_contents(self::PHOTO), ['content-type' => 'image/png']);
    }

    protected function tear_down(): void
    {
        if (self::env('CONTRACT_API_URL') !== '') {
            $this->directory->remove();
        }
        parent::tear_down();
    }

    /**
     * Carried item: /healthz with the key gives Capabilities that
     * Capabilities::fromArray() reads; they say nothing of lossless, which
     * the proxy never asks for.
     */
    public function testTheCapabilitiesAreDiscovered(): void
    {
        $capabilities = $this->converter()->getCapabilities();

        self::assertInstanceOf(Capabilities::class, $capabilities);
        self::assertTrue($capabilities->supportsInput('png'));
        self::assertTrue($capabilities->supportsInput('jpeg'));
        self::assertTrue($capabilities->supportsOutput('webp'));
        self::assertTrue($capabilities->supportsOutput('avif'));
        // MAX_IMAGE_BYTES of the API (20 MiB), below the 64 MiB of UPSTREAM_MAX_SIZE.
        self::assertSame(20 * 1048576, $capabilities->getLimit(Capabilities::LIMIT_MAX_BYTES));
    }

    /**
     * The documented request (POST /v2/convert, raw body, parameters in
     * the query string, Bearer key) gives a result that the proxy
     * verifies (finding C-11).
     */
    public function testAPngBecomesAWebpAndAnAvif(): void
    {
        $converter = $this->converter();

        foreach (['webp', 'avif'] as $format) {
            $output = $this->directory->path('photo.' . $format);
            $result = $converter->convert(self::PHOTO, $output, new ConversionRequest($format), self::env('CONTRACT_REQUEST_ID'));

            self::assertSame($format, $result->getOutputFormat());
            self::assertSame([160, 120, 1], [$result->getWidth(), $result->getHeight(), $result->getFrameCount()]);
            self::assertFalse($result->isPassthrough());
            self::assertSame($format, (new FormatSniffer())->detect((string) file_get_contents($output, false, null, 0, FormatSniffer::HEAD_LENGTH)));
            self::assertSame(filesize($output), $result->getByteSize());
        }
    }

    public function testAResizeKeepsTheFormat(): void
    {
        $output = $this->directory->path('resized.png');

        $result = $this->converter()->convert(self::PHOTO, $output, (new ConversionRequest(ConversionRequest::FORMAT_ORIGINAL))->withWidth(80));

        self::assertSame(['png', 80, 60], [$result->getOutputFormat(), $result->getWidth(), $result->getHeight()]);
    }

    /**
     * The whole proxy: a miss of a PNG stores and serves its AVIF and WEBP
     * variants, made by the API and verified.
     */
    public function testTheProxyServesTheVariantsMadeByTheApi(): void
    {
        $request = Request::create('https://cdn.example.test/images/photo.png', 'GET', [], [], [], ['HTTP_ACCEPT' => 'image/avif,image/webp,*/*']);
        $request->attributes->set(ProxyKernel::REQUEST_ID_ATTRIBUTE, self::env('CONTRACT_REQUEST_ID'));

        $response = $this->kernel()->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/avif', $response->headers->get('content-type'));
        self::assertSame('avif', (new FormatSniffer())->detect(self::body($response)));
        self::assertFileExists($this->cachePath('.webp/images/photo.png.webp'));
        self::assertSame([], $this->logger->recordsWithMessage('image conversion failed, the original is served'));
    }

    /**
     * Finding P-10: an image the API refuses is served as the original,
     * never as a 5xx; the error code of the API is logged.
     */
    public function testAnImageTheApiRefusesIsServedAsTheOriginal(): void
    {
        $broken = substr((string) file_get_contents(self::PHOTO), 0, 200);
        $this->origin->serve(self::ORIGIN . '/images/broken.png', $broken, ['content-type' => 'image/png']);

        $response = $this->kernel()->handle(Request::create('https://cdn.example.test/images/broken.png', 'GET', [], [], [], ['HTTP_ACCEPT' => 'image/webp']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($broken, self::body($response));
        $records = $this->logger->recordsWithMessage('image conversion failed, the original is served');
        self::assertNotEmpty($records);
        self::assertFalse($records[0]['context']['transient'] ?? null);
        // A code of the library (invalid_image, conversion_failed), as the API
        // logs it.
        self::assertIsString($records[0]['context']['conversion_error_code'] ?? null);
        self::assertSame(422, $records[0]['context']['status'] ?? null);
    }

    /**
     * Carried item: an image above the max_bytes the API advertises is
     * not sent; the original is served and the refusal logged.
     */
    public function testAnImageAboveTheLimitOfTheApiIsNotSent(): void
    {
        $large = $this->directory->write('large.png', (string) file_get_contents(self::PHOTO) . str_repeat("\0", 20 * 1048576));

        try {
            $this->converter()->convert($large, $this->directory->path('large.webp'), new ConversionRequest('webp'));
            self::fail('The image was sent');
        } catch (ConversionFailure $failure) {
            self::assertFalse($failure->isTransient());
            self::assertSame('file_too_large', $failure->getConversionErrorCode());
        }
    }

    /**
     * A key the API refuses (401) opens the breaker at once; the original
     * is served.
     */
    public function testARefusedKeyServesTheOriginal(): void
    {
        $response = $this->kernel(['IMAGE_API_KEY' => 'not-the-key-0123456789'])->handle(Request::create('https://cdn.example.test/images/photo.png', 'GET', [], [], [], ['HTTP_ACCEPT' => 'image/webp']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('content-type'));
        $records = $this->logger->recordsWithMessage('conversion API capabilities unavailable');
        self::assertSame('invalid_api_key', $records[0]['context']['error_code'] ?? null);
        self::assertSame(401, $records[0]['context']['status'] ?? null);
    }

    /**
     * nginx redirects plain HTTP to HTTPS: the redirect is not followed,
     * so the key never travels in clear text twice, and the original is
     * served.
     */
    public function testThePlainHttpRedirectIsNotFollowed(): void
    {
        $response = $this->kernel(['IMAGE_API_URL' => self::env('CONTRACT_API_HTTP_URL')])->handle(Request::create('https://cdn.example.test/images/photo.png', 'GET', [], [], [], ['HTTP_ACCEPT' => 'image/webp']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('content-type'));
        $records = $this->logger->recordsWithMessage('conversion API capabilities unavailable');
        self::assertSame('redirected', $records[0]['context']['error_code'] ?? null);
    }

    public function testTheCertificateIsVerified(): void
    {
        $converter = ImageConverterFactory::create($this->config(['IMAGE_API_CA_FILE' => null]), $this->directory->path('var'), $this->logger);

        self::assertNotNull($converter);
        self::assertNull($converter->getCapabilities());
        $records = $this->logger->recordsWithMessage('conversion API capabilities unavailable');
        self::assertSame('unreachable', $records[0]['context']['error_code'] ?? null);
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private function config(array $overrides = []): Config
    {
        return TestConfig::create($overrides + [
            'SOURCE_ORIGIN' => self::ORIGIN,
            'IMAGE_CONVERTER' => 'remote',
            'IMAGE_API_URL' => self::env('CONTRACT_API_URL'),
            'IMAGE_API_KEY' => self::env('CONTRACT_API_KEY'),
            'IMAGE_API_CA_FILE' => self::env('CONTRACT_API_CA_FILE'),
        ]);
    }

    private function converter(): ImageConverter
    {
        $converter = ImageConverterFactory::create($this->config(), $this->directory->path('var'), $this->logger, new FilesystemStorage());
        self::assertInstanceOf(RemoteConverter::class, $converter);

        return $converter;
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private function kernel(array $overrides = []): ProxyKernel
    {
        $config = $this->config($overrides);
        $directory = $this->directory->path('public');
        $converter = ImageConverterFactory::create($config, $directory . '/' . TestKernel::STATE_DIRECTORY, $this->logger);

        return TestKernel::create($config, $directory, $this->origin, $converter, $this->logger);
    }

    private function cachePath(string $relativePath): string
    {
        return $this->directory->path('public/' . CacheKeyResolver::CACHE_DIRECTORY . '/cdn.example.test/' . $relativePath);
    }

    private static function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? $value : '';
    }

    private static function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
