<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Observability;

use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface as LibraryException;

/**
 * Keeps the counters in files of a private directory, shared by every PHP
 * process of the host, as the conversion API's FileMetricsStore does.
 *
 * The counters are split over SHARDS shards, summed when they are read:
 * one update takes one shard, the one of its process ID first, else the
 * first free one, and waits only when all are busy. With a single file
 * every PHP-FPM worker of a busy host queued behind one lock after each
 * request, which cost up to a third of the hits PHP serves per second.
 *
 * A shard is two slots, "metrics.json" and "metrics.alt.json" for the
 * first one ("metrics-1.json" and "metrics-1.alt.json" and so on for the
 * others), and a lock file ("metrics.lock" ...). Each slot holds a
 * sequence number and the counters, under a CRC-32 of both. Under an
 * flock() of the lock file, an update reads both slots, adds its
 * increments to the newer valid one and writes the result, with the next
 * sequence number, over the other one. A write that fails (a full disk)
 * or a writer that dies half way spoils only the slot being written,
 * whose checksum no longer matches: the other slot still holds the
 * counters, which are never lost, and Prometheus never sees them fall,
 * which it would count as a reset. The slots are written in place: a
 * file truncated or renamed over another costs ext4 a write-back of the
 * data on each update (auto_da_alloc), and took up to a third of the hits
 * PHP serves per second. A shard whose two slots cannot be decoded
 * (changed by hand, a damaged disk, the first write of a slot cut by a
 * full disk) is read as empty and starts again from zero at its next
 * update: its counters are already missing from the sum, which the
 * update can only raise, and the shard stays in use. A slot of the
 * format before the checksum (counters alone) is read with the
 * sequence 0.
 *
 * The label sets of Metrics are bounded, so are the files, of a few
 * kilobytes, and so is their number: their names are fixed, three per
 * shard. The directory (var/metrics of the project) is checked by the
 * library's LockDirectory on first use: private to the PHP user, so that
 * no other user can change the counters; the files are 0644. No file of
 * the directory is opened through a symbolic link. check-env reports a
 * refused directory.
 */
final class FileMetricsStore implements MetricsStore
{
    /** The first slot of the first shard, the only file before the counters were split. */
    public const FILE_NAME = 'metrics.json';

    /** How many shards the counters are split over. */
    public const SHARDS = 8;

    /** The mode of every file of the directory. */
    private const FILE_MODE = 0644;

    /** readSlot(): the slot is missing or empty. */
    private const MISSING = 0;

    /** readSlot(): the slot cannot be decoded, or its checksum does not match. */
    private const DAMAGED = 1;

    /** readSlot(): the slot is a symbolic link, or cannot be read. */
    private const REFUSED = 2;

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
        $directory = $this->directory();
        if ($directory === null) {
            return false;
        }
        $first = (int) getmypid() % self::SHARDS;
        for ($i = 0; $i < self::SHARDS; ++$i) {
            $written = $this->update($directory, ($first + $i) % self::SHARDS, LOCK_EX | LOCK_NB, $increments);
            if ($written !== null) {
                return $written;
            }
        }

