<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image\Legacy;

use Mfonte\ProxyCdn\Image\WebpConversion;
use Mfonte\ProxyCdn\Image\WebpConverter;
use Psr\Log\LoggerInterface;

/**
 * Interim port of the 2.x "native" conversion: runs the cwebp binary on
 * the cached image, writing to a scratch file that is then renamed over the
 * WEBP variant, so that concurrent requests never read a partial file.
 *
 * The binary runs without isolation, time limit or input validation, as in
 * 2.x.
 *
 * @internal replaced by the mfonte/secure-image-converter integration in PRX-8; do not build on it
 */
final class CwebpConverter implements WebpConverter
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var string
     */
    private $binary;

    /**
     * @param string $binary name looked up in PATH, or path, of the cwebp binary
     */
    public function __construct(LoggerInterface $logger, string $binary = 'cwebp')
    {
        $this->logger = $logger;
        $this->binary = $binary;
    }

    public function convert(string $sourceFile, string $targetFile): WebpConversion
    {
        if (!is_file($sourceFile)) {
            return WebpConversion::failed('Source file does not exist');
        }

        if (!$this->binaryExists()) {
            $this->logger->error('cwebp binary missing', ['source' => $sourceFile]);

            return WebpConversion::failed('cwebp binary not available in PATH');
        }

        $outputFile = $targetFile;
        if (!is_dir(dirname($outputFile)) && !@mkdir(dirname($outputFile), 0755, true) && !is_dir(dirname($outputFile))) {
            return WebpConversion::failed('Conversion failed: cannot create the variant directory');
        }
        $scratch = $outputFile . '.tmp.' . bin2hex(random_bytes(4));

        $command = escapeshellarg($this->binary) . ' -quiet -m 4 ' . escapeshellarg($sourceFile) . ' -o ' . escapeshellarg($scratch);
        self::run($command);

        if (!is_file($scratch)) {
            $this->logger->error('cwebp produced no output', ['source' => $sourceFile, 'cmd' => $command]);

            return WebpConversion::failed('Conversion failed: output file not created');
        }

        if (!@rename($scratch, $outputFile)) {
            @unlink($scratch);
            $this->logger->error('cwebp rename failed', ['source' => $sourceFile, 'target' => $outputFile]);

            return WebpConversion::failed('Conversion failed: atomic rename failed');
        }
        @chmod($outputFile, 0644);

        return WebpConversion::converted($outputFile);
    }

    private function binaryExists(): bool
    {
        return self::run('command -v ' . escapeshellarg($this->binary)) !== '';
    }

    /**
     * Runs a shell command with its standard error discarded, as the 2.x
     * wrapExec() did, and returns its trimmed output; the empty string when
     * it fails or no function can run it.
     *
     * @param string $command an already escaped command line
     */
    private static function run(string $command): string
    {
        if (function_exists('exec')) {
            $output = [];
            $exitCode = 0;
            exec($command . ' 2>/dev/null', $output, $exitCode);

            return $exitCode === 0 ? implode(PHP_EOL, array_map('trim', $output)) : '';
        }
        if (function_exists('shell_exec')) {
            $output = shell_exec($command . ' 2>/dev/null');

            return is_string($output) ? trim($output) : '';
        }

        return '';
    }
}
