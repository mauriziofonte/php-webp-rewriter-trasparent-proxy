<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * The body of a response, streamed by the transport to a temporary file.
 *
 * The object owns the file: it is deleted when the object is destroyed,
 * unless moveTo() has moved it elsewhere first. Keep the object, or move
 * the file, for as long as the body is needed.
 */
final class ResponseBody
{
    /**
     * @var string
     */
    private $path;

    /**
     * @var int
     */
    private $size;

    /**
     * @var bool
     */
    private $owned = true;

    /**
     * @param string $path the temporary file, which this object now owns
     * @param int    $size its size in bytes
     */
    public function __construct(string $path, int $size)
    {
        $this->path = $path;
        $this->size = $size;
    }

    public function __destruct()
    {
        $this->delete();
    }

    /**
     * The path of the file: the temporary file, or the target of moveTo().
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Size in bytes.
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * The whole body as a string. It is read into memory: prefer moveTo()
     * or getPath() for large bodies.
     *
     * @return string|null null when the file cannot be read (moved away, deleted)
     */
    public function getContents(): ?string
    {
        $contents = @file_get_contents($this->path);

        return is_string($contents) ? $contents : null;
    }

    /**
     * Moves the file to $target with a rename, which is atomic when both
     * paths are on the same filesystem; the file is then no longer deleted
     * by this object. Replaces $target when it exists.
     *
     * @return bool false when the rename failed; the temporary file is then kept
     */
    public function moveTo(string $target): bool
    {
        if (!$this->owned || !@rename($this->path, $target)) {
            return false;
        }
        $this->path = $target;
        $this->owned = false;

        return true;
    }

    /**
     * Deletes the temporary file now. Does nothing once the file has been
     * moved or deleted.
     */
    public function delete(): void
    {
        if ($this->owned) {
            $this->owned = false;
            @unlink($this->path);
        }
    }
}
