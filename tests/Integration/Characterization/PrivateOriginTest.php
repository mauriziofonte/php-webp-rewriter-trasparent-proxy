<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * The default answer of the fake origin, "Cache-Control: private,
 * max-age=60": the proxy passes the file through without storing it
 * (finding P-15), and still drops the origin headers outside the
 * whitelist.
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class PrivateOriginTest extends CharacterizationTestCase
{
    public function testAPrivateFileIsServedButNeverStored(): void
    {
        $css = self::fixture('css/site.css');

        foreach ([1, 2] as $request) {
            $response = $this->get('/css/site.css');

            self::assertSame(200, $response->getStatus());
            self::assertSame($css, $response->getBody());
            self::assertSame('BYPASS', $response->getHeader('X-Cache-Status'));
            self::assertFalse($response->hasHeader('Set-Cookie'));
            self::assertFalse($response->hasHeader('X-Origin-Only'));
            self::assertFileDoesNotExist($this->cachePath('css/site.css'));
            self::assertSame($request, $this->origin->countRequestsFor('/css/site.css'), 'fetched each time');
        }
    }

    protected function originCacheControl(): ?string
    {
        return null;
    }
}
