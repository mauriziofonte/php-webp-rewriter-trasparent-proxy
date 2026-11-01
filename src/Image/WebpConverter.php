<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image;

/**
 * Converts a cached raster image to its WEBP variant, at the path the
 * caller chooses: the cache layout names it (Cache\CacheLocation).
 *
 * Only the interim 2.x converters implement it; PRX-8 replaces them, and
 * this interface, with the delegation to mfonte/secure-image-converter.
 */
interface WebpConverter
{
    /**
     * @param string $sourceFile absolute path of the cached image
     * @param string $targetFile absolute path of the WEBP file to write, atomically; its directory may not exist yet
     */
    public function convert(string $sourceFile, string $targetFile): WebpConversion;
}
