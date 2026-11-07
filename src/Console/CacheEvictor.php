<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Console;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Cache\CacheLocation;
use Mfonte\ProxyCdn\Exception\InvalidArgumentException;

/**
 * Brings the cache (public/_cache) down to a size, least recently used
 * entry first: the work of cache:evict.
 *
 * The unit is the set of an entry: the entry, its metadata, its AVIF and
 * WEBP variants, its Brotli and gzip encodings, and the pending copy of
 * an image waiting for its variants with the variants staged for it
 * (CacheLocation::getFiles()), in one tier and one query-string bucket.
 * A set is measured as the sum of its files and dated by the most recent
 * access time (BY_ATIME: the web server serves the encodings and the
 * variants too) or modification time (BY_MTIME, for a file system
 * mounted noatime) of any of them, and is always removed whole: its
 * variants, staged variants and encodings first, then the pending copy,
 * then the entry, then its metadata, so that a run cut short never
 * leaves a sibling that outlives its entry. A sibling whose entry is
 * gone is a set of its own and goes the same way; so is an image that
 * is only pending.
 *
 * The walk uses lstat() and never follows a symbolic link: a link is
 * counted, left in place, and never entered or deleted, and a file is
 * measured or removed only when every directory between the cache root
 * and the file is a real directory (CacheTree::statInside()), so nothing
 * outside the cache root is read or removed. A set used again between the
 * scan and its removal (a newer time) is kept.
 */
final class CacheEvictor
{
    public const BY_ATIME = 'atime';

    public const BY_MTIME = 'mtime';

    /**
     * The sibling directories of a bucket, with the suffix their files add
     * to the path of the entry.
     */
    private const SIBLINGS = [
        '.avif' => '.avif', '.webp' => '.webp', '.br' => '', '.gz' => '', '.meta' => '.json',
        // The pending tier of PRX-8: the entries waiting for their
        // variants, and the variants staged for them.
        '.pending' => '', '.pending/.avif' => '.avif', '.pending/.webp' => '.webp',
    ];

    /**
     * @var string
     */
    private $cacheDirectory;

    /**
     * @var string
     */
    private $by;

    /**
     * @param string $by BY_ATIME or BY_MTIME
     *
     * @throws InvalidArgumentException when $by is neither
     */
    public function __construct(string $cacheDirectory, string $by = self::BY_ATIME)
    {
        if ($by !== self::BY_ATIME && $by !== self::BY_MTIME) {
            throw new InvalidArgumentException('The eviction order must be "atime" or "mtime", got ' . $by);
        }
        $this->cacheDirectory = rtrim($cacheDirectory, '/');
        $this->by = $by;
    }

    /**
     * Measures the cache and, when $maxBytes is above 0 and the cache is
     * larger, removes the least recently used sets until it fits (nothing
     * is removed with $dryRun).
     */
    public function evict(int $maxBytes, bool $dryRun = false): EvictionReport
    {
        list($sets, $links) = $this->scan();
        $totalBytes = 0;
        foreach ($sets as $set) {
            $totalBytes += $set[1];
        }
        $report = new EvictionReport(count($sets), $totalBytes, $links);
        if ($maxBytes <= 0 || $totalBytes <= $maxBytes) {
            return $report;
        }

        uasort($sets, static function (array $a, array $b): int {
            return $a[2] <=> $b[2];
        });
        $remaining = $totalBytes;
        foreach ($sets as $key => $set) {
            if ($remaining <= $maxBytes) {
                break;
            }
            if (!$dryRun && !$this->removeSet($set[0], $set[2])) {
                $report->keep();
                continue;
            }
            $remaining -= $set[1];
            $report->evicted((string) $key, $set[1]);
        }

        return $report;
    }

    /**
     * Every set of the cache, keyed by tier, host, bucket and path: its
     * location, bytes and time; and the number of links seen.
     *
     * @return array{array<string, array{CacheLocation, int, int}>, int}
     */
    private function scan(): array
    {
        $sets = [];
        $links = 0;
        foreach ($this->directories($this->cacheDirectory, $links) as $host) {
            if ($host[0] === '.') {
                continue;
            }
            $hostDirectory = $this->cacheDirectory . '/' . $host;
            foreach (['' => false, '/' . CacheLocation::MANAGED_DIRECTORY => true] as $tier => $managed) {
                $tierDirectory = $hostDirectory . $tier;
                if ($tier !== '' && !self::isDirectory($tierDirectory, $links)) {
                    continue;
                }
                $buckets = [''];
                foreach ([CacheKeyResolver::QUERY_DIRECTORY, CacheKeyResolver::HASHED_QUERY_DIRECTORY] as $queryDirectory) {
                    if (!self::isDirectory($tierDirectory . '/' . $queryDirectory, $links)) {
                        continue;
                    }
                    foreach ($this->directories($tierDirectory . '/' . $queryDirectory, $links) as $name) {
                        $buckets[] = $queryDirectory . '/' . $name;
                    }
                }
                foreach ($buckets as $bucket) {
                    $this->scanBucket($sets, $links, $hostDirectory, $managed, $bucket, $tierDirectory . ($bucket === '' ? '' : '/' . $bucket));
                }
            }
        }

        return [$sets, $links];
    }

