<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Cache misses, query-string buckets, cache hits served by PHP, request
 * validation and response headers.
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class MissAndHitTest extends CharacterizationTestCase
{
    public function testMissFetchesFromTheOriginStoresTheFileAndAnswersWithTheProxyHeaders(): void
    {
        $png = self::fixture('images/gradient.png');

        $response = $this->get('/images/gradient.png');

        self::assertSame(200, $response->getStatus());
        self::assertSame($png, $response->getBody());
        self::assertSame('miss', $response->getHeader('X-Cache-Status'));
        self::assertSame('max-age=31536000, must-revalidate, public', $response->getHeader('Cache-Control'));
        self::assertSame('image/png', $response->getHeader('Content-Type'));
        self::assertSame((string) strlen($png), $response->getHeader('Content-Length'));
        self::assertSame('"' . md5($png) . '"', $response->getHeader('ETag'));
        self::assertSame('Tue, 01 Sep 2026 10:00:00 GMT', $response->getHeader('Last-Modified'));
        self::assertSame('Origin', $response->getHeader('Vary'));
        self::assertSame('bytes', $response->getHeader('Accept-Ranges'));
        self::assertStringEqualsFile($this->publicPath('images/gradient.png'), $png);
        self::assertSame(1, $this->origin->countRequestsFor('/images/gradient.png'));
    }

    public function testMissDropsOriginHeadersOutsideTheWhitelist(): void
    {
        $response = $this->get('/images/gradient.png');

        self::assertSame(200, $response->getStatus());
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertFalse($response->hasHeader('X-Origin-Only'));
        self::assertSame('max-age=31536000, must-revalidate, public', $response->getHeader('Cache-Control'));
    }

    /**
     * The origin request carries the proxy's own scheme and host as Referer
     * and no-cache directives, as in 2.x, and a User-Agent that names this
     * software instead of the fake Firefox 81 of 2.x (finding P-22, resolved
     * by PRX-4).
     */
    public function testOriginRequestCarriesTheProxyHeadersP22(): void
    {
        $this->get('/images/gradient.png');

        $request = $this->origin->getLastRequest();
        self::assertSame('GET', $request['method']);
        self::assertSame('/images/gradient.png', $request['uri']);
        self::assertSame(HttpClientOptions::DEFAULT_USER_AGENT, $request['headers']['user-agent'] ?? null);
        self::assertSame('http://127.0.0.1:' . $this->proxy->getPort(), $request['headers']['referer'] ?? null);
        self::assertSame('no-cache', $request['headers']['pragma'] ?? null);
        self::assertSame('no-cache', $request['headers']['cache-control'] ?? null);
        self::assertArrayNotHasKey('expect', $request['headers']);
    }

    /**
     * Without a query string the web server serves a cached file itself; a
     * request that still reaches PHP is fetched from the origin again.
     */
    public function testRequestWithoutQueryStringReachingPhpIsFetchedAgain(): void
    {
        $this->get('/images/gradient.png');
        $response = $this->get('/images/gradient.png');

        self::assertSame(200, $response->getStatus());
        self::assertSame('miss', $response->getHeader('X-Cache-Status'));
        self::assertSame(2, $this->origin->countRequestsFor('/images/gradient.png'));
    }

    public function testQueryStringRequestIsCachedInItsBucketAndServedFromDisk(): void
    {
        $png = self::fixture('images/gradient.png');

        $miss = $this->get('/images/gradient.png?v=1');
        $hit = $this->get('/images/gradient.png?v=1');

        self::assertSame('miss', $miss->getHeader('X-Cache-Status'));
        self::assertStringEqualsFile($this->bucketPath('v=1', 'images/gradient.png'), $png);
        self::assertFileDoesNotExist($this->publicPath('images/gradient.png'));
        self::assertSame('/images/gradient.png?v=1', $this->origin->getLastRequest()['uri']);

        self::assertSame(200, $hit->getStatus());
        self::assertSame($png, $hit->getBody());
        self::assertSame('hit', $hit->getHeader('X-Cache-Status'));
        self::assertSame('image/png', $hit->getHeader('Content-Type'));
        self::assertSame((string) strlen($png), $hit->getHeader('Content-Length'));
        self::assertSame('max-age=31536000, must-revalidate, public', $hit->getHeader('Cache-Control'));
        self::assertSame('Accept', $hit->getHeader('Vary'));
        self::assertFalse($hit->hasHeader('ETag'));
        self::assertFalse($hit->hasHeader('Last-Modified'));
        self::assertSame(1, $this->origin->countRequestsFor('/images/gradient.png'));
    }

    public function testEquivalentQueryStringsShareABucket(): void
    {
        $this->get('/images/gradient.png?b=2&a=1');
        $hit = $this->get('/images/gradient.png?a=1&b=2');

        self::assertSame('hit', $hit->getHeader('X-Cache-Status'));
        self::assertFileExists($this->bucketPath('a=1&b=2', 'images/gradient.png'));
        self::assertSame(1, $this->origin->countRequestsFor('/images/gradient.png'));
        self::assertSame('/images/gradient.png?a=1&b=2', $this->origin->getLastRequest()['uri']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideAlteredQueryStrings(): array
    {
        return [
            'dots become underscores' => ['a.b=1', 'a_b=1'],
            'a bare key gains an equals sign' => ['v', 'v='],
            'parameters are sorted' => ['z=1&y=2', 'y=2&z=1'],
        ];
    }

    /**
     * The canonical query string keys the cache and is also what the origin
     * receives (finding P-9, resolved by PRX-5).
     *
     * @dataProvider provideAlteredQueryStrings
     */
    #[DataProvider('provideAlteredQueryStrings')]
    public function testCanonicalQueryStringIsWhatReachesTheOriginP9(string $sent, string $forwarded): void
    {
        $response = $this->get('/images/gradient.png?' . $sent);

        self::assertSame(200, $response->getStatus());
        self::assertSame('/images/gradient.png?' . $forwarded, $this->origin->getLastRequest()['uri']);
        self::assertFileExists($this->bucketPath($forwarded, 'images/gradient.png'));
    }

    /**
     * The cache file keeps the percent-encoded name, which the web server
     * never finds (finding P-8, resolved by PRX-5 and PRX-6).
     */
    public function testPercentEncodedPathIsStoredUndecodedP8(): void
    {
        $response = $this->get('/images/my%20file.png');

        self::assertSame(200, $response->getStatus());
        self::assertSame(self::fixture('images/my file.png'), $response->getBody());
        self::assertSame('/images/my%20file.png', $this->origin->getLastRequest()['uri']);
        self::assertFileExists($this->publicPath('images/my%20file.png'));
        self::assertFileDoesNotExist($this->publicPath('images/my file.png'));
    }

    /**
     * Encoded dot segments pass the 2.x traversal check undecoded and key
     * the cache literally (finding P-8, resolved by PRX-5). What reaches the
     * origin depends on libcurl, which removes them in recent versions.
     */
    public function testEncodedDotSegmentsAreNotDecodedP8(): void
    {
        $response = $this->get('/images/%2e%2e/gradient.png');

        self::assertSame(404, $response->getStatus());
        self::assertCount(1, $this->origin->getRequests());
        self::assertFileExists($this->publicPath('images/%2e%2e/gradient.png.404'));
    }

    /**
     * A hit served by PHP has the registered media type, the one origins
     * send (finding P-23: 2.x answered application/x-font-ttf from an
     * outdated table of its own).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testHitsServedByPhpUseTheRegisteredMediaTypesP23(): void
    {
        $miss = $this->get('/fonts/font.ttf?v=1');
        $hit = $this->get('/fonts/font.ttf?v=1');

        self::assertSame('font/ttf', $miss->getHeader('Content-Type'));
        self::assertSame('hit', $hit->getHeader('X-Cache-Status'));
        self::assertSame('font/ttf', $hit->getHeader('Content-Type'));
        self::assertSame(self::fixture('fonts/font.ttf'), $hit->getBody());
    }

    /**
     * No response names a version of the software that produced it: PHP's
     * own "X-Powered-By: PHP/<version>" is removed (finding P-23).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testNoResponseExposesAVersionP23(): void
    {
        $responses = [$this->get('/images/gradient.png'), $this->get('/images/gradient.png?v=1'), $this->get('/images/gradient.png?v=1'), $this->get('/no/such.png')];

        foreach ($responses as $response) {
            self::assertFalse($response->hasHeader('X-Powered-By'), (string) $response->getHeader('X-Powered-By'));
        }
    }

    /**
     * SVG served from the proxy's own origin is sandboxed and runs no
     * script, on the miss and on the hits PHP serves (finding P-7: 2.x
     * sent no Content-Security-Policy, a stored XSS on the CDN domain).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testSvgIsSandboxedByAContentSecurityPolicyP7(): void
    {
        foreach ([$this->get('/images/logo.svg'), $this->get('/images/logo.svg?v=1'), $this->get('/images/logo.svg?v=1')] as $response) {
            self::assertSame(200, $response->getStatus());
            self::assertSame('image/svg+xml', $response->getHeader('Content-Type'));
            self::assertSame(self::fixture('images/logo.svg'), $response->getBody());
            self::assertSame("default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; sandbox", $response->getHeader('Content-Security-Policy'));
            self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        }
    }

    /**
     * Methods are not checked: POST is served like GET (finding P-19,
     * resolved by PRX-5).
     */
    public function testPostIsHandledLikeGetP19(): void
    {
        $response = $this->send('POST', '/images/gradient.png');

        self::assertSame(200, $response->getStatus());
        self::assertSame(self::fixture('images/gradient.png'), $response->getBody());
        self::assertSame('GET', $this->origin->getLastRequest()['method']);
    }

    /**
     * Every OPTIONS request gets the same answer, which combines a wildcard
     * origin with credentials (finding P-19, resolved by PRX-5).
     */
    public function testPreflightAnswersWithStaticCorsHeadersP19(): void
    {
        $response = $this->send('OPTIONS', '/anything/at/all', ['Origin' => 'https://site.example.test']);

        self::assertSame(204, $response->getStatus());
        self::assertSame('', $response->getBody());
        self::assertSame('*', $response->getHeader('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeader('Access-Control-Allow-Credentials'));
        self::assertSame('GET, POST, OPTIONS', $response->getHeader('Access-Control-Allow-Methods'));
        self::assertSame(
            'DNT, X-User-Token, Keep-Alive, User-Agent, X-Requested-With, If-Modified-Since, Cache-Control, Content-Type',
            $response->getHeader('Access-Control-Allow-Headers')
        );
        self::assertSame('31536000', $response->getHeader('Access-Control-Max-Age'));
        self::assertSame('text/plain charset=UTF-8', $response->getHeader('Content-Type'));
        self::assertSame('0', $response->getHeader('Content-Length'));
        self::assertSame([], $this->origin->getRequests());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideTraversals(): array
    {
        return [
            'parent segment' => ['/images/../secret.png'],
            'trailing parent segment' => ['/images/..'],
            'dot-dot prefix of a name' => ['/images/..hidden.png'],
        ];
    }

    /**
     * @dataProvider provideTraversals
     */
    #[DataProvider('provideTraversals')]
    public function testPathsContainingSlashDotDotAreRefused(string $path): void
    {
        $response = $this->get($path);

        self::assertErrorResponse($response, 404, 'Cannot serve this request', $path);
        self::assertSame([], $this->origin->getRequests());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUncacheablePaths(): array
    {
        return [
            'extension outside the list' => ['/app/config.php'],
            'no extension' => ['/images/gradient'],
            'directory' => ['/images/'],
        ];
    }

    /**
     * @dataProvider provideUncacheablePaths
     */
    #[DataProvider('provideUncacheablePaths')]
    public function testPathsOutsideTheExtensionListAreRefused(string $path): void
    {
        $response = $this->get($path);

        self::assertErrorResponse($response, 404, 'Request cannot be processed', $path);
        self::assertSame([], $this->origin->getRequests());
    }

    public function testExtensionsAreMatchedCaseInsensitively(): void
    {
        $response = $this->get('/images/GRADIENT.PNG');

        self::assertSame(404, $response->getStatus());
        self::assertSame('Cannot read remote origin file', self::errorCodeOf($response->getBody()));
        self::assertSame('/images/GRADIENT.PNG', $this->origin->getLastRequest()['uri']);
    }

    private static function errorCodeOf(string $body): string
    {
        return preg_match('/^Error code: (.*)\.$/m', $body, $matches) === 1 ? $matches[1] : '';
    }
}
