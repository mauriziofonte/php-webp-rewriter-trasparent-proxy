<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cache;

/**
 * CacheStorage on the local filesystem.
 *
 * write() goes through a temporary file created next to the target and
 * renamed over it, which is atomic on POSIX filesystems because both live in
 * the same directory. Temporary files are named ".mftpcdn.<random>", a name
 * that public/.htaccess refuses to serve during the short window before the
 * rename. Files are created with mode 0644 and directories with mode 0755.
 */
final class FilesystemStorage implements CacheStorage
{
    private const TEMPORARY_PREFIX = '.mftpcdn.';

    public function isFile(string $path): bool
    {
        return is_file($path);
    }

    public function read(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function size(string $path): ?int
    {
        $size = @filesize($path);

        return $size === false ? null : $size;
    }

    public function modifiedAt(string $path): ?int
    {
        $time = @filemtime($path);

        return $time === false ? null : $time;
    }

    public function write(string $path, string $contents): bool
    {
        $directory = dirname($path);
        if (!$this->ensureDirectory($directory)) {
            return false;
        }

        $temporary = @tempnam($directory, self::TEMPORARY_PREFIX);
        if ($temporary === false) {
            return false;
        }

        $written = @file_put_contents($temporary, $contents, LOCK_EX);
        if ($written !== strlen($contents)) {
            @unlink($temporary);

            return false;
        }

        @chmod($temporary, 0644);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return is_file($path);
    }

    public function import(string $path, string $source): bool
    {
        $directory = dirname($path);
        if (!$this->ensureDirectory($directory)) {
            return false;
        }
        $input = @fopen($source, 'rb');
        if ($input === false) {
            return false;
        }
        $temporary = @tempnam($directory, self::TEMPORARY_PREFIX);
        $output = $temporary === false ? false : @fopen($temporary, 'wb');
        if ($temporary === false || $output === false) {
            fclose($input);
            if ($temporary !== false) {
                @unlink($temporary);
            }

            return false;
        }
        $copied = @stream_copy_to_stream($input, $output);
        $complete = $copied !== false && @fflush($output) && $copied === @filesize($source);
        fclose($input);
        fclose($output);
        @chmod($temporary, 0644);
        if (!$complete || !@rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return is_file($path);
    }

    public function listDirectory(string $directory): array
    {
        $names = is_dir($directory) ? @scandir($directory) : false;
        if ($names === false) {
            return [];
        }

        return array_values(array_diff($names, ['.', '..']));
    }

    public function ensureDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }
        @mkdir($directory, 0755, true);

        return is_dir($directory);
    }

    public function touch(string $path): bool
    {
        return @touch($path);
    }

    public function delete(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
