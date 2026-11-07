<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cache;

use Mfonte\SecureImageConverter\Concurrency\Backoff;
use Mfonte\SecureImageConverter\Concurrency\KeyedLockInterface;
use Mfonte\SecureImageConverter\Concurrency\LeaseInterface;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Request coalescing (finding P-24): of the requests that miss the same
 * entry at the same time, one fetches it from the origin while the others
 * wait for it, so that a burst on a cold key costs one origin fetch.
 *
 * The leader holds the per-entry lock of the library (KeyedLock in a
 * LockDirectory: flock() on a file of a directory that only the proxy user
 * can write) while it fetches and stores. A request that finds the lock
 * held polls, with the Backoff of the library, until the entry is ready,
 * the leader has left an outcome, or the lock is free, and never holds the
 * lock just to look: a burst is not serialized behind it.
 *
 * At most one fetch per entry is in flight, also when the leader stores
 * nothing:
 * - a leader that the origin failed, or answered with a 403, 404 or 410,
 *   records the error it answered, and its waiters answer with it instead
 *   of fetching in turn; the record holds for OUTCOME_WINDOW seconds, for
 *   every request of the entry: one that finds the lock free meanwhile (it
 *   came late, after the leader left) answers with it as well, so the
 *   origin sees at most one fetch per window, however the burst spreads;
 * - a leader that the origin redirected records the redirect it passed
 *   on, and its waiters pass on the same one, for the same window;
 * - a leader whose response the origin forbids storing (no-store,
 *   private) records that, and for OUTCOME_WINDOW seconds the requests of
 *   the entry fetch without the lock, in parallel: each needs its own
 *   response, and waiting for one another would only serialize them;
 * - a leader that left no outcome (it crashed, or the entry was purged
 *   meanwhile) hands the lock to the first waiter that takes it, which
 *   becomes the leader;
 * - a waiter that waited longer than the timeout is told so, and answers
 *   with an error instead of fetching.
 * The outcomes are small files of a directory of the state directory, in
 * a bounded number of slots, as NegativeCache keeps its markers: the first
 * SLOT_HEX_DIGITS hexadecimal digits of the SHA-256 of the key name the
 * slot, so at most 4096 outcome files exist whatever the clients ask, and
 * none needs cleaning. A slot holds the outcome and the full hash of its
 * key; two keys that share a slot replace each other's outcome, which only
 * costs the waiters of the other key one more origin fetch.
 *
 * Without a lock directory (it could not be created safely, or the library
 * reports an error) every request fetches by itself, as in 2.x, and the
 * failure is logged. PRX-8 uses the same lock for conversions.
 */
final class RequestCoalescer
{
    /**
     * The name of the keyed lock of origin fetches in the lock directory.
     */
    public const LOCK_NAME = 'origin-fetch';

    /**
     * Seconds during which the outcome a leader recorded holds.
     */
    public const OUTCOME_WINDOW = 5;

    private const UNCACHEABLE = 'uncacheable';

    private const FAILED = 'failed';

    private const REDIRECTED = 'redirect';

    /**
     * Hexadecimal digits of the hash that name a slot: 16^3 = 4096 slots.
     */
    private const SLOT_HEX_DIGITS = 3;

    private const SLOT_SUFFIX = '.outcome';

    /**
     * @var KeyedLockInterface|null
     */
    private $lock;

    /**
     * @var float
     */
    private $timeout;

    /**
     * @var CacheStorage
     */
    private $storage;

    /**
     * @var string
     */
    private $outcomeDirectory;

    /**
     * @var int
     */
    private $outcomeWindow;

    /**
     * @var Backoff
     */
    private $backoff;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var callable(): int
     */
    private $clock;

