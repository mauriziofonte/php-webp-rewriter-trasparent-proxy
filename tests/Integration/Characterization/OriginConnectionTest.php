<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * SOURCE_ORIGIN_SERVER pins the connection to an address while SOURCE_ORIGIN
 * keeps the name; UPSTREAM_FOLLOW_REDIRECTS lets the fetch follow redirects.
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class OriginConnectionTest extends CharacterizationTestCase
{
    protected function configuration(): array
    {
        return [
            'SOURCE_ORIGIN' => '"http://origin.invalid:' . $this->origin->getPort() . '/"',
            'SOURCE_ORIGIN_SERVER' => '"127.0.0.1:' . $this->origin->getPort() . '"',
            'UPSTREAM_FOLLOW_REDIRECTS' => 'true',
        ] + parent::configuration();
    }

    public function testPinnedServerReceivesTheRequestForTheOriginName(): void
    {
        $response = $this->get('/images/gradient.png');

        self::assertSame(200, $response->getStatus());
        self::assertSame(self::fixture('images/gradient.png'), $response->getBody());
        self::assertSame('origin.invalid:' . $this->origin->getPort(), $this->origin->getLastRequest()['headers']['host'] ?? null);
        self::assertSame('/images/gradient.png', $this->origin->getLastRequest()['uri']);
    }

    public function testRedirectsAreFollowedWhenEnabled(): void
    {
        $this->origin->setRule('/images/old.png', 302, '/images/gradient.png');

        $response = $this->get('/images/old.png');

        self::assertSame(200, $response->getStatus());
        self::assertSame(self::fixture('images/gradient.png'), $response->getBody());
        self::assertSame('image/png', $response->getHeader('Content-Type'));
        self::assertStringEqualsFile($this->cachePath('images/old.png'), self::fixture('images/gradient.png'));
    }
}
