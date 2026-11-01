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
        self::assertSame('MISS', $response->getHeader('X-Cache-Status'));
        self::assertSame('max-age=31536000, must-revalidate, public', $response->getHeader('Cache-Control'));
        self::assertSame('image/png', $response->getHeader('Content-Type'));
        self::assertSame((string) strlen($png), $response->getHeader('Content-Length'));
        // The validators of the cache file, which the web server sends on a hit too.
        self::assertSame(self::etagOf($this->cachePath('images/gradient.png')), $response->getHeader('ETag'));
        self::assertSame(gmdate('D, d M Y H:i:s', (int) filemtime($this->cachePath('images/gradient.png'))) . ' GMT', $response->getHeader('Last-Modified'));
        self::assertSame('Accept', $response->getHeader('Vary'));
        self::assertStringEqualsFile($this->cachePath('images/gradient.png'), $png);
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
     * A stored file whose request still reaches PHP (a web server that does
     * not serve it, or a query string) is served from disk, and parameters
     * outside QUERY_STRING_ALLOWLIST neither create an entry nor reach the
     * origin (finding P-5: 2.x fetched the file again for every request
     * that reached PHP, and stored a new entry for every query string).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testStoredFileReachingPhpIsServedFromDiskP5(): void
    {
        $this->get('/images/gradient.png');
        $again = $this->get('/images/gradient.png');
        $busted = $this->get('/images/gradient.png?_=1700000000');
        $tracked = $this->get('/images/gradient.png?utm_source=mail&fbclid=x');

        foreach ([$again, $busted, $tracked] as $response) {
            self::assertSame(200, $response->getStatus());
            self::assertSame('HIT', $response->getHeader('X-Cache-Status'));
        }
        self::assertSame(1, $this->origin->countRequestsFor('/images/gradient.png'));
        self::assertDirectoryDoesNotExist($this->publicPath('_qs'));
    }

    public function testQueryStringRequestIsCachedInItsBucketAndServedFromDisk(): void
    {
        $png = self::fixture('images/gradient.png');

        $miss = $this->get('/images/gradient.png?v=1');
        $hit = $this->get('/images/gradient.png?v=1');

        self::assertSame('MISS', $miss->getHeader('X-Cache-Status'));
        self::assertStringEqualsFile($this->bucketPath('v=1', 'images/gradient.png'), $png);
        self::assertFileDoesNotExist($this->cachePath('images/gradient.png'));
        self::assertSame('/images/gradient.png?v=1', $this->origin->getLastRequest()['uri']);

        self::assertSame(200, $hit->getStatus());
        self::assertSame($png, $hit->getBody());
        self::assertSame('HIT', $hit->getHeader('X-Cache-Status'));
        self::assertSame('image/png', $hit->getHeader('Content-Type'));
        self::assertSame((string) strlen($png), $hit->getHeader('Content-Length'));
        self::assertSame('max-age=31536000, must-revalidate, public', $hit->getHeader('Cache-Control'));
        self::assertSame('Accept', $hit->getHeader('Vary'));
        self::assertSame(self::etagOf($this->bucketPath('v=1', 'images/gradient.png')), $hit->getHeader('ETag'));
        self::assertTrue($hit->hasHeader('Last-Modified'));
        self::assertSame(1, $this->origin->countRequestsFor('/images/gradient.png'));
    }

    public function testEquivalentQueryStringsShareABucket(): void
    {
        $this->get('/images/gradient.png?ver=2&v=1');
        $hit = $this->get('/images/gradient.png?v=1&utm_source=mail&ver=2');

        self::assertSame('HIT', $hit->getHeader('X-Cache-Status'));
        self::assertFileExists($this->bucketPath('v=1&ver=2', 'images/gradient.png'));
        self::assertSame(1, $this->origin->countRequestsFor('/images/gradient.png'));
        self::assertSame('/images/gradient.png?ver=2&v=1', $this->origin->getLastRequest()['uri']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideForwardedQueryStrings(): array
    {
        return [
            'a bare key stays bare' => ['v', 'v', 'v'],
            'the order is kept' => ['ver=2&v=1', 'ver=2&v=1', 'v=1&ver=2'],
            'other parameters are left out' => ['utm_source=mail&v=3', 'v=3', 'v=3'],
        ];
    }

    /**
     * The origin receives the allowlisted parameters as the client wrote
     * them; only the cache key is canonical (finding P-9: 2.x forwarded its
     * canonical form, "v=" for "v" and the parameters sorted).
     *
     * @dataProvider provideForwardedQueryStrings
     *
     * @group regression
     */
    #[DataProvider('provideForwardedQueryStrings')]
    #[Group('regression')]
    public function testOriginalQueryStringReachesTheOriginP9(string $sent, string $forwarded, string $canonical): void
    {
        $response = $this->get('/images/gradient.png?' . $sent);

        self::assertSame(200, $response->getStatus());
        self::assertSame('/images/gradient.png?' . $forwarded, $this->origin->getLastRequest()['uri']);
        self::assertFileExists($this->bucketPath($canonical, 'images/gradient.png'));
    }

    /**
     * The cache file has the decoded name, the one the web server looks
     * for, and any spelling of the path shares it (finding P-8: 2.x stored
     * "my%20file.png", which the web server never found). The origin gets
     * the path encoded once.
     *
     * @group regression
     */
    #[Group('regression')]
    public function testPercentEncodedPathIsStoredDecodedP8(): void
    {
        $response = $this->get('/images/my%20file.png?v=1');
        $hit = $this->get('/images/my%20fil%65.png?v=1');

        self::assertSame(200, $response->getStatus());
        self::assertSame(self::fixture('images/my file.png'), $response->getBody());
        self::assertSame('/images/my%20file.png?v=1', $this->origin->getLastRequest()['uri']);
        self::assertFileExists($this->bucketPath('v=1', 'images/my file.png'));
        self::assertFileDoesNotExist($this->bucketPath('v=1', 'images/my%20file.png'));
        self::assertSame('HIT', $hit->getHeader('X-Cache-Status'));
        self::assertCount(1, $this->origin->getRequests());

        $this->get('/images/my%20file.png');
        self::assertFileExists($this->cachePath('images/my file.png'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnsafeEncodedPaths(): array
    {
        return [
            'encoded dot segments' => ['/images/%2e%2e/gradient.png'],
            'encoded slash' => ['/images%2fgradient.png'],
            'encoded NUL' => ['/images/gradient.png%00.png'],
            'invalid UTF-8' => ['/images/%FF.png'],
            'query-string bucket' => ['/_qs/a798de8ee75aeb55/images/gradient.png'],
        ];
    }

    /**
     * A path that decodes to another file than the one it seems to name is
     * refused before any cache entry, marker or origin request (finding P-8:
     * 2.x stored "%2e%2e" literally and asked the origin).
     *
     * @dataProvider provideUnsafeEncodedPaths
     *
     * @group regression
     */
    #[DataProvider('provideUnsafeEncodedPaths')]
    #[Group('regression')]
    public function testUnsafeEncodedPathsAreRefusedP8(string $path): void
    {
        $response = $this->get($path);

        self::assertSame(400, $response->getStatus());
        self::assertSame([], $this->origin->getRequests());
        self::assertSame(['.', '..', 'index.php'], array_values(array_diff((array) scandir($this->publicPath('')), ['.htaccess'])));
    }

    /**
     * Unicode file names are stored under their UTF-8 name (risk L-15).
     */
    public function testUnicodePathsAreStoredUnderTheirUtf8Name(): void
    {
        $this->get('/images/caf%C3%A9.png');

        self::assertSame('/images/caf%C3%A9.png', $this->origin->getLastRequest()['uri']);
        self::assertSame(['/images/café.png'], array_column($this->origin->getRequests(), 'path'));
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
        self::assertSame('HIT', $hit->getHeader('X-Cache-Status'));
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
     * @return array<string, array{string}>
     */
    public static function provideRefusedMethods(): array
    {
        return [
            'POST' => ['POST'],
            'PUT' => ['PUT'],
            'DELETE' => ['DELETE'],
            'PATCH' => ['PATCH'],
        ];
    }

    /**
     * Only GET, HEAD and OPTIONS are answered; any other method gets 405
     * and never reaches the origin (finding P-19: 2.x served POST, PUT and
     * DELETE like GET).
     *
     * @dataProvider provideRefusedMethods
     *
     * @group regression
     */
    #[DataProvider('provideRefusedMethods')]
    #[Group('regression')]
    public function testOtherMethodsAreRefusedP19(string $method): void
    {
        $response = $this->send($method, '/images/gradient.png');

        self::assertErrorResponse($response, 405, 'Method not allowed', '/images/gradient.png');
        self::assertSame('GET, HEAD, OPTIONS', $response->getHeader('Allow'));
        self::assertSame([], $this->origin->getRequests());
        self::assertFileDoesNotExist($this->cachePath('images/gradient.png'));
    }

    public function testHeadAnswersTheHeadersOfTheGetWithoutBody(): void
    {
        $png = self::fixture('images/gradient.png');

        $miss = $this->send('HEAD', '/images/gradient.png?v=1');
        $hit = $this->send('HEAD', '/images/gradient.png?v=1');

        foreach (['MISS' => $miss, 'HIT' => $hit] as $status => $response) {
            self::assertSame(200, $response->getStatus());
            self::assertSame('', $response->getBody());
            self::assertSame($status, $response->getHeader('X-Cache-Status'));
            self::assertSame('image/png', $response->getHeader('Content-Type'));
            self::assertSame((string) strlen($png), $response->getHeader('Content-Length'));
        }
        self::assertStringEqualsFile($this->bucketPath('v=1', 'images/gradient.png'), $png);
    }

    /**
     * A CORS preflight gets a valid answer: the "*" origin without
     * credentials, the methods the proxy answers and the request headers
     * the browser asks for (finding P-19: 2.x combined "*" with
     * credentials, which browsers refuse, and allowed POST).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testPreflightIsAValidCorsAnswerP19(): void
    {
        $response = $this->send('OPTIONS', '/fonts/font.ttf', [
            'Origin' => 'https://site.example.test',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'range, if-none-match',
        ]);

        self::assertSame(204, $response->getStatus());
        self::assertSame('', $response->getBody());
        self::assertSame('*', $response->getHeader('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
        self::assertSame('GET, HEAD, OPTIONS', $response->getHeader('Access-Control-Allow-Methods'));
        self::assertSame('range, if-none-match', $response->getHeader('Access-Control-Allow-Headers'));
        self::assertSame('86400', $response->getHeader('Access-Control-Max-Age'));
        self::assertSame([], $this->origin->getRequests());
    }

    /**
     * The asset a preflight allows is answered with the CORS header too.
     */
    public function testAssetsAllowEveryOrigin(): void
    {
        $response = $this->get('/fonts/font.ttf', ['Origin' => 'https://site.example.test']);

        self::assertSame('*', $response->getHeader('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
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

        self::assertErrorResponse($response, 400, 'Cannot serve this request', $path);
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
