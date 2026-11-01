<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
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
     * A redirect within the origin is passed on with its Location on the
     * proxy, a redirect elsewhere is a 502, 403 is a 403, and 410 is a 410
     * remembered like a 404; none opens the breaker (finding P-13: 2.x
     * answered 503 for each and kept answering it for NETWORK_ERROR_TTL).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testRedirectsForbiddenAndGoneAreAnsweredAsSuchP13(): void
    {
        $this->origin->setRule('/images/moved.png', 301, '/images/gradient.png?v=2');
        $this->origin->setRule('/images/away.png', 302, 'http://127.0.0.1:1/internal.png');
        $this->origin->setRule('/images/forbidden.png', 403);
        $this->origin->setRule('/images/gone.png', 410);

        $moved = $this->get('/images/moved.png');
        $away = $this->get('/images/away.png');
        $forbidden = $this->get('/images/forbidden.png');
        $gone = $this->get('/images/gone.png');
        $goneAgain = $this->get('/images/gone.png');

        self::assertSame(301, $moved->getStatus());
        self::assertSame('/images/gradient.png?v=2', $moved->getHeader('Location'));
        self::assertErrorResponse($away, 502, 'Bad response from the remote origin', '/images/away.png');
        self::assertErrorResponse($forbidden, 403, 'Remote origin refused the file', '/images/forbidden.png');
        self::assertErrorResponse($gone, 410, 'Remote origin file is gone', '/images/gone.png');
        self::assertErrorResponse($goneAgain, 410, 'Remote origin file is gone', '/images/gone.png');
        self::assertSame(1, $this->origin->countRequestsFor('/images/gone.png'));
        foreach (['moved', 'away', 'forbidden', 'gone'] as $name) {
            self::assertFileDoesNotExist($this->publicPath('images/' . $name . '.png.netfail'));
        }
        self::assertSame(0, $this->origin->countRequestsFor('/images/gradient.png'));
    }

    /**
     * The 3xx answer of the origin is retried at once: it never keeps the
     * file unavailable (finding P-13).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testARedirectDoesNotOpenTheBreakerP13(): void
    {
        $this->origin->setRule('/images/gradient.png', 302, '/images/elsewhere.png');
        $this->get('/images/gradient.png');
        $this->origin->removeRule('/images/gradient.png');

        $response = $this->get('/images/gradient.png');

        self::assertSame(200, $response->getStatus());
        self::assertSame(2, $this->origin->countRequestsFor('/images/gradient.png'));
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
