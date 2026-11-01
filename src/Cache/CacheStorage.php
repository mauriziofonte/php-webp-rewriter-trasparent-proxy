<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cache;

/**
 * Storage of cache entries and of the marker files kept next to them,
 * addressed by absolute path.
 *
 * Writes are atomic: a reader sees either the previous contents or the new
 * ones, never a partial file. Implementations never throw and never emit PHP
 * warnings; failures are reported through the return values. The readers
 * are impure: another process may change the files between two calls.
 */
interface CacheStorage
{
    /**
     * Copies the file at $source to $path atomically, in chunks: the
     * contents are never held in memory. False when $source cannot be read
     * or $path cannot be written.
     */
    public function import(string $path, string $source): bool;

    /**
     * Whether $path is an existing regular file.
     *
     * @phpstan-impure
     */
    public function isFile(string $path): bool;

    /**
     * Contents of the file at $path, or null when it cannot be read.
     *
     * @phpstan-impure
     */
    public function read(string $path): ?string;

    /**
     * Size in bytes of the file at $path, or null when it does not exist.
     *
     * @phpstan-impure
     */
    public function size(string $path): ?int;

    /**
     * Unix time of the last modification of $path, or null when it does not exist.
     *
     * @phpstan-impure
     */
    public function modifiedAt(string $path): ?int;

    /**
     * Atomically replaces the file at $path with $contents, creating the
     * missing parent directories.
     *
     * @return bool true when the file holds $contents afterwards
     */
    public function write(string $path, string $contents): bool;

    /**
     * Creates $directory and its missing parents.
     *
     * @return bool true when $directory exists afterwards
     */
    public function ensureDirectory(string $directory): bool;

    /**
     * Creates the empty file $path, or sets its modification time to now.
     * The parent directory must exist.
     *
     * @return bool true on success
     */
    public function touch(string $path): bool;

    /**
     * Removes the file at $path when it exists.
     */
    public function delete(string $path): void;
}
