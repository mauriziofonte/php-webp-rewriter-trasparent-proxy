<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Http;

use Mfonte\ProxyCdn\Http\ResponseFactory;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The responses of 2.x, byte for byte.
 *
 * @covers \Mfonte\ProxyCdn\Http\ResponseFactory
 */
#[CoversClass(ResponseFactory::class)]
final class ResponseFactoryTest extends TestCase
{
    /**
     * A valid preflight answer: the "*" origin without credentials, the
     * methods the proxy answers (finding P-19).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testPreflightP19(): void
    {
        $response = (new ResponseFactory(600, 30))->preflight('');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
        self::assertSame('GET, HEAD, OPTIONS', $response->headers->get('Access-Control-Allow-Methods'));
        self::assertSame('GET, HEAD, OPTIONS', $response->headers->get('Allow'));
        self::assertFalse($response->headers->has('Access-Control-Allow-Headers'));
        self::assertSame('600', $response->headers->get('Access-Control-Max-Age'));
        self::assertSame('0', $response->headers->get('Content-Length'));
    }

    public function testThePreflightMaxAgeIsCapped(): void
    {
        self::assertSame('86400', (new ResponseFactory(31536000, 30))->preflight('')->headers->get('Access-Control-Max-Age'));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function provideRequestedHeaders(): array
    {
        return [
            'one' => ['Range', 'range'],
            'several, spaced' => [' If-None-Match ,X-Custom', 'if-none-match, x-custom'],
            'empty entries' => [',range,', 'range'],
            'not a field name' => ['range, bad header', null],
            'injection' => ["range\r\nSet-Cookie: x", null],
        ];
    }

    /**
     * @dataProvider provideRequestedHeaders
     */
    #[DataProvider('provideRequestedHeaders')]
    public function testThePreflightAllowsTheRequestedHeaders(string $requested, ?string $allowed): void
    {
        $response = (new ResponseFactory(600, 30))->preflight($requested);

        self::assertSame($allowed, $response->headers->get('Access-Control-Allow-Headers'));
        self::assertSame('Access-Control-Request-Headers', $response->headers->get('Vary'));
    }

    public function testMethodNotAllowed(): void
    {
        $response = (new ResponseFactory(600, 30))->error('/a.css', ResponseFactory::ERROR_METHOD_NOT_ALLOWED);

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD, OPTIONS', $response->headers->get('Allow'));
        self::assertSame("The request could not be processed.\nError code: Method not allowed.\nRay ID: " . md5('/a.css'), $response->getContent());
    }

    /**
     * The failures of the proxy are 5xx, the refused requests 4xx (finding
     * P-21: 2.x answered 404 for every error but an unavailable origin).
     *
     * @dataProvider provideChangedErrors
     *
     * @group regression
     */
    #[DataProvider('provideChangedErrors')]
    #[Group('regression')]
    public function testErrorP21(int $error, int $status, string $code): void
    {
        self::assertError($error, $status, $code);
    }

    /**
     * @return array<string, array{int, int, string}>
     */
    public static function provideChangedErrors(): array
    {
        return [
            'refused' => [ResponseFactory::ERROR_REFUSED, 400, 'Cannot serve this request'],
            'cache directory' => [ResponseFactory::ERROR_CACHE_DIRECTORY, 500, 'Cannot create cache directory'],
            'cache file' => [ResponseFactory::ERROR_CACHE_FILE, 500, 'Cannot create cache file'],
            'configuration' => [ResponseFactory::ERROR_CONFIGURATION, 503, 'ENV_FILE_LOAD_ERR'],
            'unknown' => [42, 500, 'Unknown error'],
        ];
    }

    /**
     * The errors whose status 2.x already got right.
     *
     * @dataProvider provideUnchangedErrors
     */
    #[DataProvider('provideUnchangedErrors')]
    public function testErrorKeepsTheStatusOf2x(int $error, int $status, string $code): void
    {
        self::assertError($error, $status, $code);
    }

    /**
     * @return array<string, array{int, int, string}>
     */
    public static function provideUnchangedErrors(): array
    {
        return [
            'origin not found' => [ResponseFactory::ERROR_ORIGIN_NOT_FOUND, 404, 'Cannot read remote origin file'],
            'not cacheable' => [ResponseFactory::ERROR_NOT_CACHEABLE, 404, 'Request cannot be processed'],
            'upstream unavailable' => [ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE, 503, 'Upstream temporarily unavailable'],
        ];
    }

