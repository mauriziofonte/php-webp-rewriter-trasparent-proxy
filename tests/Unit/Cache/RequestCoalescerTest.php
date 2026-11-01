<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Cache;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Cache\RequestCoalescer;
use Mfonte\ProxyCdn\Http\ResponseFactory;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\SecureImageConverter\Concurrency\Backoff;
use Mfonte\SecureImageConverter\Concurrency\KeyedLock;
use Mfonte\SecureImageConverter\Concurrency\KeyedLockInterface;
use Mfonte\SecureImageConverter\Concurrency\LeaseInterface;
use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The cross-process behaviour is proved by
 * Integration\Concurrency\RequestCoalescingTest; these tests hold the lock
 * of the library in this process (a second KeyedLock on the same
 * directory opens its own file description, so flock() sees two holders).
 *
 * @covers \Mfonte\ProxyCdn\Cache\RequestCoalescer
 */
#[CoversClass(RequestCoalescer::class)]
final class RequestCoalescerTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var MemoryLogger
     */
    private $logger;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->logger = new MemoryLogger();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testTheFirstRequestLeads(): void
    {
        $coalescer = $this->coalescer($this->lock(), 1.0);
        $coalescer->recordFailure('key', ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE);

        $lease = $coalescer->enter('key', static function (): bool {
            return false;
        });

        self::assertInstanceOf(LeaseInterface::class, $lease);
        self::assertNull($coalescer->recentFailure('key'), 'the failure of an earlier leader is not for its waiters');
        self::assertNull($coalescer->tryEnter('key'), 'held');
        $lease->release();
        self::assertTrue($coalescer->isEnabled());
    }

    public function testAWaiterReturnsAsSoonAsTheEntryIsReadyWithoutTheLock(): void
    {
        $leader = $this->lock()->tryAcquire('key');
        self::assertNotNull($leader);
        $checks = 0;

        $lease = ($this->coalescer($this->lock(), 5.0))->enter('key', static function () use (&$checks): bool {
            return ++$checks === 3;
        });

        self::assertNull($lease, 'it looks for the entry again');
        self::assertSame(3, $checks);
        self::assertFalse($leader->isReleased(), 'the leader keeps its lock');
        $leader->release();
    }

    public function testAWaiterWhoseLeaderLeftWithoutOutcomeBecomesTheLeader(): void
    {
        $lock = new class () implements KeyedLockInterface {
            /** @var int */
            public $attempts = 0;

            /** @var list<LeaseInterface> */
            public $leases = [];

            public function acquire(string $key, float $timeout): LeaseInterface
            {
                throw new \LogicException('not used');
            }

            public function tryAcquire(string $key): ?LeaseInterface
            {
                // Held by a leader for two attempts, then free.
                if (++$this->attempts < 3) {
                    return null;
                }
                $lease = new class () implements LeaseInterface {
                    /** @var bool */
                    private $released = false;

                    public function release(): void
                    {
                        $this->released = true;
                    }

                    public function isReleased(): bool
                    {
                        return $this->released;
                    }
                };
                $this->leases[] = $lease;

                return $lease;
            }
        };

        $lease = ($this->coalescer($lock, 5.0, new Backoff(0.001, 0.001)))->enter('key', static function (): bool {
            return false;
        });

        self::assertCount(1, $lock->leases);
        self::assertSame($lock->leases[0], $lease, 'it keeps the lock: one fetch in flight');
        self::assertFalse($lock->leases[0]->isReleased());
    }

    public function testAWaitIsBounded(): void
    {
        $leader = $this->lock()->tryAcquire('key');
        self::assertNotNull($leader);

        $started = microtime(true);
        $lease = ($this->coalescer($this->lock(), 0.2))->enter('key', static function (): bool {
            return false;
        });

        self::assertFalse($lease, 'it answers without fetching');
        self::assertLessThan(2.0, microtime(true) - $started);
        self::assertSame('request coalescing timed out', $this->logger->getRecords()[0]['message'] ?? null);
        $leader->release();
    }

    public function testWithoutALockEveryRequestFetches(): void
    {
        $coalescer = $this->coalescer(null, 1.0);

        self::assertNull($coalescer->enter('key', static function (): bool {
            return false;
        }));
        self::assertNull($coalescer->tryEnter('key'));
        self::assertFalse($coalescer->isEnabled());
    }

    public function testALockErrorIsLoggedAndTheRequestFetches(): void
    {
        $lock = new class () implements KeyedLockInterface {
            public function acquire(string $key, float $timeout): LeaseInterface
            {
                throw new InvalidArgumentException('broken');
            }

            public function tryAcquire(string $key): ?LeaseInterface
            {
                throw new InvalidArgumentException('broken');
            }
        };
        $coalescer = $this->coalescer($lock, 1.0);

        self::assertNull($coalescer->enter('key', static function (): bool {
            return false;
        }));
        self::assertNull($coalescer->tryEnter('key'));
        $records = $this->logger->getRecords();
        self::assertCount(2, $records);
        self::assertSame('request coalescing unavailable', $records[0]['message']);
        self::assertSame('warning', $records[0]['level']);
    }

    public function testAWaiterReturnsWhenTheLeaderRecordsAFailure(): void
    {
        $leader = $this->lock()->tryAcquire('key');
        self::assertNotNull($leader);
        $leading = $this->coalescer($this->lock(), 5.0);
        $checks = 0;

        $lease = $this->coalescer($this->lock(), 5.0)->enter('key', static function () use (&$checks, $leading): bool {
            if (++$checks === 2) {
                $leading->recordFailure('key', ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE);
            }

            return false;
        });

        self::assertNull($lease, 'it answers with the failure');
        self::assertSame(2, $checks);
        self::assertFalse($leader->isReleased());
        $leader->release();
    }

    public function testOutcomesHoldForTheirWindowAndPerKey(): void
    {
        $coalescer = $this->coalescer($this->lock(), 1.0);
        self::assertNull($coalescer->recentFailure('key'));
        self::assertFalse($coalescer->isRecentlyUncacheable('key'));

        $coalescer->recordFailure('key', ResponseFactory::ERROR_ORIGIN_FORBIDDEN);
        self::assertSame(ResponseFactory::ERROR_ORIGIN_FORBIDDEN, $coalescer->recentFailure('key'));
        self::assertFalse($coalescer->isRecentlyUncacheable('key'));
        self::assertNull($coalescer->recentFailure('other'));

        $coalescer->recordUncacheable('key');
        self::assertNull($coalescer->recentFailure('key'));
        self::assertTrue($coalescer->isRecentlyUncacheable('key'));

        $file = $this->directory->path('outcomes/' . substr(hash('sha256', 'key'), 0, 3) . '.outcome');
        self::assertFileExists($file);
        touch($file, time() - RequestCoalescer::OUTCOME_WINDOW);
        clearstatcache();
        self::assertFalse($coalescer->isRecentlyUncacheable('key'), 'expired');

        $coalescer->recordFailure('key', ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE);
        $coalescer->forget('key');
        self::assertNull($coalescer->recentFailure('key'));
        self::assertFileDoesNotExist($file);
    }

    /**
     * The outcomes stay bounded, as the markers of NegativeCache: one slot
     * file per three hexadecimal digits of the hash of the key, whatever
     * the clients ask; a key that shares a slot replaces the outcome of the
     * other, and forgetting a key leaves the outcome of the other.
     */
    public function testOutcomesLiveInABoundedNumberOfSlots(): void
    {
        $coalescer = $this->coalescer($this->lock(), 1.0);
        for ($i = 0; $i < 5000; ++$i) {
            $coalescer->recordFailure('/path/' . $i, ResponseFactory::ERROR_ORIGIN_FORBIDDEN);
        }

        $files = glob($this->directory->path('outcomes/*')) ?: [];
        self::assertLessThanOrEqual(4096, count($files));
        self::assertGreaterThan(2000, count($files), 'about 4096 * (1 - e^(-5000/4096)) slots in use');
        self::assertSame(ResponseFactory::ERROR_ORIGIN_FORBIDDEN, $coalescer->recentFailure('/path/4999'));

        list($first, $second) = self::keysOfOneSlot();
        $coalescer->recordFailure($first, ResponseFactory::ERROR_BAD_GATEWAY);
        $coalescer->recordUncacheable($second);
        self::assertNull($coalescer->recentFailure($first), 'replaced by the other key');
        self::assertTrue($coalescer->isRecentlyUncacheable($second));
        $coalescer->forget($first);
        self::assertTrue($coalescer->isRecentlyUncacheable($second), 'not the outcome of another key');
    }

    /**
     * A redirect is shared with its status and target; a target that
     * could not be one line of the slot is not recorded.
     */
    public function testARedirectIsSharedWithItsTarget(): void
    {
        $coalescer = $this->coalescer($this->lock(), 1.0);
        self::assertNull($coalescer->recentRedirect('key'));

        $coalescer->recordRedirect('key', 302, '/css/moved.css?v=2');
        self::assertSame([302, '/css/moved.css?v=2'], $coalescer->recentRedirect('key'));
        self::assertNull($coalescer->recentFailure('key'));
        self::assertNull($coalescer->recentRedirect('other'));

        $coalescer->recordRedirect('spaced', 301, "/a b\n");
        self::assertNull($coalescer->recentRedirect('spaced'));
        $coalescer->recordFailure('key', ResponseFactory::ERROR_ORIGIN_NOT_FOUND);
        self::assertNull($coalescer->recentRedirect('key'), 'replaced');
    }

    public function testWithoutALockNoOutcomeIsRecorded(): void
    {
        $coalescer = $this->coalescer(null, 1.0);
        $coalescer->recordFailure('key', ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE);
        $coalescer->recordUncacheable('other');

        self::assertNull($coalescer->recentFailure('key'));
        self::assertFalse($coalescer->isRecentlyUncacheable('other'));
        self::assertDirectoryDoesNotExist($this->directory->path('outcomes'));
    }

    /**
     * Two keys whose hashes share their first three hexadecimal digits.
     *
     * @return array{string, string}
     */
    private static function keysOfOneSlot(): array
    {
        $seen = [];
        for ($i = 0;; ++$i) {
            $key = 'collision-' . $i;
            $slot = substr(hash('sha256', $key), 0, 3);
            if (isset($seen[$slot])) {
                return [$seen[$slot], $key];
            }
            $seen[$slot] = $key;
        }
    }

    private function coalescer(?KeyedLockInterface $lock, float $timeout, ?Backoff $backoff = null): RequestCoalescer
    {
        return new RequestCoalescer($lock, $timeout, $this->logger, new FilesystemStorage(), $this->directory->path('outcomes'), RequestCoalescer::OUTCOME_WINDOW, $backoff);
    }

    private function lock(): KeyedLock
    {
        return new KeyedLock(new LockDirectory($this->directory->path('locks')), RequestCoalescer::LOCK_NAME);
    }
}
