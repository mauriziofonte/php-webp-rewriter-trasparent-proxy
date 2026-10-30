<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cache;

/**
 * Where the cache entry of one request lives on disk: the file, its
 * directory and the ".webp" sibling that holds its converted copy.
 *
 * Immutable; built by CacheKeyResolver.
 */
final class CacheLocation
{
    /**
     * @var string
     */
    private $file;

    /**
     * @var string
     */
    private $directory;

    /**
     * @var string
     */
    private $webpFile;

    /**
     * @var string
     */
    private $canonicalQuery;

    /**
     * @param string $file           absolute path of the cache file
     * @param string $directory      absolute path of its directory, with a trailing separator
     * @param string $webpFile       absolute path of the ".webp" sibling
     * @param string $canonicalQuery canonical query string that keys the entry, empty for none
     */
    public function __construct(string $file, string $directory, string $webpFile, string $canonicalQuery)
    {
        $this->file = $file;
        $this->directory = $directory;
        $this->webpFile = $webpFile;
        $this->canonicalQuery = $canonicalQuery;
    }

    public function getFile(): string
    {
        return $this->file;
    }

    /**
     * Directory of the file, with a trailing separator.
     */
    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * The file with its extension replaced by ".webp".
     */
    public function getWebpFile(): string
    {
        return $this->webpFile;
    }

    /**
     * Canonical query string that keys the entry; empty when the entry is
     * keyed on the path alone.
     */
    public function getCanonicalQuery(): string
    {
        return $this->canonicalQuery;
    }

    /**
     * Whether the entry lives in a query-string bucket (_qs/<hash>/), which
     * the web server never serves by itself.
     */
    public function isQueryStringBucket(): bool
    {
        return $this->canonicalQuery !== '';
    }
}
