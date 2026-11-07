<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Compression;

use Mfonte\ProxyCdn\Cache\CacheLocation;
use Mfonte\ProxyCdn\Cache\CacheStorage;

/**
 * Writes the Brotli and gzip siblings of a compressible cache entry
 * (finding S-6), which Apache and nginx send to the clients that accept
 * them, without compressing on every hit.
 *
 * gzip needs ext-zlib, Brotli the brotli extension; a sibling whose
 * extension is missing is not written. An entry smaller than MIN_BYTES or
 * larger than MAX_BYTES is left alone, and a sibling is kept only when it
 * is smaller than the entry. Writes go through CacheStorage, so they are
 * atomic.
 */
final class Precompressor
{
    /**
     * The extensions whose files are worth compressing: text, SVG, and the
     * uncompressed image and font formats.
     */
    public const COMPRESSIBLE_EXTENSIONS = ['css', 'js', 'mjs', 'json', 'map', 'svg', 'txt', 'xml', 'ico', 'ttf', 'ttc', 'otf', 'eot'];

    /**
     * Below this size the saving does not pay for the headers.
     */
    public const MIN_BYTES = 256;

    /**
     * Above this size the entry is not compressed: it would be read into
     * memory.
     */
    public const MAX_BYTES = 16777216;

    /**
     * @var CacheStorage
     */
    private $storage;

    /**
     * @var bool
     */
    private $enabled;

    /**
     * @param bool $enabled PRECOMPRESS
     */
    public function __construct(CacheStorage $storage, bool $enabled = true)
    {
        $this->storage = $storage;
        $this->enabled = $enabled;
    }

    /**
     * Whether files of $extension get compressed siblings.
     *
     * @param string $extension lower-case, without dot
     */
    public static function isCompressible(string $extension): bool
    {
        return in_array($extension, self::COMPRESSIBLE_EXTENSIONS, true);
    }

    /**
     * Writes the siblings of the entry of $location, typed by $extension.
     *
     * @return list<string> the encodings written (CacheLocation::ENCODING_*)
     */
    public function compress(CacheLocation $location, string $extension): array
    {
        if (!$this->enabled || !self::isCompressible($extension)) {
            return [];
        }
        $size = $this->storage->size($location->getFile());
        if ($size === null || $size < self::MIN_BYTES || $size > self::MAX_BYTES) {
            return [];
        }
        $contents = $this->storage->read($location->getFile());
        if ($contents === null) {
            return [];
        }

        $written = [];
        foreach (CacheLocation::ENCODINGS as $encoding) {
            $compressed = self::encode($encoding, $contents);
            if ($compressed !== null && strlen($compressed) < strlen($contents) && $this->storage->write($location->getEncodedFile($encoding), $compressed)) {
                $written[] = $encoding;
            }
        }

        return $written;
    }

    private static function encode(string $encoding, string $contents): ?string
    {
        if ($encoding === CacheLocation::ENCODING_GZIP && function_exists('gzencode')) {
            $compressed = gzencode($contents, 9);

            return is_string($compressed) ? $compressed : null;
        }
        if ($encoding === CacheLocation::ENCODING_BROTLI && function_exists('brotli_compress')) {
            /** @var mixed $compressed the brotli extension has no stubs */
            $compressed = call_user_func('brotli_compress', $contents, 11);

            return is_string($compressed) ? $compressed : null;
        }

        return null;
    }
}
