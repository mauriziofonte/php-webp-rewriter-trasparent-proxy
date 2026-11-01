<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The circuit breaker of the origin, and how the other origin answers are
 * served (CIRCUIT_BREAKER_THRESHOLD=2 here).
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class CircuitBreakerTest extends CharacterizationTestCase
{
    public function testServerErrorsOpenTheBreakerAfterTheThreshold(): void
    {
        $this->origin->setRule('/images/gradient.png', 500);

        $first = $this->get('/images/gradient.png');
        $second = $this->get('/images/gradient.png');
        $third = $this->get('/images/gradient.png');

        foreach ([$first, $second, $third] as $response) {
            self::assertErrorResponse($response, 503, 'Upstream temporarily unavailable', '/images/gradient.png');
            self::assertSame('30', $response->getHeader('Retry-After'));
        }
        self::assertSame(2, $this->origin->countRequestsFor('/images/gradient.png'));
        self::assertCount(1, $this->breakerStates());
        self::assertSame([], $this->negativeMarkers());
        self::assertFileDoesNotExist($this->cachePath('images/gradient.png.netfail'));
        self::assertFileDoesNotExist($this->cachePath('images/gradient.png'));
    }

    /**
     * Once open, the breaker answers for every path of the origin without
     * contacting it, while the entries on disk are still served (finding
     * P-14: 2.x kept one breaker per file, so a failing origin was
     * contacted again for every other path, and an open breaker hid the
     * cached copy of its own file).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testTheBreakerIsKeptPerOriginP14(): void
    {
        $this->get('/css/site.css?v=1');
        $this->origin->setRule('/images/gradient.png', 502);
        $this->origin->setRule('/js/app.js', 502);
        $this->get('/images/gradient.png');
        $this->get('/images/gradient.png');

        $other = $this->get('/js/app.js');
        $cached = $this->get('/css/site.css?v=1');

        self::assertErrorResponse($other, 503, 'Upstream temporarily unavailable', '/js/app.js');
        self::assertSame(0, $this->origin->countRequestsFor('/js/app.js'));
        self::assertSame(200, $cached->getStatus());
        self::assertSame('HIT', $cached->getHeader('X-Cache-Status'));
        self::assertFileDoesNotExist($this->cachePath('js/app.js.netfail'));
    }

    public function testAnExpiredBreakerLetsAProbeThroughAndClosesOnSuccess(): void
    {
        $this->origin->setRule('/images/gradient.png', 503);
        $this->get('/images/gradient.png');
        $this->get('/images/gradient.png');
        $this->origin->removeRule('/images/gradient.png');
        self::assertSame(503, $this->get('/js/app.js')->getStatus());

        $this->shiftBreakerTimes(-31);
        $probe = $this->get('/images/gradient.png');
        $after = $this->get('/js/app.js');

        self::assertSame(200, $probe->getStatus());
        self::assertSame(200, $after->getStatus());
        self::assertSame([], $this->breakerStates());
        self::assertSame(3, $this->origin->countRequestsFor('/images/gradient.png'));
    }

    public function testAFailedProbeOpensTheBreakerAgain(): void
    {
        $this->origin->setRule('/images/gradient.png', 500);
        $this->get('/images/gradient.png');
        $this->get('/images/gradient.png');

        $this->shiftBreakerTimes(-31);
        $probe = $this->get('/images/gradient.png');
        $next = $this->get('/js/app.js');

        self::assertSame(503, $probe->getStatus());
        self::assertSame(503, $next->getStatus());
        self::assertSame(3, $this->origin->countRequestsFor('/images/gradient.png'));
        self::assertSame(0, $this->origin->countRequestsFor('/js/app.js'));
    }

    /**
     * @return array<string, array{int, string|null}>
     */
    public static function provideAvailableAnswers(): array
    {
        return [
            'status 404' => [404, null],
            'status 403' => [403, null],
            'status 410' => [410, null],
            'redirect' => [302, '/images/elsewhere.png'],
        ];
    }

    /**
     * A probe the origin answers with anything but unavailability closes
     * the breaker: the origin is up, whatever it says of that path.
     *
     * @dataProvider provideAvailableAnswers
     */
    #[DataProvider('provideAvailableAnswers')]
    public function testAProbeAnsweredByTheOriginClosesTheBreaker(int $status, ?string $location): void
    {
        $this->origin->setRule('/images/gradient.png', 503);
        $this->get('/images/gradient.png');
        $this->get('/images/gradient.png');
        $this->origin->setRule('/images/gradient.png', $status, $location);
        self::assertSame(503, $this->get('/js/app.js')->getStatus());

        $this->shiftBreakerTimes(-31);
        $probe = $this->get('/images/gradient.png');
        $after = $this->get('/js/app.js');

        self::assertSame($status, $probe->getStatus());
        self::assertSame(200, $after->getStatus());
        self::assertSame([], $this->breakerStates());
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
        self::assertSame([], $this->breakerStates());
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

    public function testAnUnreachableOriginCountsAsAFailure(): void
    {
        $this->origin->stop();

        $this->get('/images/gradient.png');
        $response = $this->get('/js/app.js');

        self::assertErrorResponse($response, 503, 'Upstream temporarily unavailable', '/js/app.js');
        self::assertCount(1, $this->breakerStates());
    }

    public function testAFreshNotFoundMarkerIsCheckedBeforeTheBreaker(): void
    {
        $this->origin->setRule('/images/gradient.png', 404);
        $this->get('/images/gradient.png?v=1');
        $this->origin->setRule('/js/app.js', 500);
        $this->get('/js/app.js');
        $this->get('/js/app.js');

        $response = $this->get('/images/gradient.png?v=1');

        self::assertErrorResponse($response, 404, 'Cannot read remote origin file', '/images/gradient.png');
    }

    protected function configuration(): array
    {
        return array_merge(parent::configuration(), ['CIRCUIT_BREAKER_THRESHOLD' => '2']);
    }

    /**
     * The state files of the breakers, in var/breaker/ of the copy.
     *
     * @return list<string>
     */
    private function breakerStates(): array
    {
        $files = glob($this->proxy->getProjectDirectory() . '/var/breaker/*.breaker');

        return $files === false ? [] : $files;
    }

    /**
     * Moves the times kept by every breaker by $seconds.
     */
    private function shiftBreakerTimes(int $seconds): void
    {
        foreach ($this->breakerStates() as $file) {
            $state = json_decode((string) file_get_contents($file), true);
            self::assertIsArray($state);
            foreach (['last_failure_at', 'opened_at', 'probe_at'] as $name) {
                if (isset($state[$name]) && is_int($state[$name]) && $state[$name] !== 0) {
                    $state[$name] += $seconds;
                }
            }
            file_put_contents($file, json_encode($state));
        }
    }
}
