<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Console;

/**
 * Walks and removes directory trees without ever following a symbolic
 * link: every entry is looked at with lstat(), a link is reported (or
 * removed itself) and never entered, so a link planted in the cache can
 * lead neither the cache commands nor their deletions out of their root.
 */
final class CacheTree
{
    public const TYPE_FILE = 'file';

    public const TYPE_LINK = 'link';

    public const TYPE_OTHER = 'other';

    /**
     * The entries below $directory, depth first, as relative path =>
     * [type, size, atime, mtime] (TYPE_* constants). Directories are
     * entered, not listed; a directory for which $enter returns false is
     * skipped. A missing or unreadable directory yields nothing.
     *
     * @param (callable(string, int): bool)|null $enter receives the relative path of a directory and its depth (1
     *                                                  for a child of $directory)
     *
     * @return \Generator<string, array{string, int, int, int}>
     */
    public static function walk(string $directory, ?callable $enter = null): \Generator
    {
        $directory = rtrim($directory, '/');
        $pending = [['', 1]];
        while ($pending !== []) {
            list($relative, $depth) = array_pop($pending);
            $names = @scandir($directory . ($relative === '' ? '' : '/' . $relative));
            if ($names === false) {
                continue;
            }
            $children = [];
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $relative === '' ? $name : $relative . '/' . $name;
                $stat = @lstat($directory . '/' . $path);
                if ($stat === false) {
                    continue;
                }
                $type = $stat['mode'] & 0170000;
                if ($type === 0040000) {
                    if ($enter === null || $enter($path, $depth)) {
                        $children[] = [$path, $depth + 1];
                    }
                    continue;
                }
                $kind = $type === 0100000 ? self::TYPE_FILE : ($type === 0120000 ? self::TYPE_LINK : self::TYPE_OTHER);
                yield $path => [$kind, (int) $stat['size'], (int) $stat['atime'], (int) $stat['mtime']];
            }
            // Popped from the end: reversed, the walk keeps the order of scandir().
            foreach (array_reverse($children) as $child) {
                $pending[] = $child;
            }
        }
    }

    /**
     * The lstat() of $path as [type, size, atime, mtime]; null when it does
     * not exist or is a directory.
     *
     * @return array{string, int, int, int}|null
     */
    public static function stat(string $path): ?array
    {
        $stat = @lstat($path);
        if ($stat === false) {
            return null;
        }
        $type = $stat['mode'] & 0170000;
        if ($type === 0040000) {
            return null;
        }
        $kind = $type === 0100000 ? self::TYPE_FILE : ($type === 0120000 ? self::TYPE_LINK : self::TYPE_OTHER);

        return [$kind, (int) $stat['size'], (int) $stat['atime'], (int) $stat['mtime']];
    }

    /**
     * The stat() of $path, as stat() gives it, but only when every directory
     * between $root and $path is a real directory, not a link: lstat()
     * alone does not follow the last component, but would follow a link
     * on the way ("<root>/.br" pointing elsewhere). Null otherwise, or
     * when $path is not below $root.
     *
     * @return array{string, int, int, int}|null
     */
    public static function statInside(string $root, string $path): ?array
    {
        $root = rtrim($root, '/');
        if (strpos($path, $root . '/') !== 0) {
            return null;
        }
        $components = explode('/', substr($path, strlen($root) + 1));
        array_pop($components);
        $current = $root;
        foreach ($components as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                return null;
            }
            $current .= '/' . $component;
            $stat = @lstat($current);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
                return null;
            }
        }

        return self::stat($path);
    }

    /**
     * Removes $path: a file or a link is unlinked (a link, never its
     * target), a directory is emptied the same way, then removed. Returns
     * the number of files and links removed.
     */
    public static function remove(string $path): int
    {
        $stat = @lstat($path);
        if ($stat === false) {
            return 0;
        }
        if (($stat['mode'] & 0170000) !== 0040000) {
            return @unlink($path) ? 1 : 0;
        }
        $removed = 0;
        $names = @scandir($path);
        foreach ($names === false ? [] : $names as $name) {
            if ($name !== '.' && $name !== '..') {
                $removed += self::remove($path . '/' . $name);
            }
        }
        @rmdir($path);

        return $removed;
    }
}
