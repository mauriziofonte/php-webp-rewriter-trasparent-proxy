<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * Stops origin fetches for a while after a failure, so that a failing origin
 * is not hammered. Keyed by the resource as in 2.x; PRX-5 moves to one
 * breaker per origin (finding P-14).
 */
interface CircuitBreaker
{
    /**
     * Whether fetches for $key are currently refused.
     */
    public function isOpen(string $key): bool;

    /**
     * Opens the breaker for $key after a failed fetch.
     */
    public function recordFailure(string $key): void;

    /**
     * Closes the breaker for $key, after a successful fetch or a purge.
     */
    public function reset(string $key): void;
}
