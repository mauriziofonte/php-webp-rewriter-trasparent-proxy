<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The ".netfail" marker: any origin failure other than a 404 is remembered
 * for NETWORK_ERROR_TTL seconds and answered with 503.
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class CircuitBreakerTest extends CharacterizationTestCase
{
    public function testServerErrorOpensTheBreakerAndAnswers503(): void
    {
        $this->origin->setRule('/images/gradient.png', 500);

        $response = $this->get('/images/gradient.png');

        self::assertErrorResponse($response, 503, 'Upstream temporarily unavailable', '/images/gradient.png');
        self::assertSame('30', $response->getHeader('Retry-After'));
        self::assertFileExists($this->publicPath('images/gradient.png.netfail'));
        self::assertSame([], $this->negativeMarkers());
        self::assertFileDoesNotExist($this->publicPath('images/gradient.png'));
    }

    public function testOpenBreakerAnswersWithoutContactingTheOrigin(): void
    {
        $this->origin->setRule('/images/gradient.png', 502);
        $this->get('/images/gradient.png');
        $this->origin->removeRule('/images/gradient.png');

        $response = $this->get('/images/gradient.png');

        self::assertErrorResponse($response, 503, 'Upstream temporarily unavailable', '/images/gradient.png');
        self::assertSame(1, $this->origin->countRequestsFor('/images/gradient.png'));
    }

    public function testExpiredBreakerRetriesAndClosesOnSuccess(): void
    {
        $this->origin->setRule('/images/gradient.png', 503);
        $this->get('/images/gradient.png');
        $this->origin->removeRule('/images/gradient.png');

        self::age($this->publicPath('images/gradient.png.netfail'), 31);
        $response = $this->get('/images/gradient.png');

        self::assertSame(200, $response->getStatus());
        self::assertFileDoesNotExist($this->publicPath('images/gradient.png.netfail'));
        self::assertSame(2, $this->origin->countRequestsFor('/images/gradient.png'));
    }

    /**
     * An open breaker also hides an entry that is cached on disk.
     */
    public function testOpenBreakerAlsoAnswersForCachedEntries(): void
    {
        $this->get('/images/gradient.png?v=1');
        touch($this->bucketPath('v=1', 'images/gradient.png.netfail'));

        $response = $this->get('/images/gradient.png?v=1');

        self::assertSame(503, $response->getStatus());
    }

    /**
     * The breaker is kept per file, not per origin (finding P-14, resolved
     * by PRX-5): a failing origin is contacted again for every other path.
     */
    public function testBreakerIsKeptPerFileP14(): void
    {
        $this->origin->setRule('/images/gradient.png', 500);
        $this->origin->setRule('/js/app.js', 500);

        self::assertSame(503, $this->get('/images/gradient.png')->getStatus());
        self::assertSame(503, $this->get('/js/app.js')->getStatus());

        self::assertSame(1, $this->origin->countRequestsFor('/js/app.js'));
        self::assertFileExists($this->publicPath('js/app.js.netfail'));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideNonFailureStatuses(): array
    {
        return [
            'status 301' => [301],
            'status 302' => [302],
            'status 403' => [403],
            'status 410' => [410],
        ];
    }

    /**
     * Redirects, 403 and 410 are treated like network failures (finding
     * P-13). Since PRX-4 the fetcher reports each with a reason of its own;
     * PRX-5 changes how the kernel treats them.
     *
     * @dataProvider provideNonFailureStatuses
     */
    #[DataProvider('provideNonFailureStatuses')]
    public function testOtherOriginStatusesOpenTheBreakerP13(int $status): void
    {
        $this->origin->setRule('/images/gradient.png', $status, '/images/elsewhere.png');

        $response = $this->get('/images/gradient.png');

        self::assertErrorResponse($response, 503, 'Upstream temporarily unavailable', '/images/gradient.png');
        self::assertFileExists($this->publicPath('images/gradient.png.netfail'));
        self::assertSame(0, $this->origin->countRequestsFor('/images/elsewhere.png'));
    }

    public function testUnreachableOriginOpensTheBreaker(): void
    {
        $this->origin->stop();

        $response = $this->get('/images/gradient.png');

        self::assertErrorResponse($response, 503, 'Upstream temporarily unavailable', '/images/gradient.png');
        self::assertFileExists($this->publicPath('images/gradient.png.netfail'));
    }

    public function testFreshNotFoundMarkerIsCheckedBeforeTheBreaker(): void
    {
        $this->origin->setRule('/images/gradient.png', 404);
        $this->get('/images/gradient.png?v=1');
        $this->origin->removeRule('/images/gradient.png');
        mkdir(dirname($this->bucketPath('v=1', 'images/gradient.png')), 0777, true);
        touch($this->bucketPath('v=1', 'images/gradient.png.netfail'));

        $response = $this->get('/images/gradient.png?v=1');

        self::assertSame(404, $response->getStatus());
    }
}
