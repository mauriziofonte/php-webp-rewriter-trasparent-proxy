<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cache;

/**
 * Where the cache entry of one request and its siblings live on disk.
 *
 * With D the bucket directory of the entry (CacheKeyResolver) and P its
 * relative path:
 *
 *     D/P                 the entry, as the origin sent it
 *     D/.avif/P.avif      its AVIF variant (raster images)
 *     D/.webp/P.webp      its WEBP variant (raster images)
 *     D/.br/P, D/.gz/P    its Brotli and gzip encodings (compressible types)
 *
 * The variants keep the name of the entry and add the extension of their
 * format, so that the web server types them by that extension; the
 * encodings keep the name, and the type, of the entry. Every sibling lives
 * in a directory whose name starts with ".", which no request path names.
 *
 * Immutable; built by CacheKeyResolver.
 */
final class CacheLocation
{
    public const FORMAT_AVIF = 'avif';

    public const FORMAT_WEBP = 'webp';

    /**
     * The formats a raster entry may have a variant in, by preference.
     */
    public const FORMATS = [self::FORMAT_AVIF, self::FORMAT_WEBP];

    public const ENCODING_BROTLI = 'br';

    public const ENCODING_GZIP = 'gz';

    /**
     * The encodings an entry may have a sibling in, by preference.
     */
    public const ENCODINGS = [self::ENCODING_BROTLI, self::ENCODING_GZIP];

    /**
     * @var string
     */
    private $hostDirectory;

    /**
     * @var string
     */
    private $bucket;

    /**
     * @var string
     */
    private $path;

    /**
     * @var string
     */
    private $canonicalQuery;

    /**
     * @param string $hostDirectory  absolute path of the directory of the host
     * @param string $bucket         CacheKeyResolver::bucketOf() of the query string, "" for none
     * @param string $path           decoded request path, without the leading "/"
     * @param string $canonicalQuery canonical query string that keys the entry, empty for none
     */
    public function __construct(string $hostDirectory, string $bucket, string $path, string $canonicalQuery)
    {
        $this->hostDirectory = $hostDirectory;
        $this->bucket = $bucket;
        $this->path = $path;
        $this->canonicalQuery = $canonicalQuery;
    }

    /**
     * The entry.
     */
    public function getFile(): string
    {
        return $this->bucketDirectory() . DIRECTORY_SEPARATOR . $this->path;
    }

    /**
     * Directory of the entry, with a trailing separator.
     */
    public function getDirectory(): string
    {
        return rtrim(dirname($this->getFile()), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * The variant of the entry in $format (one of FORMATS).
     */
    public function getVariantFile(string $format): string
    {
        return $this->siblingDirectory($format) . DIRECTORY_SEPARATOR . $this->path . '.' . $format;
    }

    /**
     * The entry encoded with $encoding (one of ENCODINGS).
     */
    public function getEncodedFile(string $encoding): string
    {
        return $this->siblingDirectory($encoding) . DIRECTORY_SEPARATOR . $this->path;
    }

    /**
     * Every file of the entry: the entry, its variants and its encodings.
     *
     * @return list<string>
     */
    public function getFiles(): array
    {
        $files = [$this->getFile()];
        foreach (self::FORMATS as $format) {
            $files[] = $this->getVariantFile($format);
        }
        foreach (self::ENCODINGS as $encoding) {
            $files[] = $this->getEncodedFile($encoding);
        }

        return $files;
    }

    /**
     * The directory of the host.
     */
    public function getHostDirectory(): string
    {
        return $this->hostDirectory;
    }

    /**
     * The bucket of the query string, relative to the host directory: ""
     * for none (see CacheKeyResolver::bucketOf()).
     */
    public function getBucket(): string
    {
        return $this->bucket;
    }

    /**
     * The decoded request path, without the leading "/".
     */
    public function getRelativePath(): string
    {
        return $this->path;
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
     * Whether the entry lives in a query-string bucket.
     */
    public function isQueryStringBucket(): bool
    {
        return $this->bucket !== '';
    }

    /**
     * A string that names the entry and nothing else, for locks.
     */
    public function getKey(): string
    {
        return $this->getFile();
    }

    private function bucketDirectory(): string
    {
        return $this->hostDirectory . ($this->bucket === '' ? '' : DIRECTORY_SEPARATOR . $this->bucket);
    }

    private function siblingDirectory(string $kind): string
    {
        return $this->bucketDirectory() . DIRECTORY_SEPARATOR . '.' . $kind;
    }
}
