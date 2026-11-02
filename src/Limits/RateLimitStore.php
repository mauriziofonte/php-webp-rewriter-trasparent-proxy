<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Limits;

/**
 * Keeps one integer per key for RateLimiter and updates it atomically:
 * concurrent requests of one key, in any PHP process that shares the
 * store, never read the same value and both write after it.
 */
interface RateLimitStore
{
    /**
     * Calls $decide with the value stored for $key (null when there is
     * none) and stores what it returns, unless it returns null, atomically
     * for the key.
     *
     * @param string                   $key    32 lower-case hexadecimal digits
     * @param callable(int|null): ?int $decide must not have other effects than its result
     *
     * @return bool false when the store could not do the update
     */
    public function update(string $key, callable $decide): bool;
}
