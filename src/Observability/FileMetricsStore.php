<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Observability;

use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface as LibraryException;

/**
 * Keeps the counters in JSON files of a private directory, read and written
 * under an flock() of each file, so that every PHP process of the host
 * shares the counters, as the conversion API's FileMetricsStore does.
 *
 * The counters are split over SHARDS files ("metrics.json", then
 * "metrics-1.json" to "metrics-7.json"), summed when they are read: one
 * update locks one file, the one of its process ID first, else the first
 * free one, and waits only when all are busy. With a single file every
 * PHP-FPM worker of a busy host queued behind one lock after each request,
 * which cost up to a third of the hits PHP serves per second (PRX-10). One
 * update costs one locked read and write of a file of a few kilobytes: the
 * label sets of Metrics are bounded, so are the files, and so is their
 * number, since their names are fixed.
 *
 * The directory (var/metrics of the project) is checked by the library's
 * LockDirectory on first use: private to the PHP user, so that no other
 * user can change the counters. check-env reports a refused directory.
 */
final class FileMetricsStore implements MetricsStore
{
    /** The first file, the only one before the counters were split. */
    public const FILE_NAME = 'metrics.json';

    /** How many files the counters are split over. */
    public const SHARDS = 8;

    /**
     * @var string
     */
    private $path;

    /**
     * @var string|null the directory, once it is checked
     */
    private $directory;

    /**
     * @param string $path absolute path of the directory; created with mode 0700 when missing
     */
    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function add(array $increments): bool
    {
        $first = (int) getmypid() % self::SHARDS;
        $handle = null;
        for ($i = 0; $i < self::SHARDS && $handle === null; ++$i) {
            $handle = $this->lock(($first + $i) % self::SHARDS, LOCK_EX | LOCK_NB);
        }
        // Every file is busy: wait for the first one.
        $handle = $handle ?? $this->lock($first, LOCK_EX);
        if ($handle === null) {
            return false;
        }

        try {
            $counters = self::decode(@stream_get_contents($handle, -1, 0));
            foreach ($increments as $name => $amount) {
                $counters[$name] = ($counters[$name] ?? 0) + $amount;
            }
            $json = json_encode($counters, JSON_UNESCAPED_SLASHES);
            $written = is_string($json) && @ftruncate($handle, 0) && @rewind($handle) && @fwrite($handle, $json) !== false && @fflush($handle);
            @flock($handle, LOCK_UN);

            return $written;
        } finally {
            @fclose($handle);
        }
    }

    public function read(): ?array
    {
        $directory = $this->directory();
        if ($directory === null) {
            return null;
        }
        $counters = [];
        for ($shard = 0; $shard < self::SHARDS; ++$shard) {
            $file = $directory . '/' . self::fileName($shard);
            clearstatcache(true, $file);
            if (!file_exists($file) && !is_link($file)) {
                continue;
            }
            $handle = $this->lock($shard, LOCK_SH);
            if ($handle === null) {
                return null;
            }

            try {
                foreach (self::decode(@stream_get_contents($handle, -1, 0)) as $name => $value) {
                    $counters[$name] = ($counters[$name] ?? 0) + $value;
                }
                @flock($handle, LOCK_UN);
            } finally {
                @fclose($handle);
            }
        }

        return $counters;
    }

    /**
     * The file of a shard, opened (created when missing) and locked.
     *
     * @param int<0, 7> $operation LOCK_SH or LOCK_EX, with LOCK_NB not to wait
     *
     * @return resource|null null when it cannot be opened, or locked without waiting under LOCK_NB
     */
    private function lock(int $shard, int $operation)
    {
        $directory = $this->directory();
        if ($directory === null) {
            return null;
        }
        $file = $directory . '/' . self::fileName($shard);
        clearstatcache(true, $file);
        if (is_link($file)) {
            return null;
        }
        $handle = @fopen($file, 'c+b');
        if ($handle === false) {
            return null;
        }
        if (!@flock($handle, $operation)) {
            @fclose($handle);

            return null;
        }

        return $handle;
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
     * The name of the file of a shard, from 0 to SHARDS - 1.
     */
    public static function fileName(int $shard): string
    {
        return $shard === 0 ? self::FILE_NAME : 'metrics-' . $shard . '.json';
    }

    /**
     * The counters of the file; empty when it is empty or damaged.
     *
     * @param string|false $json
     *
     * @return array<string, int>
     */
    private static function decode($json): array
    {
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($data)) {
            return [];
        }
        $counters = [];
        foreach ($data as $name => $value) {
            if (is_string($name) && is_int($value)) {
                $counters[$name] = $value;
            }
        }

        return $counters;
    }
}
