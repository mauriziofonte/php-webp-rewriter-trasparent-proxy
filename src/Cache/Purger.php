<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cache;

/**
 * Removes cache entries on request.
 *
 * A purge removes the entry and its 404 marker; an aggressive purge also
 * removes the sibling representations that share its name in the same
 * directory (the ".webp" conversion and the raster originals). Entries of
 * other query-string buckets are left alone (finding P-16, resolved by
 * PRX-7).
 */
final class Purger
{
    /**
     * Extensions of the siblings removed by an aggressive purge.
     */
    private const SIBLING_EXTENSIONS = ['webp', 'jpg', 'jpeg', 'png', 'gif', 'bmp'];

    /**
     * @var CacheStorage
     */
    private $storage;

    /**
     * @var NegativeCache
     */
    private $negativeCache;

    public function __construct(CacheStorage $storage, NegativeCache $negativeCache)
    {
        $this->storage = $storage;
        $this->negativeCache = $negativeCache;
    }

    /**
     * @param string $cacheFile  absolute path of the entry
     * @param bool   $aggressive whether to remove the sibling representations too
     *
     * @return list<string> the entries purged, $cacheFile first; the caller
     *                      clears any other state it keeps for them
     */
    public function purge(string $cacheFile, bool $aggressive): array
    {
        $entries = [$cacheFile];
        if ($aggressive && pathinfo($cacheFile, PATHINFO_FILENAME) !== '') {
            foreach (self::SIBLING_EXTENSIONS as $extension) {
                $entries[] = CacheKeyResolver::siblingOf($cacheFile, $extension);
            }
        }
        $entries = array_values(array_unique($entries));

        foreach ($entries as $entry) {
            $this->storage->delete($entry);
            $this->negativeCache->forget($entry);
        }

        return $entries;
    }
}
