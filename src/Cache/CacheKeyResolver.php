<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cache;

/**
 * Maps a host, a request path and a query string to the cache location of
 * the entry, in a layout that Apache and nginx compute themselves, so that
 * they serve every hit without PHP (findings S-4 and L-13):
 *
 *     <cache>/<host>/<path>                    entry without query string
 *     <cache>/<host>/.q/<query>/<path>         entry of a query string
 *     <cache>/<host>/.qh/<hash>/<path>         entry of a query string the
 *                                              web server cannot map
 *
 * <cache> is public/_cache/ of the project, <host> the name of the host
 * allowlist (Security\HostAllowlist), <path> the decoded request path
 * (PathSanitizer, finding P-8). <query> is the canonical query string
 * (QueryString::getCanonical()) when it is made of the characters of
 * SERVER_QUERY_PATTERN only: the web server finds the entry when the
 * client sent the canonical form itself ("?v=42"), and hands any other
 * form to PHP, which maps it to the same entry. Any other canonical query
 * string is hashed into <hash> (the first 16 hexadecimal digits of its
 * SHA-256), which only PHP computes. The siblings of an entry live in
 * directories whose names start with ".", which no request path can name
 * (PathSanitizer refuses such segments): see CacheLocation.
 */
final class CacheKeyResolver
{
    /**
     * The cache root below the document root.
     */
    public const CACHE_DIRECTORY = '_cache';

    /**
     * The query-string buckets of 2.x, below the document root; refused as
     * a first path segment so that their files are never served.
     */
    public const LEGACY_BUCKET_DIRECTORY = '_qs';

    /**
     * The directory of the query-string buckets the web server maps.
     */
    public const QUERY_DIRECTORY = '.q';

    /**
     * The directory of the hashed query-string buckets.
     */
    public const HASHED_QUERY_DIRECTORY = '.qh';

    /**
     * The canonical query strings stored under their own name: at most 200
     * bytes of letters, digits, "-", "_", ".", "~", "=" and "&", not
     * starting with "." (no ".." segment). The Apache and nginx
     * configurations match the raw query string with the same pattern.
     */
    public const SERVER_QUERY_PATTERN = '/\A[A-Za-z0-9_~-][A-Za-z0-9._~=&-]{0,199}\z/';

    /**
     * @var string
     */
    private $cacheDirectory;

    /**
     * @param string $cacheDirectory absolute path of the cache root (public/_cache of the project)
     */
    public function __construct(string $cacheDirectory)
    {
        $this->cacheDirectory = rtrim($cacheDirectory, DIRECTORY_SEPARATOR);
    }

    /**
     * @param string $host           a host name of the allowlist (HostAllowlist::match())
     * @param string $path           decoded request path (PathSanitizer::sanitize()), starting with "/"
     * @param string $canonicalQuery QueryString::getCanonical(), empty for none
     */
    public function resolve(string $host, string $path, string $canonicalQuery): CacheLocation
    {
        return new CacheLocation($this->hostDirectory($host), self::bucketOf($canonicalQuery), ltrim($path, '/'), $canonicalQuery);
    }

    /**
     * The directory of the entries of $host.
     */
    public function hostDirectory(string $host): string
    {
        return $this->cacheDirectory . DIRECTORY_SEPARATOR . $host;
    }

    /**
     * The cache root.
     */
    public function getCacheDirectory(): string
    {
        return $this->cacheDirectory;
    }

    /**
     * The bucket of a canonical query string, relative to the host
     * directory: "" for none, ".q/<query>" or ".qh/<hash>".
     */
    public static function bucketOf(string $canonicalQuery): string
    {
        if ($canonicalQuery === '') {
            return '';
        }
        if (preg_match(self::SERVER_QUERY_PATTERN, $canonicalQuery) === 1) {
            return self::QUERY_DIRECTORY . DIRECTORY_SEPARATOR . $canonicalQuery;
        }

        return self::HASHED_QUERY_DIRECTORY . DIRECTORY_SEPARATOR . substr(hash('sha256', $canonicalQuery), 0, 16);
    }
}
