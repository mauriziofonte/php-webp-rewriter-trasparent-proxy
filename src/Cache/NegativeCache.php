<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cache;

/**
 * Remembers, for NEGATIVE_CACHE_TTL seconds, that the origin answered 404
 * (or 410) for a cache entry, so that the proxy answers again without
 * contacting the origin.
 *
 * 2.x wrote a marker "<entry>.404" next to the would-be cache file and
 * created its directories, so every request for a path that does not
 * exist added directories and a file below public/ (finding P-5). The
 * markers now live in one flat state directory outside public/
 * (var/negative/ of the project), in a bounded number of slots: the first
 * SLOT_HEX_DIGITS hexadecimal digits of the SHA-256 of the entry name the
 * slot, so at most 4096 marker files exist whatever the clients ask. A
 * slot holds the status and the full hash of its entry; two entries that
 * share a slot replace each other's marker, which only costs one more
 * origin request. The modification time of the slot file is the moment of
 * the answer. Nothing below public/ is created, and no directory other
 * than the state directory itself.
 */
final class NegativeCache
{
    /**
     * Hexadecimal digits of the hash that name a slot: 16^3 = 4096 slots.
     */
    private const SLOT_HEX_DIGITS = 3;

    private const SLOT_SUFFIX = '.neg';

    /**
     * The statuses that can be remembered.
     */
    private const STATUSES = [404, 410];

    /**
     * @var CacheStorage
     */
    private $storage;

    /**
     * @var string
     */
    private $directory;

    /**
     * @var int
     */
    private $ttl;

    /**
     * @param string $directory the state directory, outside the document root
     * @param int    $ttl       seconds a marker stays fresh (NEGATIVE_CACHE_TTL)
     */
    public function __construct(CacheStorage $storage, string $directory, int $ttl)
    {
        $this->storage = $storage;
        $this->directory = rtrim($directory, '/');
        $this->ttl = $ttl;
    }

    /**
     * The status remembered for $cacheFile less than TTL seconds ago, or
     * null.
     */
    public function lookup(string $cacheFile): ?int
    {
        $slot = $this->slotOf($cacheFile);
        $status = $this->statusIn($slot, $cacheFile);
        $rememberedAt = $status === null ? null : $this->storage->modifiedAt($slot);
        if ($rememberedAt === null || time() - $rememberedAt >= $this->ttl) {
            return null;
        }

        return $status;
    }

    /**
     * Whether a 404 or 410 was remembered for $cacheFile less than TTL
     * seconds ago.
     */
    public function isFresh(string $cacheFile): bool
    {
        return $this->lookup($cacheFile) !== null;
    }

    /**
     * Remembers from now on that the origin answered $status for
     * $cacheFile; a status other than 404 and 410 is not remembered.
     */
    public function remember(string $cacheFile, int $status = 404): void
    {
        if (in_array($status, self::STATUSES, true)) {
            $this->storage->write($this->slotOf($cacheFile), $status . ' ' . hash('sha256', $cacheFile) . "\n");
        }
    }

    /**
     * Forgets the status remembered for $cacheFile, fresh or not; the
     * marker of another entry that shares the slot is kept.
     */
    public function forget(string $cacheFile): void
    {
        $slot = $this->slotOf($cacheFile);
        if ($this->statusIn($slot, $cacheFile) !== null) {
            $this->storage->delete($slot);
        }
    }

    private function slotOf(string $cacheFile): string
    {
        return $this->directory . '/' . substr(hash('sha256', $cacheFile), 0, self::SLOT_HEX_DIGITS) . self::SLOT_SUFFIX;
    }

    /**
     * The status the slot file holds for $cacheFile, or null when it holds
     * none or the one of another entry.
     */
    private function statusIn(string $slot, string $cacheFile): ?int
    {
        $contents = $this->storage->read($slot);
        if ($contents === null || preg_match('/\A(\d{3}) ([0-9a-f]{64})\n\z/', $contents, $match) !== 1) {
            return null;
        }
        $status = (int) $match[1];

        return $match[2] === hash('sha256', $cacheFile) && in_array($status, self::STATUSES, true) ? $status : null;
    }
}
