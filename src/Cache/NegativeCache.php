<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cache;

/**
 * Remembers that the origin answered 404 for a cache entry, with a marker
 * file "<entry>.404" whose modification time is the moment of the answer.
 *
 * While the marker is younger than the TTL the proxy answers 404 without
 * contacting the origin. Remembering creates the missing directories of the
 * entry (finding P-5, resolved by PRX-5).
 */
final class NegativeCache
{
    private const MARKER_SUFFIX = '.404';

    /**
     * @var CacheStorage
     */
    private $storage;

    /**
     * @var int
     */
    private $ttl;

    /**
     * @param int $ttl seconds a marker stays fresh (NEGATIVE_CACHE_TTL)
     */
    public function __construct(CacheStorage $storage, int $ttl)
    {
        $this->storage = $storage;
        $this->ttl = $ttl;
    }

    /**
     * Whether a 404 was remembered for $cacheFile less than TTL seconds ago.
     */
    public function isFresh(string $cacheFile): bool
    {
        $marker = $cacheFile . self::MARKER_SUFFIX;
        if (!$this->storage->isFile($marker)) {
            return false;
        }
        $rememberedAt = $this->storage->modifiedAt($marker);

        return $rememberedAt !== null && time() - $rememberedAt < $this->ttl;
    }

    /**
     * Remembers a 404 for $cacheFile from now on.
     */
    public function remember(string $cacheFile): void
    {
        $this->storage->ensureDirectory(dirname($cacheFile));
        $this->storage->touch($cacheFile . self::MARKER_SUFFIX);
    }

    /**
     * Forgets the 404 remembered for $cacheFile, if any.
     */
    public function forget(string $cacheFile): void
    {
        $this->storage->delete($cacheFile . self::MARKER_SUFFIX);
    }
}
