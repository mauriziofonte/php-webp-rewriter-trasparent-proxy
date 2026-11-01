<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cache;

/**
 * Maps a request path and query string to its cache location, with the 2.x
 * layout:
 *
 * - without a meaningful query string the entry is <cache>/<path>, where the
 *   web server serves it on later requests without running PHP;
 * - otherwise it is <cache>/_qs/<hash>/<path>, where <hash> is the first 16
 *   hexadecimal digits of the SHA-256 of the canonical query string
 *   (QueryString::getCanonical(), see QueryStringPolicy).
 *
 * The path is the decoded one of PathSanitizer, so that the file has the
 * name the web server looks for (finding P-8); PathSanitizer refuses the
 * paths that start with BUCKET_DIRECTORY. PRX-6 replaces this layout.
 */
final class CacheKeyResolver
{
    /**
     * The directory of the query-string buckets, below the cache root.
     */
    public const BUCKET_DIRECTORY = '_qs';

    /**
     * @var string
     */
    private $cacheDirectory;

    /**
     * @param string $cacheDirectory absolute path of the cache root (the web server's document root)
     */
    public function __construct(string $cacheDirectory)
    {
        $this->cacheDirectory = rtrim($cacheDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * @param string $path           decoded request path (PathSanitizer::sanitize()), starting with "/"
     * @param string $canonicalQuery QueryString::getCanonical(), empty for none
     */
    public function resolve(string $path, string $canonicalQuery): CacheLocation
    {
        $base = $this->cacheDirectory;
        if ($canonicalQuery !== '') {
            $base .= self::BUCKET_DIRECTORY . DIRECTORY_SEPARATOR . substr(hash('sha256', $canonicalQuery), 0, 16) . DIRECTORY_SEPARATOR;
        }

        $file = $base . ltrim($path, '/');
        $directory = rtrim(dirname($file), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return new CacheLocation($file, $directory, self::siblingOf($file, 'webp'), $canonicalQuery);
    }

    /**
     * The path of $file with its extension, when it has one, replaced by $extension.
     */
    public static function siblingOf(string $file, string $extension): string
    {
        return dirname($file) . DIRECTORY_SEPARATOR . pathinfo($file, PATHINFO_FILENAME) . '.' . $extension;
    }
}