    /**
     * @param KeyedLockInterface|null $lock             null to never coalesce
     * @param float                   $timeout          the longest wait for a leader, in seconds: above the time a
     *                                                  fetch may take (UPSTREAM_TIMEOUT)
     * @param string                  $outcomeDirectory the directory of the outcomes the leaders record
     * @param int                     $outcomeWindow    seconds during which a recorded outcome holds
     * @param (callable(): int)|null  $clock            the current Unix time; time() when null
     */
    public function __construct(
        ?KeyedLockInterface $lock,
        float $timeout,
        LoggerInterface $logger,
        CacheStorage $storage,
        string $outcomeDirectory,
        int $outcomeWindow = self::OUTCOME_WINDOW,
        ?Backoff $backoff = null,
        ?callable $clock = null
    ) {
        $this->lock = $lock;
        $this->timeout = max(0.0, min($timeout, Backoff::MAX_TIMEOUT));
        $this->logger = $logger;
        $this->storage = $storage;
        $this->outcomeDirectory = rtrim($outcomeDirectory, '/');
        $this->outcomeWindow = max(1, $outcomeWindow);
        $this->backoff = $backoff ?? new Backoff(0.005, 0.1);
        $this->clock = $clock ?? 'time';
    }

    /**
     * Waits until this request may fetch the entry named $key.
     *
     * @param string           $key   the entry (CacheLocation::getKey())
     * @param callable(): bool $ready whether another request has made the entry available
     *
     * A request told to look again that finds neither the entry nor an
     * outcome (it expired, was purged or was replaced in its slot in
     * between) enters again rather than fetch: another request may lead by
     * then.
     *
     * @return LeaseInterface|false|null the lock to release once the entry is stored, when this request leads (a
     *                                   lease that holds nothing when the lock fails: the request fetches by itself);
     *                                   null when it must look for the entry, and for an outcome, again, or without
     *                                   a lock; false when it waited longer than the timeout
     */
    public function enter(string $key, callable $ready)
    {
        if ($this->lock === null) {
            return null;
        }
        $lock = $this->lock;
        try {
            $lease = $lock->tryAcquire($key);
            if ($lease !== null) {
                // A request that finds the lock free after a leader left an
                // outcome (it came late in the burst, or the origin keeps
                // failing) answers with that outcome too, until it expires:
                // leading would fetch again, and erasing it would send the
                // waiters still polling to the origin after it.
                if ($this->outcomeOf($key) !== null) {
                    $lease->release();

                    return null;
                }

                return $lease;
            }
            $outcome = $this->backoff->retryUntil(function () use ($lock, $key, $ready): ?object {
                if ($ready() || $this->outcomeOf($key) !== null) {
                    return new \stdClass();
                }
                $lease = $lock->tryAcquire($key);
                if ($lease !== null && $this->outcomeOf($key) !== null) {
                    // The leader recorded its outcome as this request last looked.
                    $lease->release();

                    return new \stdClass();
                }

                // Null while the leader works; else it left without an
                // outcome, and this request leads.
                return $lease;
            }, $this->timeout);
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('request coalescing unavailable', ['error' => $exception->getMessage()]);

            return self::unlocked();
        }
        if ($outcome === null) {
            $this->logger->warning('request coalescing timed out', ['timeout' => $this->timeout]);

            return false;
        }

        return $outcome instanceof LeaseInterface ? $outcome : null;
    }

    /**
     * Whether a lock is available, so that requests are coalesced.
     */
    public function isEnabled(): bool
    {
        return $this->lock !== null;
    }

