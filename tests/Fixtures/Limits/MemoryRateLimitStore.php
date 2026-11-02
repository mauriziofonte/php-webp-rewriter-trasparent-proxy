<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Limits;

use Mfonte\ProxyCdn\Limits\RateLimitStore;

/**
 * An in-memory RateLimitStore.
 */
final class MemoryRateLimitStore implements RateLimitStore
{
    /**
     * @var array<string, int>
     */
    private $values = [];

    public function update(string $key, callable $decide): bool
    {
        $next = $decide($this->values[$key] ?? null);
        if ($next !== null) {
            $this->values[$key] = $next;
        }

        return true;
    }
}
