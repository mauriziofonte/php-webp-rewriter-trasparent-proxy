<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cache;

/**
 * Removes a cache entry on an authenticated purge (see
 * Security\PurgeAuthenticator).
 *
 * A purge removes the entry, its AVIF and WEBP variants, its Brotli and
 * gzip encodings (CacheLocation::getFiles()), and what the negative cache
 * remembers for the entry. 2.x also had "purge_all", which removed the files of other URLs
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
     * @return list<string> the files of the entry that existed and were removed
     */
    public function purge(CacheLocation $location): array
    {
        $removed = [];
        foreach ($location->getFiles() as $file) {
            if ($this->storage->isFile($file)) {
                $this->storage->delete($file);
                $removed[] = $file;
            }
        }
        $this->negativeCache->forget($location->getFile());

        return $removed;
    }
}
