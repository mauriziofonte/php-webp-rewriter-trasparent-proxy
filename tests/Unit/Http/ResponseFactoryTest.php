<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Http;

use Mfonte\ProxyCdn\Http\ResponseFactory;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The responses of 2.x, byte for byte.
 *
 * @covers \Mfonte\ProxyCdn\Http\ResponseFactory
 */
#[CoversClass(ResponseFactory::class)]
final class ResponseFactoryTest extends TestCase
{
    public function testPreflight(): void
    {
        $response = (new ResponseFactory(600, 30))->preflight();

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        self::assertSame('GET, POST, OPTIONS', $response->headers->get('Access-Control-Allow-Methods'));
        self::assertSame(
            'DNT, X-User-Token, Keep-Alive, User-Agent, X-Requested-With, If-Modified-Since, Cache-Control, Content-Type',
            $response->headers->get('Access-Control-Allow-Headers')
        );
        self::assertSame('600', $response->headers->get('Access-Control-Max-Age'));
        self::assertSame('text/plain charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('0', $response->headers->get('Content-Length'));
    }

    /**
     * Every error but an unavailable origin is a 404 (finding P-21).
     *
     * @dataProvider provideErrors
     */
    #[DataProvider('provideErrors')]
    public function testError(int $error, int $status, string $code): void
    {
        $response = (new ResponseFactory(600, 45))->error('/css/site.css', $error);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame(
            "The request could not be processed.\nError code: " . $code . ".\nRay ID: " . md5('/css/site.css'),
            $response->getContent()
        );
        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame($status === 503 ? '45' : null, $response->headers->get('Retry-After'));
    }

    /**
     * @return array<string, array{int, int, string}>
     */
    public static function provideErrors(): array
    {
        return [
            'refused' => [ResponseFactory::ERROR_REFUSED, 404, 'Cannot serve this request'],
            'cache directory' => [ResponseFactory::ERROR_CACHE_DIRECTORY, 404, 'Cannot create cache directory'],
            'cache file' => [ResponseFactory::ERROR_CACHE_FILE, 404, 'Cannot create cache file'],
            'origin not found' => [ResponseFactory::ERROR_ORIGIN_NOT_FOUND, 404, 'Cannot read remote origin file'],
            'not cacheable' => [ResponseFactory::ERROR_NOT_CACHEABLE, 404, 'Request cannot be processed'],
            'upstream unavailable' => [ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE, 503, 'Upstream temporarily unavailable'],
            'configuration' => [ResponseFactory::ERROR_CONFIGURATION, 404, 'ENV_FILE_LOAD_ERR'],
            'unknown' => [42, 404, 'Unknown error'],
        ];
    }

    public function testWithoutPathTheRayIdIsRandom(): void
    {
        $first = (string) (new ResponseFactory(600, 30))->error(null, ResponseFactory::ERROR_REFUSED)->getContent();
        $second = (string) (new ResponseFactory(600, 30))->error(null, ResponseFactory::ERROR_REFUSED)->getContent();

        self::assertMatchesRegularExpression('/\nRay ID: [0-9a-f]{32}\z/', $first);
        self::assertNotSame($first, $second);
    }

    /**
     * The answer while the configuration is invalid (finding P-21).
     */
    public function testConfigurationErrorP21(): void
    {
        $response = ResponseFactory::configurationError();

        self::assertSame(404, $response->getStatusCode());
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
    }

    public function testForwardedHeaders(): void
    {
        $forwarded = (new ResponseFactory(600, 30))->forwardedHeaders([
            'content-type' => 'image/png',
            'content-length' => '0',
            'etag' => '"e"',
            'last-modified' => 'Tue, 01 Sep 2026 10:00:00 GMT',
            'vary' => '',
            'accept-ranges' => 'bytes',
            'content-disposition' => 'inline',
            'set-cookie' => 'session=secret',
            'cache-control' => 'private',
            'expires' => '0',
            'Content-Language' => 'en',
        ]);

        self::assertSame([
            'content-type' => 'image/png',
            'etag' => '"e"',
            'last-modified' => 'Tue, 01 Sep 2026 10:00:00 GMT',
            'accept-ranges' => 'bytes',
            'content-disposition' => 'inline',
        ], $forwarded);
    }

    public function testMiss(): void
    {
        $response = (new ResponseFactory(600, 30))->miss('body', ['content-type' => 'text/css', 'etag' => '"e"'], false, 'css');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('body', $response->getContent());
        self::assertSame('text/css', $response->headers->get('content-type'));
        self::assertSame('"e"', $response->headers->get('etag'));
        self::assertSame('max-age=600, must-revalidate, public', $response->headers->get('cache-control'));
        self::assertSame('miss', $response->headers->get('x-cache-status'));
        self::assertFalse($response->headers->has('vary'));
    }

    public function testAWebpMissVariesOnAccept(): void
    {
        $response = (new ResponseFactory(600, 30))->miss('webp', ['vary' => 'Origin'], true, 'png');

        self::assertSame('Accept', $response->headers->get('vary'));
    }

    public function testHit(): void
    {
        $response = (new ResponseFactory(600, 30))->hit('body', 'text/css', 4);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('body', $response->getContent());
        self::assertSame('text/css', $response->headers->get('content-type'));
        self::assertSame('4', $response->headers->get('content-length'));
        self::assertSame('max-age=600, must-revalidate, public', $response->headers->get('cache-control'));
        self::assertSame('hit', $response->headers->get('x-cache-status'));
        self::assertSame('Accept', $response->headers->get('vary'));
    }

    /**
     * @return array<string, array{Response}>
     */
    public static function provideSvgResponses(): array
    {
        $responses = new ResponseFactory(600, 30);

        return [
            'miss of an .svg path' => [$responses->miss('<svg/>', ['content-type' => 'text/plain'], false, 'svg')],
            'miss with an SVG type' => [$responses->miss('<svg/>', ['content-type' => 'Image/SVG+XML; charset=utf-8'], false, 'png')],
            'hit' => [$responses->hit('<svg/>', 'image/svg+xml', 6)],
        ];
    }

    /**
     * An SVG gets a Content-Security-Policy that sandboxes it and forbids
     * scripts (finding P-7).
     *
     * @dataProvider provideSvgResponses
     */
    #[DataProvider('provideSvgResponses')]
    public function testSvgResponsesAreSandboxedP7(Response $response): void
    {
        self::assertSame(ResponseFactory::SVG_CONTENT_SECURITY_POLICY, $response->headers->get('content-security-policy'));
        self::assertStringEndsWith('; sandbox', ResponseFactory::SVG_CONTENT_SECURITY_POLICY);
        self::assertStringStartsWith("default-src 'none';", ResponseFactory::SVG_CONTENT_SECURITY_POLICY);
        self::assertSame('nosniff', $response->headers->get('x-content-type-options'));
    }

    public function testOtherAssetsGetNosniffOnly(): void
    {
        $responses = new ResponseFactory(600, 30);

        foreach ([$responses->miss('a', ['content-type' => 'text/css'], false, 'css'), $responses->hit('a', 'image/png', 1)] as $response) {
            self::assertSame('nosniff', $response->headers->get('x-content-type-options'));
            self::assertFalse($response->headers->has('content-security-policy'));
        }
    }

    public function testHitWithoutSize(): void
    {
        self::assertFalse((new ResponseFactory(600, 30))->hit('body', 'text/css', null)->headers->has('content-length'));
    }

    public function testFromConfig(): void
    {
        $responses = ResponseFactory::fromConfig(TestConfig::create(['CACHE_EXPIRY' => '120', 'NETWORK_ERROR_TTL' => '7']));

        self::assertSame('120', $responses->preflight()->headers->get('Access-Control-Max-Age'));
        self::assertSame('7', $responses->error('/a.css', ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE)->headers->get('Retry-After'));
    }
}