    /**
     * Takes the lock of $key if it is free, without waiting: for work that
     * one request does for all and others skip, such as a background
     * revalidation. Null when it is held, or without a lock.
     */
    public function tryEnter(string $key): ?LeaseInterface
    {
        if ($this->lock === null) {
            return null;
        }
        try {
            return $this->lock->tryAcquire($key);
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('request coalescing unavailable', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * Records that the origin failed the fetch of $key, with the error
     * the leader answered (a ResponseFactory::ERROR_* constant).
     */
    public function recordFailure(string $key, int $error): void
    {
        if ($this->lock !== null) {
            $this->storage->write($this->slotOf($key), self::FAILED . ' ' . $error . ' ' . hash('sha256', $key) . "\n");
        }
    }

    /**
     * Records that the leader of $key answered with a redirect to $target,
     * a path and query string on the proxy without spaces or control
     * characters (ProxyKernel::proxyLocation()).
     */
    public function recordRedirect(string $key, int $status, string $target): void
    {
        if ($this->lock !== null && preg_match('/\A\S{1,4096}\z/', $target) === 1) {
            $this->storage->write($this->slotOf($key), self::REDIRECTED . ' ' . $status . ' ' . hash('sha256', $key) . ' ' . $target . "\n");
        }
    }

    /**
     * Records that the origin forbids storing the response of $key.
     */
    public function recordUncacheable(string $key): void
    {
        if ($this->lock !== null) {
            $this->storage->write($this->slotOf($key), self::UNCACHEABLE . ' 0 ' . hash('sha256', $key) . "\n");
        }
    }

    /**
     * The error a leader recorded for $key within the window; null when
     * there is none.
     */
    public function recentFailure(string $key): ?int
    {
        $outcome = $this->outcomeOf($key);

        return $outcome !== null && $outcome[0] === self::FAILED ? $outcome[1] : null;
    }

    /**
     * The status and target of the redirect a leader recorded for $key
     * within the window; null when there is none.
     *
     * @return array{int, string}|null
     */
    public function recentRedirect(string $key): ?array
    {
        $outcome = $this->outcomeOf($key);

        return $outcome !== null && $outcome[0] === self::REDIRECTED ? [$outcome[1], $outcome[2]] : null;
    }

    /**
     * Whether a leader found, within the window, that the origin forbids
     * storing the response of $key.
     */
    public function isRecentlyUncacheable(string $key): bool
    {
        $outcome = $this->outcomeOf($key);

        return $outcome !== null && $outcome[0] === self::UNCACHEABLE;
    }

    /**
     * Forgets the outcome recorded for $key: the entry is stored. The
     * outcome of another key that shares the slot is kept.
     */
    public function forget(string $key): void
    {
        if ($this->lock !== null && $this->recordedIn($key) !== null) {
            $this->storage->delete($this->slotOf($key));
        }
    }

    /**
     * The outcome recorded for $key within the window, as its kind, its
     * error or status, and the target of a redirect; null when there is
     * none.
     *
     * @return array{string, int, string}|null
     *
     * @phpstan-impure
     */
    private function outcomeOf(string $key): ?array
    {
        if ($this->lock === null) {
            return null;
        }
        $outcome = $this->recordedIn($key);
        $recordedAt = $outcome === null ? null : $this->storage->modifiedAt($this->slotOf($key));
        if ($recordedAt === null || ($this->clock)() - $recordedAt >= $this->outcomeWindow) {
            return null;
        }

        return $outcome;
    }

    /**
     * The outcome the slot of $key holds for $key, fresh or not; null when
     * it holds none or the one of another key.
     *
     * @return array{string, int, string}|null
     *
     * @phpstan-impure
     */
    private function recordedIn(string $key): ?array
    {
        $contents = $this->storage->read($this->slotOf($key));
        if ($contents === null || preg_match('/\A(failed|uncacheable|redirect) ([0-9]{1,9}) ([0-9a-f]{64})(?: (\S{1,4096}))?\n\z/', $contents, $match) !== 1) {
            return null;
        }
        $target = $match[4] ?? '';
        if ($match[3] !== hash('sha256', $key) || ($match[1] === self::REDIRECTED) !== ($target !== '')) {
            return null;
        }

        return [$match[1], (int) $match[2], $target];
    }

    /**
     * The lease of a request that leads without the lock, which failed.
     */
    private static function unlocked(): LeaseInterface
    {
        return new class () implements LeaseInterface {
            /**
             * @var bool
             */
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
    }

    private function slotOf(string $key): string
    {
        return $this->outcomeDirectory . '/' . substr(hash('sha256', $key), 0, self::SLOT_HEX_DIGITS) . self::SLOT_SUFFIX;
    }
}
