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
 * At most one fetch per entry is in flight, also when the leader fails:
 * - a leader that the origin failed records the failure, and its waiters
 *   answer with it instead of fetching in turn; the record holds for
 *   OUTCOME_WINDOW seconds, and the next leader clears it;
 * - a leader whose response the origin forbids storing (no-store,
 *   private) records that, and for OUTCOME_WINDOW seconds the requests of
 *   the entry fetch without the lock, in parallel: each needs its own
 *   response, and waiting for one another would only serialize them;
 * - a leader that left no outcome (it crashed, or the entry was purged
 *   meanwhile) hands the lock to the first waiter that takes it, which
 *   becomes the leader;
 * - a waiter that waited longer than the timeout is told so, and answers
 *   with an error instead of fetching.
 * The outcomes are small files of a directory of the state directory.
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
     * @param KeyedLockInterface|null $lock             null to never coalesce
     * @param float                   $timeout          the longest wait for a leader, in seconds: above the time a
     *                                                  fetch may take (UPSTREAM_TIMEOUT)
     * @param string                  $outcomeDirectory the directory of the outcomes the leaders record
     * @param int                     $outcomeWindow    seconds during which a recorded outcome holds
     */
    public function __construct(
        ?KeyedLockInterface $lock,
        float $timeout,
        LoggerInterface $logger,
        CacheStorage $storage,
        string $outcomeDirectory,
        int $outcomeWindow = self::OUTCOME_WINDOW,
        ?Backoff $backoff = null
    ) {
        $this->lock = $lock;
        $this->timeout = max(0.0, min($timeout, Backoff::MAX_TIMEOUT));
        $this->logger = $logger;
        $this->storage = $storage;
        $this->outcomeDirectory = rtrim($outcomeDirectory, '/');
        $this->outcomeWindow = max(1, $outcomeWindow);
        $this->backoff = $backoff ?? new Backoff(0.005, 0.1);
    }

    /**
     * Waits until this request may fetch the entry named $key.
     *
     * @param string           $key   the entry (CacheLocation::getKey())
     * @param callable(): bool $ready whether another request has made the entry available
     *
     * @return LeaseInterface|false|null the lock to release once the entry is stored, when this request leads; null
     *                                   when it must look for the entry, and for an outcome, again; false when it
     *                                   waited longer than the timeout
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
                // A new leader: the outcome of an earlier one is not for its waiters.
                $this->forget($key);

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

            return null;
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
            $this->storage->write($this->outcomeFile($key), self::FAILED . ' ' . $error . "\n");
        }
    }

    /**
     * Records that the origin forbids storing the response of $key.
     */
    public function recordUncacheable(string $key): void
    {
        if ($this->lock !== null) {
            $this->storage->write($this->outcomeFile($key), self::UNCACHEABLE . " 0\n");
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
     * Whether a leader found, within the window, that the origin forbids
     * storing the response of $key.
     */
    public function isRecentlyUncacheable(string $key): bool
    {
        $outcome = $this->outcomeOf($key);

        return $outcome !== null && $outcome[0] === self::UNCACHEABLE;
    }

    /**
     * Forgets the outcome recorded for $key: the entry is stored.
     */
    public function forget(string $key): void
    {
        $file = $this->outcomeFile($key);
        if ($this->lock !== null && $this->storage->isFile($file)) {
            $this->storage->delete($file);
        }
    }

    /**
     * The outcome recorded for $key within the window, as its kind and
     * error; null when there is none.
     *
     * @return array{string, int}|null
     *
     * @phpstan-impure
     */
    private function outcomeOf(string $key): ?array
    {
        if ($this->lock === null) {
            return null;
        }
        $file = $this->outcomeFile($key);
        $contents = $this->storage->read($file);
        if ($contents === null || preg_match('/\A(failed|uncacheable) ([0-9]{1,9})\n\z/', $contents, $match) !== 1) {
            return null;
        }
        $recordedAt = $this->storage->modifiedAt($file);
        if ($recordedAt === null || time() - $recordedAt >= $this->outcomeWindow) {
            return null;
        }

        return [$match[1], (int) $match[2]];
    }

    private function outcomeFile(string $key): string
    {
        return $this->outcomeDirectory . '/' . hash('sha256', $key) . '.outcome';
    }
}
