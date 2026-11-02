<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cache;

/**
 * The size of the cache as the last scan of cache:evict measured it: the
 * bytes and the entries on disk, and when. /metrics reports it (risk L-14:
 * the cache-size metric), without walking the cache on every scrape.
 * Immutable.
 */
final class CacheUsage
{
    /**
     * The file of the last scan, in the state directory (var/).
     */
    public const FILE_NAME = 'cache-usage.json';

    /**
     * @var int
     */
    private $bytes;

    /**
     * @var int
     */
    private $entries;

    /**
     * @var int
     */
    private $scannedAt;

    /**
     * @param int $bytes     bytes of every file of the cache
     * @param int $entries   cache entries, each with its siblings
     * @param int $scannedAt Unix time of the scan
     */
    public function __construct(int $bytes, int $entries, int $scannedAt)
    {
        $this->bytes = max(0, $bytes);
        $this->entries = max(0, $entries);
        $this->scannedAt = max(0, $scannedAt);
    }

    /**
     * The usage written in $file; null when it is missing or damaged.
     *
     * @phpstan-impure
     */
    public static function read(CacheStorage $storage, string $file): ?self
    {
        $json = $storage->read($file);
        $data = $json === null ? null : json_decode($json, true);
        if (!is_array($data) || !is_int($data['bytes'] ?? null) || !is_int($data['entries'] ?? null) || !is_int($data['scanned_at'] ?? null)) {
            return null;
        }

        return new self($data['bytes'], $data['entries'], $data['scanned_at']);
    }

    /**
     * Writes the usage to $file, atomically.
     */
    public function write(CacheStorage $storage, string $file): bool
    {
        $json = json_encode(['bytes' => $this->bytes, 'entries' => $this->entries, 'scanned_at' => $this->scannedAt]);

        return is_string($json) && $storage->write($file, $json, 0600);
    }

    public function getBytes(): int
    {
        return $this->bytes;
    }

    public function getEntries(): int
    {
        return $this->entries;
    }

    public function getScannedAt(): int
    {
        return $this->scannedAt;
    }
}
