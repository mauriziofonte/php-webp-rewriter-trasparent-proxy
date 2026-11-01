<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cache;

/**
 * Removes cache entries on an authenticated purge (see
 * Security\PurgeAuthenticator).
 *
 * A purge removes, in both tiers (the one the web server serves and the one
 * PHP revalidates, CacheLocation::managed()), the entry, its metadata, its
 * AVIF and WEBP variants and its Brotli and gzip encodings
 * (CacheLocation::getFiles()), and what the negative cache remembers for
 * it. A purge of a URL without query string also removes the entries of
 * every query string of the same path, in ".q/" and ".qh/" (finding P-16:
 * 2.x left them, so "PURGE /app.css" kept serving "/app.css?v=2"); a purge
 * of a URL with a query string removes that entry only.
 *
 * 2.x also had "purge_all", which removed the files of other URLs that
 * share the base name ("a.jpg" for "a.png"); those are other entries,
 * purged by their own URL.
 */
final class Purger
{
    /**
     * The bucket directories of the query strings, below a host directory.
     */
    private const QUERY_DIRECTORIES = [CacheKeyResolver::QUERY_DIRECTORY, CacheKeyResolver::HASHED_QUERY_DIRECTORY];

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
     * @param CacheLocation $location the entry of the purged URL, in the tier the web server serves
     *
     * @return list<string> the files that existed and were removed
     */
    public function purge(CacheLocation $location): array
    {
        $entries = [$location];
        if (!$location->isQueryStringBucket()) {
            $entries = array_merge($entries, $this->queryEntries($location));
        }

        $removed = [];
        foreach ($entries as $entry) {
            foreach ([$entry, $entry->managed()] as $tier) {
                foreach ($tier->getFiles() as $file) {
                    if ($this->storage->isFile($file)) {
                        $this->storage->delete($file);
                        $removed[] = $file;
                    }
                }
            }
            $this->negativeCache->forget($entry->getFile());
        }

        return $removed;
    }

    /**
     * The entries of the path of $location in every query-string bucket
     * that exists, in either tier.
     *
     * @return list<CacheLocation>
     */
    private function queryEntries(CacheLocation $location): array
    {
        $entries = [];
        $hostDirectory = $location->getHostDirectory();
        foreach (['', DIRECTORY_SEPARATOR . CacheLocation::MANAGED_DIRECTORY] as $tier) {
            foreach (self::QUERY_DIRECTORIES as $directory) {
                foreach ($this->storage->listDirectory($hostDirectory . $tier . DIRECTORY_SEPARATOR . $directory) as $name) {
                    $bucket = $directory . DIRECTORY_SEPARATOR . $name;
                    $entries[$bucket] = $location->withBucket($bucket, $directory === CacheKeyResolver::QUERY_DIRECTORY ? $name : '');
                }
            }
        }

        return array_values($entries);
    }
}