    /**
     * @param array<string, array{CacheLocation, int, int}> $sets
     */
    private function scanBucket(array &$sets, int &$links, string $hostDirectory, bool $managed, string $bucket, string $bucketDirectory): void
    {
        $paths = [];
        $visible = static function (string $relative): bool {
            return basename($relative)[0] !== '.';
        };
        foreach (CacheTree::walk($bucketDirectory, $visible) as $relative => $stat) {
            if ($stat[0] === CacheTree::TYPE_LINK) {
                ++$links;
            } elseif ($stat[0] === CacheTree::TYPE_FILE && basename($relative)[0] !== '.') {
                $paths[$relative] = true;
            }
        }
        foreach (self::SIBLINGS as $directory => $suffix) {
            if (!self::isDirectory($bucketDirectory . '/' . $directory, $links)) {
                continue;
            }
            // A dot directory within is another sibling (".pending/.webp").
            foreach (CacheTree::walk($bucketDirectory . '/' . $directory, $visible) as $relative => $stat) {
                if ($stat[0] === CacheTree::TYPE_LINK) {
                    ++$links;
                } elseif ($stat[0] === CacheTree::TYPE_FILE && basename($relative)[0] !== '.' && ($suffix === '' || substr($relative, -strlen($suffix)) === $suffix)) {
                    $paths[$suffix === '' ? $relative : (string) substr($relative, 0, -strlen($suffix))] = true;
                }
            }
        }

        foreach (array_keys($paths) as $path) {
            $location = new CacheLocation($hostDirectory, $bucket, (string) $path, '');
            if ($managed) {
                $location = $location->managed();
            }
            list($bytes, $time) = $this->measure($location);
            $sets[($managed ? 'm' : 'w') . ':' . $location->getKey()] = [$location, $bytes, $time];
        }
    }

    /**
     * The bytes and the time of the regular files of a set.
     *
     * @return array{int, int}
     */
    private function measure(CacheLocation $location): array
    {
        $bytes = 0;
        $time = 0;
        foreach ($location->getFiles() as $file) {
            $stat = CacheTree::statInside($this->cacheDirectory, $file);
            if ($stat !== null && $stat[0] === CacheTree::TYPE_FILE) {
                $bytes += $stat[1];
                $time = max($time, $this->by === self::BY_ATIME ? $stat[2] : $stat[3]);
            }
        }

        return [$bytes, $time];
    }

    /**
     * Removes the regular files of a set, siblings first; false, and
     * nothing is removed, when the set was used after $scannedTime.
     */
    private function removeSet(CacheLocation $location, int $scannedTime): bool
    {
        if ($this->measure($location)[1] > $scannedTime) {
            return false;
        }
        $last = [$location->getPendingFile(), $location->getFile(), $location->getMetadataFile()];
        $ordered = array_merge(array_values(array_diff($location->getFiles(), $last)), $last);
        foreach ($ordered as $file) {
            $stat = CacheTree::statInside($this->cacheDirectory, $file);
            if ($stat !== null && $stat[0] === CacheTree::TYPE_FILE) {
                @unlink($file);
            }
        }

        return true;
    }

    /**
     * The names of the real directories (not links) in $directory.
     *
     * @return list<string>
     */
    private function directories(string $directory, int &$links): array
    {
        $names = @scandir($directory);
        $directories = [];
        foreach ($names === false ? [] : $names as $name) {
            if ($name !== '.' && $name !== '..' && self::isDirectory($directory . '/' . $name, $links)) {
                $directories[] = $name;
            }
        }

        return $directories;
    }

    /**
     * Whether $path is a directory and not a link to one; a link is counted.
     */
    private static function isDirectory(string $path, int &$links): bool
    {
        $stat = @lstat($path);
        if ($stat === false) {
            return false;
        }
        $type = $stat['mode'] & 0170000;
        if ($type === 0120000) {
            ++$links;
        }

        return $type === 0040000;
    }
}
