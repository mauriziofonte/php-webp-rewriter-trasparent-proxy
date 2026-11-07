<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Origin;

use Mfonte\ProxyCdn\Origin\OriginCircuitBreaker;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Origin\OriginCircuitBreaker
 */
#[CoversClass(OriginCircuitBreaker::class)]
final class OriginCircuitBreakerTest extends TestCase
{
    private const ORIGIN = 'https://origin.example.test:443';

    private const OTHER = 'https://other.example.test:443';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testItIsClosedAtFirst(): void
    {
        self::assertFalse($this->breaker()->isOpen(self::ORIGIN));
        self::assertDirectoryDoesNotExist($this->directory->path('breaker'));
    }

    /**
     * The breaker belongs to the origin and opens after the threshold
     * (finding P-14: 2.x opened one breaker per file, at the first
     * failure, and kept contacting the origin for every other path).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testItOpensForTheOriginAfterTheThresholdP14(): void
    {
        $breaker = $this->breaker();

        $breaker->recordFailure(self::ORIGIN);
        $breaker->recordFailure(self::ORIGIN);
        self::assertFalse($breaker->isOpen(self::ORIGIN));

        $breaker->recordFailure(self::ORIGIN);
        self::assertTrue($breaker->isOpen(self::ORIGIN));
        self::assertTrue($this->breaker()->isOpen(self::ORIGIN), 'the state is shared by every worker');
        self::assertFalse($breaker->isOpen(self::OTHER));
    }

    public function testASuccessStartsTheCountAgain(): void
    {
        $breaker = $this->breaker();
        $breaker->recordFailure(self::ORIGIN);
        $breaker->recordFailure(self::ORIGIN);

        $breaker->reset(self::ORIGIN);
        $breaker->recordFailure(self::ORIGIN);
        $breaker->recordFailure(self::ORIGIN);

        self::assertFalse($breaker->isOpen(self::ORIGIN));
    }

    public function testOldFailuresAreForgotten(): void
    {
        $breaker = $this->breaker();
        $breaker->recordFailure(self::ORIGIN);
        $breaker->recordFailure(self::ORIGIN);
        $this->shiftTimes(-31);

        $breaker->recordFailure(self::ORIGIN);

        self::assertFalse($breaker->isOpen(self::ORIGIN));
    }

    /**
     * The pause runs from the end of the last failure to the start of the
     * fetch that fails next: a failure that took long (a timeout) is not
     * a pause, so one URL whose fetches time out, each the outcome window
     * after the last, still opens the breaker.
     */
    public function testThePauseEndsWhenTheFailingFetchStarted(): void
    {
        $breaker = $this->breaker();
        for ($failure = 0; $failure < 3; ++$failure) {
            // Each fetch started 5 seconds after the last failure and took
            // 60 seconds (UPSTREAM_TIMEOUT), more than the 30-second open time.
            $this->shiftTimes(-65);
            $breaker->recordFailure(self::ORIGIN, time() - 60);
        }

        self::assertTrue($breaker->isOpen(self::ORIGIN));
    }

    public function testAPauseUpToTheStartOfTheFailingFetchStartsTheCountAgain(): void
    {
        $breaker = $this->breaker();
        $breaker->recordFailure(self::ORIGIN);
        $breaker->recordFailure(self::ORIGIN);
        $this->shiftTimes(-41);

        $breaker->recordFailure(self::ORIGIN, time() - 10);

        self::assertFalse($breaker->isOpen(self::ORIGIN), 'a 31-second pause');
    }

    public function testItLetsOneProbeThroughAfterTheOpenTime(): void
    {
        $breaker = $this->opened();
        $this->shiftTimes(-30);

        self::assertFalse($breaker->isOpen(self::ORIGIN), 'the probe goes through');
        self::assertTrue($breaker->isOpen(self::ORIGIN), 'the others wait for the probe');
    }

    public function testAFailedProbeOpensItAgainAtOnce(): void
    {
        $breaker = $this->opened();
        $this->shiftTimes(-30);
        self::assertFalse($breaker->isOpen(self::ORIGIN));

        $breaker->recordFailure(self::ORIGIN);

        self::assertTrue($breaker->isOpen(self::ORIGIN));
        $this->shiftTimes(-30);
        self::assertFalse($breaker->isOpen(self::ORIGIN));
    }

    public function testASuccessfulProbeClosesIt(): void
    {
        $breaker = $this->opened();
        $this->shiftTimes(-30);
        self::assertFalse($breaker->isOpen(self::ORIGIN));

        $breaker->reset(self::ORIGIN);

        self::assertFalse($breaker->isOpen(self::ORIGIN));
        self::assertFalse($breaker->isOpen(self::ORIGIN));
    }

    public function testAZeroOpenTimeNeverOpens(): void
    {
        $breaker = new OriginCircuitBreaker($this->directory->path('breaker'), 0, 1);

        $breaker->recordFailure(self::ORIGIN);

        self::assertFalse($breaker->isOpen(self::ORIGIN));
    }

    public function testAThresholdOfOneOpensAtTheFirstFailure(): void
    {
        $breaker = new OriginCircuitBreaker($this->directory->path('breaker'), 30, 1);

        $breaker->recordFailure(self::ORIGIN);

        self::assertTrue($breaker->isOpen(self::ORIGIN));
    }

    public function testAnUnwritableDirectoryKeepsItClosed(): void
    {
        $this->directory->write('file', 'not a directory');
        $breaker = new OriginCircuitBreaker($this->directory->path('file/breaker'), 30, 1);

        $breaker->recordFailure(self::ORIGIN);

        self::assertFalse($breaker->isOpen(self::ORIGIN));
    }

    public function testACorruptStateIsAClosedBreaker(): void
    {
        $breaker = $this->opened();
        foreach ($this->stateFiles() as $file) {
            file_put_contents($file, 'garbage');
        }

        self::assertFalse($breaker->isOpen(self::ORIGIN));
    }

    private function breaker(): OriginCircuitBreaker
    {
        return new OriginCircuitBreaker($this->directory->path('breaker'), 30, 3);
    }

    private function opened(): OriginCircuitBreaker
    {
        $breaker = $this->breaker();
        for ($failure = 0; $failure < 3; ++$failure) {
            $breaker->recordFailure(self::ORIGIN);
        }
        self::assertTrue($breaker->isOpen(self::ORIGIN));

        return $breaker;
    }

    /**
     * Moves every time of the stored states by $seconds.
     */
    private function shiftTimes(int $seconds): void
    {
        foreach ($this->stateFiles() as $file) {
            $state = json_decode((string) file_get_contents($file), true);
            self::assertIsArray($state);
            foreach ($state as $name => $value) {
                if (is_int($value) && $name !== 'failures' && $value !== 0) {
                    $state[$name] = $value + $seconds;
                }
            }
            file_put_contents($file, json_encode($state));
        }
    }

    /**
     * @return list<string>
     */
    private function stateFiles(): array
    {
        $files = glob($this->directory->path('breaker/*.breaker'));

        return $files === false ? [] : $files;
    }
}
