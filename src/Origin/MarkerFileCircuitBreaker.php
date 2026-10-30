<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

use Mfonte\ProxyCdn\Cache\CacheStorage;

/**
 * The 2.x breaker: an empty "<cache file>.netfail" marker whose
 * modification time opens the breaker for that file for a number of
 * seconds (NETWORK_ERROR_TTL). The key is the cache file.
 */
final class MarkerFileCircuitBreaker implements CircuitBreaker
{
    public const SUFFIX = '.netfail';

    /**
     * @var CacheStorage
     */
    private $storage;

    /**
     * @var int
     */
    private $ttl;

    /**
     * @param int $ttl seconds the breaker stays open after a failure
     */
    public function __construct(CacheStorage $storage, int $ttl)
    {
        $this->storage = $storage;
        $this->ttl = $ttl;
    }

    public function isOpen(string $key): bool
    {
        $marker = $key . self::SUFFIX;
        if (!$this->storage->isFile($marker)) {
            return false;
        }

        $openedAt = $this->storage->modifiedAt($marker);

        return $openedAt !== null && time() - $openedAt < $this->ttl;
    }

    /**
     * Creates or refreshes the marker, creating its directory first.
     */
    public function recordFailure(string $key): void
    {
        $this->storage->ensureDirectory(dirname($key));
        $this->storage->touch($key . self::SUFFIX);
    }

    public function reset(string $key): void
    {
        $this->storage->delete($key . self::SUFFIX);
    }
}
