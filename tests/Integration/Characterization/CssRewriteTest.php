<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * CSS rewriting of root-relative url() references (removed by PRX-5).
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class CssRewriteTest extends CharacterizationTestCase
{
    /**
     * The scheme and Host header of the request that caused the miss are
     * written into the shared cache file and served to every later client
     * (finding P-2, resolved by PRX-5).
     */
    public function testRootRelativeUrlsAreRewrittenWithTheRequestHostP2(): void
    {
        $this->get('/css/site.css?v=1', ['Host' => 'attacker.example.test']);
        $hit = $this->get('/css/site.css?v=1', ['Host' => 'cdn.example.test']);

        $expected = [
            'body { background: url(http://attacker.example.test/images/gradient.png); }',
            ".quoted { background: url('http://attacker.example.test/images/gradient.png'); }",
            '.double { background: url("http://attacker.example.test/images/gradient.png"); }',
        ];
        foreach ($expected as $line) {
            self::assertStringContainsString($line, $hit->getBody());
            self::assertStringContainsString($line, (string) file_get_contents($this->bucketPath('v=1', 'css/site.css')));
        }
        self::assertSame('hit', $hit->getHeader('X-Cache-Status'));
        self::assertSame('text/css;charset=UTF-8', $hit->getHeader('Content-Type'));
    }

    /**
     * Protocol-relative references are broken (finding P-3, resolved by
     * PRX-5); relative ones are left alone.
     */
    public function testProtocolRelativeUrlsAreBrokenP3(): void
    {
        $response = $this->get('/css/site.css', ['Host' => 'cdn.example.test']);

        self::assertStringContainsString('url(http://cdn.example.test//static.example.test/x.png)', $response->getBody());
        self::assertStringContainsString('url(images/gradient.png)', $response->getBody());
    }

    /**
     * The miss response announces the origin's Content-Length although the
     * rewritten body is longer (finding P-4, resolved by PRX-5).
     */
    public function testOriginContentLengthIsSentForTheLongerBodyP4(): void
    {
        $original = self::fixture('css/site.css');

        $response = $this->get('/css/site.css', ['Host' => 'cdn.example.test']);

        self::assertSame(200, $response->getStatus());
        self::assertSame('text/css;charset=UTF-8', $response->getHeader('Content-Type'));
        self::assertSame((string) strlen($original), $response->getHeader('Content-Length'));
        self::assertGreaterThan(strlen($original), strlen($response->getBody()));
    }

    public function testOtherTypesAreNotRewritten(): void
    {
        $response = $this->get('/js/app.js', ['Host' => 'cdn.example.test']);

        self::assertSame(self::fixture('js/app.js'), $response->getBody());
    }
}
