<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * One circuit breaker per origin, kept in a state file of its own below
 * the state directory (var/breaker/ of the project) and updated under an
 * exclusive lock, so that every PHP worker sees the same state.
 *
 * - Closed: fetches go through. Each failure that means the origin is
 *   unavailable is counted; a success, or a pause longer than the open
 *   time since the last failure, starts the count again.
 * - Open: after CIRCUIT_BREAKER_THRESHOLD consecutive failures, fetches
 *   are refused for NETWORK_ERROR_TTL seconds, for every path of the
 *   origin.
 * - Half-open: once the open time is over, one request is let through as
 *   a probe (the others keep being refused while it runs, up to the open
 *   time again); any answer of the origin but unavailability closes the
 *   breaker (a 404 too: the origin is up), unavailability opens it again
 *   at once.
 *
 * When the state cannot be read or written the breaker stays closed: the
 * proxy then behaves as without a breaker rather than refusing to serve.
 */
final class OriginCircuitBreaker implements CircuitBreaker
{
    private const SUFFIX = '.breaker';

    /**
     * @var string
     */
    private $directory;

    /**
     * @var int
     */
    private $openSeconds;

    /**
     * @var int
     */
    private $threshold;

    /**
     * @param string $directory   the state directory of the breakers, outside the document root
     * @param int    $openSeconds seconds the breaker stays open (NETWORK_ERROR_TTL); 0 disables it
     * @param int    $threshold   consecutive failures that open it (CIRCUIT_BREAKER_THRESHOLD), at least 1
     */
    public function __construct(string $directory, int $openSeconds, int $threshold)
    {
        $this->directory = rtrim($directory, '/');
        $this->openSeconds = $openSeconds;
        $this->threshold = max(1, $threshold);
    }

    public function isOpen(string $origin): bool
    {
        if ($this->openSeconds <= 0 || !is_file($this->fileOf($origin))) {
            return false;
        }

        $open = false;
        $this->update($origin, function (array $state, int $now) use (&$open): array {
            if ($state['opened_at'] === 0) {
                return $state;
            }
            if ($now - $state['opened_at'] < $this->openSeconds) {
                $open = true;

                return $state;
            }
            // Half-open: one probe at a time.
            if ($state['probe_at'] !== 0 && $now - $state['probe_at'] < $this->openSeconds) {
                $open = true;

                return $state;
            }
            $state['probe_at'] = $now;

            return $state;
        });

        return $open;
    }

    public function recordFailure(string $origin): void
    {
        if ($this->openSeconds <= 0) {
            return;
        }
        $this->update($origin, function (array $state, int $now): array {
            $halfOpen = $state['opened_at'] !== 0;
            if (!$halfOpen && $state['last_failure_at'] !== 0 && $now - $state['last_failure_at'] > $this->openSeconds) {
                $state['failures'] = 0;
            }
            ++$state['failures'];
            $state['last_failure_at'] = $now;
            if ($halfOpen || $state['failures'] >= $this->threshold) {
                $state['opened_at'] = $now;
                $state['probe_at'] = 0;
            }

            return $state;
        });
    }

    public function reset(string $origin): void
    {
        $file = $this->fileOf($origin);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Reads, changes and writes the state of $origin under an exclusive
     * lock.
     *
     * @param callable(array{failures: int, last_failure_at: int, opened_at: int, probe_at: int}, int): array{failures: int, last_failure_at: int, opened_at: int, probe_at: int} $change
     */
    private function update(string $origin, callable $change): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            return;
        }
        $handle = @fopen($this->fileOf($origin), 'c+');
        if ($handle === false) {
            return;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            $before = self::decode((string) stream_get_contents($handle));
            $after = $change($before, time());
            if ($after !== $before) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string) json_encode($after));
                fflush($handle);
            }
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{failures: int, last_failure_at: int, opened_at: int, probe_at: int}
     */
    private static function decode(string $contents): array
    {
        $state = ['failures' => 0, 'last_failure_at' => 0, 'opened_at' => 0, 'probe_at' => 0];
        $decoded = json_decode($contents, true);
        if (is_array($decoded)) {
            foreach (array_keys($state) as $name) {
                if (isset($decoded[$name]) && is_int($decoded[$name]) && $decoded[$name] >= 0) {
                    $state[$name] = $decoded[$name];
                }
            }
        }

        return $state;
    }

    private function fileOf(string $origin): string
    {
        return $this->directory . '/' . substr(hash('sha256', strtolower($origin)), 0, 16) . self::SUFFIX;
    }
}
