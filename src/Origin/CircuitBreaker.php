<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * Stops origin fetches for a while once an origin keeps failing, so that a
 * failing origin is not hammered and the PHP workers are not all held by
 * fetches that time out. Keyed by the origin (finding P-14: 2.x kept one
 * breaker per file, so a failing origin was contacted again for every
 * other path).
 */
interface CircuitBreaker
{
    /**
     * Whether fetches from $origin are currently refused.
     *
     * @param string $origin the scheme, host and port of the origin
     */
    public function isOpen(string $origin): bool;

    /**
     * Counts a fetch that failed because $origin is unavailable.
     */
    public function recordFailure(string $origin): void;

    /**
     * Closes the breaker of $origin once the origin answered: a success,
     * or any failure that does not mean it is unavailable.
     */
    public function reset(string $origin): void;
}