        // Every shard is busy, or refused: wait for the first one.
        return $this->update($directory, $first, LOCK_EX, $increments) ?? false;
    }

    public function read(): ?array
    {
        $directory = $this->directory();
        if ($directory === null) {
            return null;
        }
        $counters = [];
        for ($shard = 0; $shard < self::SHARDS; ++$shard) {
            if (!self::exists($directory . '/' . self::fileName($shard)) && !self::exists($directory . '/' . self::alternateName($shard))) {
                continue;
            }
            $lock = $this->open($directory . '/' . self::lockName($shard));
            if ($lock === null) {
                return null;
            }

            try {
                if (!@flock($lock, LOCK_SH)) {
                    return null;
                }
                $latest = $this->latest($directory, $shard);
                if ($latest === null) {
                    return null;
                }
                foreach ($latest[1] as $name => $value) {
                    $counters[$name] = ($counters[$name] ?? 0) + $value;
                }
            } finally {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }

        return $counters;
    }

    /**
     * Adds the increments to one shard, under the lock of the shard.
     *
     * @param int<0, 7>          $operation  LOCK_EX, with LOCK_NB not to wait
     * @param array<string, int> $increments
     *
     * @return bool|null whether the shard was written; null when it is busy
     *                   under LOCK_NB, or refused (a link), so that another
     *                   shard is tried
     */
    private function update(string $directory, int $shard, int $operation, array $increments): ?bool
    {
        $lock = $this->open($directory . '/' . self::lockName($shard));
        if ($lock === null) {
            return null;
        }

        try {
            if (!@flock($lock, $operation)) {
                return ($operation & LOCK_NB) !== 0 ? null : false;
            }
            $latest = $this->latest($directory, $shard);
            if ($latest === null) {
                return null;
            }
            [$sequence, $counters, $slot] = $latest;
            foreach ($increments as $name => $amount) {
                $counters[$name] = ($counters[$name] ?? 0) + $amount;
            }
            $content = self::encode($sequence + 1, $counters);

            return $content !== null && $this->write(
                $directory . '/' . ($slot === 0 ? self::alternateName($shard) : self::fileName($shard)),
                $content
            );
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /**
     * The newer valid slot of a shard: its sequence number, its counters
     * and which slot it is (0 or 1). The sequence is 0, the counters empty
     * and the slot null when neither is valid.
     *
     * @return array{int, array<string, int>, int|null}|null null when a slot is refused
     */
    private function latest(string $directory, int $shard): ?array
    {
        $latest = [0, [], null];
        foreach ([self::fileName($shard), self::alternateName($shard)] as $slot => $name) {
            $content = $this->readSlot($directory . '/' . $name);
            if ($content === self::REFUSED) {
                return null;
            }
            if (is_array($content) && ($latest[2] === null || $content[0] > $latest[0])) {
                $latest = [$content[0], $content[1], $slot];
            }
        }

        return $latest;
    }

    /**
     * @return array{int, array<string, int>}|int the sequence number and the
     *                                            counters, else MISSING, DAMAGED or REFUSED
     */
    private function readSlot(string $file)
    {
        if (is_link($file)) {
            return self::REFUSED;
        }
        if (!self::exists($file)) {
            return self::MISSING;
        }
        $content = @file_get_contents($file);
        if ($content === false) {
            return self::REFUSED;
        }

        return $content === '' ? self::MISSING : self::decode($content) ?? self::DAMAGED;
    }

    /**
     * Writes the content over the slot, in place; a slot that cannot be
     * written completely is left with a checksum that does not match.
     */
    private function write(string $file, string $content): bool
    {
        $handle = $this->open($file);
        if ($handle === null) {
            return false;
        }
        $written = @fwrite($handle, $content) === strlen($content)
            && @ftruncate($handle, strlen($content))
            && @fflush($handle);

        return @fclose($handle) && $written;
    }

    /**
     * Opens a file of the directory for reading and writing, created with
     * FILE_MODE when missing, never through a symbolic link.
     *
     * @return resource|null
     */
    private function open(string $file)
    {
        if (is_link($file)) {
            return null;
        }
        $created = !file_exists($file);
        $handle = @fopen($file, 'c+b');
        if ($handle === false) {
            return null;
        }
        if ($created) {
            @chmod($file, self::FILE_MODE);
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
     * The name of the first slot of a shard, from 0 to SHARDS - 1.
     */
    public static function fileName(int $shard): string
    {
        return $shard === 0 ? self::FILE_NAME : 'metrics-' . $shard . '.json';
    }

    /**
     * The name of the second slot of a shard, from 0 to SHARDS - 1.
     */
    public static function alternateName(int $shard): string
    {
        return self::baseName($shard) . '.alt.json';
    }

    /**
     * The name of the lock file of a shard, from 0 to SHARDS - 1.
     */
    public static function lockName(int $shard): string
    {
        return self::baseName($shard) . '.lock';
    }

    private static function baseName(int $shard): string
    {
        return substr(self::fileName($shard), 0, -strlen('.json'));
    }

    private static function exists(string $file): bool
    {
        clearstatcache(true, $file);

        return file_exists($file) || is_link($file);
    }

    /**
     * A slot: the CRC-32 of the JSON in hexadecimal, a space, and the JSON
     * of the sequence number and the counters.
     *
     * @param array<string, int> $counters
     *
     * @return string|null null when the counters cannot be encoded (a name that is not UTF-8)
     */
    private static function encode(int $sequence, array $counters): ?string
    {
        $json = json_encode(['seq' => $sequence, 'counters' => $counters], JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return null;
        }

        return sprintf('%08x', crc32($json)) . ' ' . $json;
    }

    /**
     * The sequence number and the counters of a slot; null when it cannot
     * be decoded or its checksum does not match. A slot of the format
     * before the checksum, the counters alone, has the sequence 0.
     *
     * @return array{int, array<string, int>}|null
     */
    private static function decode(string $content): ?array
    {
        if ($content[0] === '{') {
            $counters = self::counters(json_decode($content, true));

            return $counters === null ? null : [0, $counters];
        }
        if (preg_match('/\A([0-9a-f]{8}) (\{.*\})\z/s', $content, $match) !== 1 || sprintf('%08x', crc32($match[2])) !== $match[1]) {
            return null;
        }
        $data = json_decode($match[2], true);
        if (!is_array($data) || !is_int($data['seq'] ?? null) || $data['seq'] < 1) {
            return null;
        }
        $counters = self::counters($data['counters'] ?? null);

        return $counters === null ? null : [$data['seq'], $counters];
    }

    /**
     * @param mixed $data
     *
     * @return array<string, int>|null
     */
    private static function counters($data): ?array
    {
        if (!is_array($data)) {
            return null;
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
