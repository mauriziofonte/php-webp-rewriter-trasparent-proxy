<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Limits;

use Mfonte\ProxyCdn\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface as LibraryException;

/**
 * A RateLimitStore of bounded size, for keys that come from the traffic
 * (one per client address), shared by every PHP process of the host
 * without APCu.
 *
 * The keys are spread over SLOTS files, by their first three hexadecimal
 * digits ("slot-<xyz>.state"), each holding at most ENTRIES_PER_SLOT keys
 * with their values, read and written under an exclusive flock() of the
 * file. When a slot is full, the key with the smallest value leaves it:
 * for RateLimiter that is the client that has been idle the longest,
 * whose bucket is full again, or nearly. So however many addresses send
 * requests, the store never holds more than SLOTS files of a few hundred
 * bytes, and a flood of addresses can at worst make the limiter forget a
 * busy client (which then starts again with a full bucket): it never makes
 * one client pay for another.
 *
 * The directory (var/ratelimit of the project) is checked by the library's
 * LockDirectory on first use: private to the PHP user, so that no other
 * user can plant or change a state file. check-env reports a refused one.
 */
final class SlotRateLimitStore implements RateLimitStore
{
    /**
     * Number of slot files: 16^3.
     */
    public const SLOTS = 4096;

    /**
     * Keys a slot holds at most.
     */
    public const ENTRIES_PER_SLOT = 8;

    /**
     * @var string
     */
    private $path;

    /**
     * @var string|null the resolved directory, once checked
     */
    private $directory;

    /**
     * @param string $path absolute path of the directory; created with mode 0700 when missing
     */
    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * @throws InvalidArgumentException when the key is not 32 lower-case hexadecimal digits
     */
    public function update(string $key, callable $decide): bool
    {
        if (preg_match('/\A[0-9a-f]{32}\z/', $key) !== 1) {
            throw new InvalidArgumentException('A rate limit key must be 32 lower-case hexadecimal digits.');
        }
        $directory = $this->directory();
        if ($directory === null) {
            return false;
        }
        $file = $directory . '/slot-' . substr($key, 0, 3) . '.state';
        clearstatcache(true, $file);
        if (is_link($file)) {
            return false;
        }
        $handle = @fopen($file, 'c+b');
        if ($handle === false) {
            return false;
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                return false;
            }
            $entries = self::decode(@stream_get_contents($handle, 4096, 0));
            $next = $decide($entries[$key] ?? null);
            if ($next === null) {
                @flock($handle, LOCK_UN);

                return true;
            }
            $entries[$key] = $next;
            while (count($entries) > self::ENTRIES_PER_SLOT) {
                $others = $entries;
                unset($others[$key]);
                asort($others);
                unset($entries[(string) key($others)]);
            }
            $written = @ftruncate($handle, 0) && @rewind($handle) && @fwrite($handle, self::encode($entries)) !== false && @fflush($handle);
            @flock($handle, LOCK_UN);

            return $written;
        } finally {
            @fclose($handle);
        }
    }

    private function directory(): ?string
    {
        if ($this->directory === null) {
            try {
                $this->directory = (new LockDirectory($this->path))->getPath();
            } catch (LibraryException $exception) {
                return null;
            }
        }

        return $this->directory;
    }

    /**
     * The entries of a slot file: "<key> <value>" lines; malformed lines
     * are dropped.
     *
     * @param string|false $contents
     *
     * @return array<string, int>
     */
    private static function decode($contents): array
    {
        $entries = [];
        if (!is_string($contents)) {
            return $entries;
        }
        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/\A([0-9a-f]{32}) (-?[0-9]{1,19})\z/', $line, $match) === 1) {
                $entries[$match[1]] = (int) $match[2];
            }
        }

        return array_slice($entries, 0, self::ENTRIES_PER_SLOT, true);
    }

    /**
     * @param array<string, int> $entries
     */
    private static function encode(array $entries): string
    {
        $lines = '';
        foreach ($entries as $key => $value) {
            $lines .= $key . ' ' . $value . "\n";
        }

        return $lines;
    }
}
