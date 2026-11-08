<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Limits;

/**
 * A token bucket per key: $perMinute requests per minute on average, up to
 * $burst at once after a pause. The algorithm of the conversion API's
 * RateLimiter (Mfonte\ImageConversionApi\Limits\RateLimiter).
 *
 * It is computed as the generic cell rate algorithm, which behaves as a
 * token bucket but keeps one integer per key, the theoretical arrival time
 * of the next request in microseconds, so that a store can update it with
 * one locked write. A request is allowed when that time is at most
 * (burst - 1) intervals ahead of now, and then pushes it one interval
 * further; a refused request changes nothing, so a client that keeps
 * hammering does not lock itself out beyond the refill. The wait it is
 * told (Retry-After) is the time until the next token, rounded up to whole
 * seconds.
 *
 * The arrival time is never pulled back towards now: when the wall clock
 * steps back by D, a key that spent its burst is refused for about D more
 * seconds, an error on the safe side.
 */
final class RateLimiter
{
    /**
     * @var RateLimitStore
     */
    private $store;

    /**
     * @var int microseconds between two tokens
     */
    private $interval;

    /**
     * @var int microseconds the arrival time may run ahead of now
     */
    private $tolerance;

    /**
     * @var callable(): int
     */
    private $clock;

    /**
     * @param int                    $perMinute requests per minute, at least 1
     * @param int                    $burst     requests at once, at least 1
     * @param (callable(): int)|null $clock     the current time in microseconds; microtime() when null
     */
    public function __construct(RateLimitStore $store, int $perMinute, int $burst, ?callable $clock = null)
    {
        $this->store = $store;
        $this->interval = max(1, intdiv(60000000, max(1, $perMinute)));
        $this->tolerance = $this->interval * (max(1, $burst) - 1);
        $this->clock = $clock ?? static function (): int {
            return (int) round(microtime(true) * 1000000);
        };
    }

    /**
     * Takes one token from the bucket of $key.
     *
     * @param string $key 32 lower-case hexadecimal digits that identify the client
     *
     * @return int|null 0 when the request is allowed, otherwise the seconds to wait, at least 1; null when the
     *                  store cannot update the bucket
     */
    public function consume(string $key): ?int
    {
        $now = ($this->clock)();
        $interval = $this->interval;
        $tolerance = $this->tolerance;
        $wait = 0;
        $decide = static function (?int $arrival) use ($now, $interval, $tolerance, &$wait): ?int {
            $arrival = $arrival === null ? $now : max($arrival, $now);
            if ($arrival - $now > $tolerance) {
                $wait = $arrival - $now - $tolerance;

                return null;
            }
            $wait = 0;

            return $arrival + $interval;
        };
        if (!$this->store->update($key, $decide)) {
            return null;
        }

        return $wait === 0 ? 0 : max(1, (int) ceil($wait / 1000000));
    }
}
