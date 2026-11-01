<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * Stylesheets are cached and served as the origin sent them: 2.x rewrote
 * their root-relative url() references with the scheme and Host of the
 * request (findings P-2, P-3 and P-4, resolved by PRX-5 by removing the
 * rewriting; a root-relative reference already resolves against the proxy
 * that serves the stylesheet).
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class StylesheetTest extends CharacterizationTestCase
{
    /**
     * The Host header of the request that caused the miss never reaches the
     * shared cache file or a later client (finding P-2).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testTheRequestHostNeverReachesTheCachedStylesheetP2(): void
    {
        $original = self::fixture('css/site.css');

        $miss = $this->get('/css/site.css?v=1', ['Host' => 'attacker.example.test']);
        $hit = $this->get('/css/site.css?v=1', ['Host' => 'cdn.example.test']);

        self::assertSame($original, $miss->getBody());
        self::assertSame('hit', $hit->getHeader('X-Cache-Status'));
        self::assertSame($original, $hit->getBody());
        self::assertStringEqualsFile($this->bucketPath('v=1', 'css/site.css'), $original);
        self::assertStringNotContainsString('attacker.example.test', $hit->getBody());
        self::assertSame('text/css;charset=UTF-8', $hit->getHeader('Content-Type'));
    }

    /**
     * Protocol-relative and relative references are left as they are
     * (finding P-3).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testProtocolRelativeUrlsAreKeptP3(): void
    {
        $response = $this->get('/css/site.css', ['Host' => 'cdn.example.test']);

        self::assertStringContainsString('url(//static.example.test/x.png)', $response->getBody());
        self::assertStringContainsString('url(/images/gradient.png)', $response->getBody());
        self::assertStringContainsString('url(images/gradient.png)', $response->getBody());
    }

    /**
     * The Content-Length of the miss is the length of the body sent (finding
     * P-4: 2.x announced the origin length for a longer rewritten body).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testContentLengthIsTheLengthOfTheBodyP4(): void
    {
        $response = $this->get('/css/site.css', ['Host' => 'cdn.example.test']);

        self::assertSame(200, $response->getStatus());
        self::assertSame('text/css;charset=UTF-8', $response->getHeader('Content-Type'));
        self::assertSame((string) strlen($response->getBody()), $response->getHeader('Content-Length'));
        self::assertSame(self::fixture('css/site.css'), $response->getBody());
    }

    public function testOtherTypesAreServedAsTheOriginSentThem(): void
    {
        $response = $this->get('/js/app.js', ['Host' => 'cdn.example.test']);

        self::assertSame(self::fixture('js/app.js'), $response->getBody());
    }
}
