<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Image;

use Mfonte\ProxyCdn\Image\WebpConversion;
use Mfonte\ProxyCdn\Image\WebpConverter;

/**
 * Writes "FAKE-WEBP:" followed by the source to the WEBP sibling, or fails
 * with a fixed error for sources whose name contains "broken".
 */
final class FakeWebpConverter implements WebpConverter
{
    /**
     * @var list<string>
     */
    private $sources = [];

    public function convert(string $sourceFile, string $targetFile): WebpConversion
    {
        $this->sources[] = $sourceFile;
        if (strpos(basename($sourceFile), 'broken') !== false) {
            return WebpConversion::failed('Fake conversion failed');
        }
        $target = $targetFile;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        file_put_contents($target, 'FAKE-WEBP:' . (string) file_get_contents($sourceFile));

        return WebpConversion::converted($target);
    }

    /**
     * The files converted so far, oldest first.
     *
     * @return list<string>
     */
    public function getSources(): array
    {
        return $this->sources;
    }
}
