<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image;

/**
 * Converts a cached raster image to its WEBP sibling: the file with the same
 * directory and name and the extension ".webp".
 *
 * Only the interim 2.x converters implement it; PRX-8 replaces them, and
 * this interface, with the delegation to mfonte/secure-image-converter.
 */
interface WebpConverter
{
    /**
     * @param string $sourceFile absolute path of the cached image
     */
    public function convert(string $sourceFile): WebpConversion;
}
