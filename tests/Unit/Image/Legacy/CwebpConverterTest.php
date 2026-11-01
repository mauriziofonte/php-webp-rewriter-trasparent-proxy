<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Image\Legacy;

use Mfonte\ProxyCdn\Image\Legacy\CwebpConverter;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Runs a fake cwebp: a shell script that records its arguments, fails for
 * sources whose name contains "broken" and otherwise writes "FAKE-WEBP:"
 * followed by the source to the output.
 *
 * @covers \Mfonte\ProxyCdn\Image\Legacy\CwebpConverter
 */
#[CoversClass(CwebpConverter::class)]
final class CwebpConverterTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var MemoryLogger
     */
    private $logger;

    /**
     * @var string
     */
    private $binary;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->logger = new MemoryLogger();
        $this->binary = $this->directory->write('bin/cwebp', implode("\n", [
            '#!/bin/sh',
            'echo "$*" >> "$(dirname "$0")/calls.log"',
            'case "$4" in *broken*) echo "cannot decode" >&2; exit 1;; esac',
            '{ printf "FAKE-WEBP:"; cat "$4"; } > "$6"',
            '',
        ]));
        self::assertTrue(chmod($this->binary, 0755));
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testTheSiblingIsWritten(): void
    {
        $source = $this->directory->write('cache/images/logo.png', 'PNG');

        $conversion = (new CwebpConverter($this->logger, $this->binary))->convert($source, self::target($source));

        $webp = $this->directory->path('cache/images/logo.webp');
        self::assertTrue($conversion->isConverted());
        self::assertSame($webp, $conversion->getPath());
        self::assertSame('FAKE-WEBP:PNG', file_get_contents($webp));
        self::assertSame('644', substr(sprintf('%o', fileperms($webp)), -3));
        self::assertSame([], glob($this->directory->path('cache/images/*.tmp.*')));
        self::assertSame([], $this->logger->getRecords());
    }

    public function testThe2xArgumentsArePassed(): void
    {
        $source = $this->directory->write('cache/my file.png', 'PNG');

        (new CwebpConverter($this->logger, $this->binary))->convert($source, self::target($source));

        $calls = (string) file_get_contents($this->directory->path('bin/calls.log'));
        self::assertMatchesRegularExpression(
            '#\A-quiet -m 4 ' . preg_quote($source, '#') . ' -o ' . preg_quote($this->directory->path('cache/my file.webp'), '#') . '\.tmp\.[0-9a-f]{8}\n\z#',
            $calls
        );
    }

    public function testTheLastExtensionIsReplaced(): void
    {
        $source = $this->directory->write('cache/a.b.jpeg', 'JPEG');

        $conversion = (new CwebpConverter($this->logger, $this->binary))->convert($source, self::target($source));

        self::assertSame($this->directory->path('cache/a.b.webp'), $conversion->getPath());
    }

    public function testAMissingSourceFails(): void
    {
        $conversion = (new CwebpConverter($this->logger, $this->binary))->convert($this->directory->path('missing.png'), self::target($this->directory->path('missing.png')));

        self::assertSame('Source file does not exist', $conversion->getError());
        self::assertSame([], $this->logger->getRecords());
    }

    public function testAMissingBinaryFails(): void
    {
        $source = $this->directory->write('cache/logo.png', 'PNG');

        $conversion = (new CwebpConverter($this->logger, $this->directory->path('bin/missing-cwebp')))->convert($source, self::target($source));

        self::assertFalse($conversion->isConverted());
        self::assertSame('cwebp binary not available in PATH', $conversion->getError());
        self::assertSame([['level' => 'error', 'message' => 'cwebp binary missing', 'context' => ['source' => $source]]], $this->logger->getRecords());
    }

    public function testAFailedRunFails(): void
    {
        $source = $this->directory->write('cache/broken.png', 'PNG');

        $conversion = (new CwebpConverter($this->logger, $this->binary))->convert($source, self::target($source));

        self::assertSame('Conversion failed: output file not created', $conversion->getError());
        self::assertFileDoesNotExist($this->directory->path('cache/broken.webp'));
        $records = $this->logger->recordsWithMessage('cwebp produced no output');
        self::assertCount(1, $records);
        self::assertSame($source, $records[0]['context']['source'] ?? null);
    }

    public function testAFailedRenameFailsAndRemovesTheScratchFile(): void
    {
        $source = $this->directory->write('cache/logo.png', 'PNG');
        $this->directory->write('cache/logo.webp/occupied', 'a directory where the sibling goes');

        $conversion = (new CwebpConverter($this->logger, $this->binary))->convert($source, self::target($source));

        self::assertSame('Conversion failed: atomic rename failed', $conversion->getError());
        self::assertSame([], glob($this->directory->path('cache/*.tmp.*')));
        self::assertCount(1, $this->logger->recordsWithMessage('cwebp rename failed'));
    }

    /**
     * The path the kernel gives the variant of $source in 2.x layout terms:
     * its directory and name with ".webp".
     */
    private static function target(string $source): string
    {
        return dirname($source) . '/' . pathinfo($source, PATHINFO_FILENAME) . '.webp';
    }
}
