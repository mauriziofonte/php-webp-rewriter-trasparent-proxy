<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * A configuration that misses a required value.
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class InvalidConfigurationTest extends CharacterizationTestCase
{
    protected function configuration(): array
    {
        return ['CACHEABLE_EXTENSIONS' => null] + parent::configuration();
    }

    /**
     * Every request, OPTIONS included, gets the configuration error as 404
     * (finding P-21, resolved by PRX-5) with a random Ray ID.
     */
    public function testEveryRequestAnswersWithTheLoadErrorP21(): void
    {
        foreach (['GET', 'OPTIONS'] as $method) {
            $response = $this->send($method, '/images/gradient.png');

            self::assertSame(404, $response->getStatus());
            self::assertSame('text/plain; charset=UTF-8', $response->getHeader('Content-Type'));
            self::assertMatchesRegularExpression(
                '/^The request could not be processed\\.\\nError code: ENV_FILE_LOAD_ERR\\.\\nRay ID: [0-9a-f]{32}$/',
                $response->getBody()
            );
        }
        self::assertSame([], $this->origin->getRequests());
    }
}