    /**
     * A host outside PROXY_HOSTS is misdirected (risk L-13).
     */
    public function testUnknownHost(): void
    {
        self::assertError(ResponseFactory::ERROR_UNKNOWN_HOST, 421, 'Unknown host');
    }

    public function testWithoutPathTheRayIdIsRandom(): void
    {
        $first = (string) (new ResponseFactory(600, 30))->error(null, ResponseFactory::ERROR_REFUSED)->getContent();
        $second = (string) (new ResponseFactory(600, 30))->error(null, ResponseFactory::ERROR_REFUSED)->getContent();

        self::assertMatchesRegularExpression('/\nRay ID: [0-9a-f]{32}\z/', $first);
        self::assertNotSame($first, $second);
    }

    /**
     * The answer while the configuration is invalid is a 503 (finding P-21).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testConfigurationErrorP21(): void
    {
        $response = ResponseFactory::configurationError();

        self::assertSame(503, $response->getStatusCode());
        self::assertMatchesRegularExpression(
            '/\AThe request could not be processed.\nError code: ENV_FILE_LOAD_ERR.\nRay ID: [0-9a-f]{32}\z/',
            (string) $response->getContent()
        );
        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
    }

    public function testInternalError(): void
    {
        $response = (new ResponseFactory(600, 30))->internalError('Cannot read cached file from disk');

        self::assertSame(500, $response->getStatusCode());
        self::assertSame("The request could not be processed.\nInternal server error: Cannot read cached file from disk.", $response->getContent());
        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function testUnexpectedError(): void
    {
        $response = ResponseFactory::unexpectedError('Unexpected error');

        self::assertSame(500, $response->getStatusCode());
        self::assertSame("The request could not be processed.\nInternal server error: Unexpected error.", $response->getContent());
    }

    /**
     * The headers of an asset, which the web server configurations send on
     * a hit too: the type of the extension (finding P-23), X-Cache-Status
     * (finding P-18), CORS, CORP and Timing-Allow-Origin (opportunity O-G).
     */
    public function testAssetHeaders(): void
    {
        $headers = (new ResponseFactory(600, 30))->assetHeaders('css', ResponseFactory::CACHE_MISS);

        self::assertSame([
            'Content-Type' => 'text/css',
            'Cache-Control' => 'public, max-age=600, must-revalidate',
            'X-Cache-Status' => 'MISS',
            'Access-Control-Allow-Origin' => '*',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Timing-Allow-Origin' => '*',
            'X-Content-Type-Options' => 'nosniff',
        ], $headers);
    }

    /**
     * An SVG gets a Content-Security-Policy that sandboxes it and forbids
     * scripts (finding P-7).
     */
    public function testSvgAssetsAreSandboxedP7(): void
    {
        $headers = (new ResponseFactory(600, 30))->assetHeaders('svg', ResponseFactory::CACHE_HIT);

        self::assertSame(ResponseFactory::SVG_CONTENT_SECURITY_POLICY, $headers['Content-Security-Policy'] ?? null);
        self::assertStringEndsWith('; sandbox', ResponseFactory::SVG_CONTENT_SECURITY_POLICY);
        self::assertStringStartsWith("default-src 'none';", ResponseFactory::SVG_CONTENT_SECURITY_POLICY);
        self::assertSame('image/svg+xml', $headers['Content-Type']);
    }

    public function testFromConfig(): void
    {
        $responses = ResponseFactory::fromConfig(TestConfig::create(['CACHE_EXPIRY' => '120', 'NETWORK_ERROR_TTL' => '7']));

        self::assertSame('120', $responses->preflight('')->headers->get('Access-Control-Max-Age'));
        self::assertSame('7', $responses->error('/a.css', ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE)->headers->get('Retry-After'));
    }

    private static function assertError(int $error, int $status, string $code): void
    {
        $response = (new ResponseFactory(600, 45))->error('/css/site.css', $error);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame(
            "The request could not be processed.\nError code: " . $code . ".\nRay ID: " . md5('/css/site.css'),
            $response->getContent()
        );
        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame($error === ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE ? '45' : null, $response->headers->get('Retry-After'));
    }
}
