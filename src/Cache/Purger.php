<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cache;

/**
 * Removes a cache entry on an authenticated purge (see
 * Security\PurgeAuthenticator).
 *
 * A purge removes the entry, the WebP variant converted from it when the
 * entry is a raster image, and what the negative cache remembers for
 * both. 2.x also had "purge_all", which removed the files of other URLs
 * that share the base name ("a.jpg" for "a.png"); those are other
 * entries, purged by their own URL. Entries of other query-string buckets
 * are left alone (finding P-16, resolved by PRX-7).
 */
final class Purger
{
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
     * @param bool $withWebpVariant whether the entry is a raster image whose WebP variant goes too
     *
     * @return list<string> the files of the entry that existed and were removed
     */
    public function purge(CacheLocation $location, bool $withWebpVariant): array
    {
        $entries = [$location->getFile()];
        if ($withWebpVariant && $location->getWebpFile() !== $location->getFile()) {
            $entries[] = $location->getWebpFile();
        }

        $removed = [];
        foreach ($entries as $entry) {
            if ($this->storage->isFile($entry)) {
                $this->storage->delete($entry);
                $removed[] = $entry;
            }
            $this->negativeCache->forget($entry);
        }

        return $removed;
    }
}
