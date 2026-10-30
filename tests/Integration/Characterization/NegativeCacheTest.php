<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * The ".404" marker: an origin 404 is remembered for NEGATIVE_CACHE_TTL
 * seconds next to the would-be cache file.
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class NegativeCacheTest extends CharacterizationTestCase
{
    public function testOriginNotFoundArmsTheMarkerAndAnswers404(): void
    {
        $response = $this->get('/images/missing.png');

        self::assertErrorResponse($response, 404, 'Cannot read remote origin file', '/images/missing.png');
        self::assertFileExists($this->publicPath('images/missing.png.404'));
        self::assertFileDoesNotExist($this->publicPath('images/missing.png'));
        self::assertFileDoesNotExist($this->publicPath('images/missing.png.netfail'));
    }

    public function testFreshMarkerAnswersWithoutContactingTheOrigin(): void
    {
        $this->get('/images/missing.png');
        $response = $this->get('/images/missing.png');

        self::assertErrorResponse($response, 404, 'Cannot read remote origin file', '/images/missing.png');
        self::assertSame(1, $this->origin->countRequestsFor('/images/missing.png'));
    }

    public function testExpiredMarkerIsRetriedAndRemovedOnSuccess(): void
    {
        $this->origin->setRule('/images/gradient.png', 404);
        $this->get('/images/gradient.png');
        $this->origin->removeRule('/images/gradient.png');

        self::assertSame(404, $this->get('/images/gradient.png')->getStatus());
        self::assertSame(1, $this->origin->countRequestsFor('/images/gradient.png'));

        self::age($this->publicPath('images/gradient.png.404'), 301);
        $response = $this->get('/images/gradient.png');

        self::assertSame(200, $response->getStatus());
        self::assertSame('miss', $response->getHeader('X-Cache-Status'));
        self::assertFileDoesNotExist($this->publicPath('images/gradient.png.404'));
        self::assertSame(2, $this->origin->countRequestsFor('/images/gradient.png'));
    }

    public function testMarkerOfAQueryStringRequestLivesInItsBucket(): void
    {
        $this->get('/images/missing.png?v=3');

        self::assertFileExists($this->bucketPath('v=3', 'images/missing.png.404'));
        self::assertSame(200, $this->get('/images/gradient.png?v=3')->getStatus());
    }

    /**
     * Every path that does not exist creates its directories and a marker
     * (finding P-5, resolved by PRX-5).
     */
    public function testNotFoundCreatesDirectoriesForPathsThatDoNotExistP5(): void
    {
        $this->get('/no/such/directory/file.png');

        self::assertDirectoryExists($this->publicPath('no/such/directory'));
        self::assertFileExists($this->publicPath('no/such/directory/file.png.404'));
    }
}
