<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Filesystem;

/**
 * A directory created below the system temporary directory and removed with
 * everything in it. Symbolic links are removed, never followed.
 */
final class TemporaryDirectory
{
    /**
     * @var string
     */
    private $path;

    public function __construct(string $prefix = 'proxy-cdn-test-')
    {
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(6));
        if (!mkdir($this->path, 0777, true)) {
            throw new \RuntimeException('Cannot create ' . $this->path);
        }
    }

    /**
     * Absolute path of the directory, or of $relativePath inside it.
     */
    public function path(string $relativePath = ''): string
    {
        return $relativePath === '' ? $this->path : $this->path . DIRECTORY_SEPARATOR . ltrim($relativePath, '/');
    }

    /**
     * Writes $contents to $relativePath, creating its directories.
     *
     * @return string the absolute path of the file
     */
    public function write(string $relativePath, string $contents): string
    {
        $file = $this->path($relativePath);
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $contents);

        return $file;
    }

    public function remove(): void
    {
        self::removePath($this->path);
    }

    private static function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if (is_string($entry) && $entry !== '.' && $entry !== '..') {
                self::removePath($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        rmdir($path);
    }
}
