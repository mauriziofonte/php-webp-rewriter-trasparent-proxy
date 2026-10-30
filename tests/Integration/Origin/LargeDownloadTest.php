<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Origin;

use Mfonte\ProxyCdn\Origin\CurlTransport;
use Mfonte\ProxyCdn\Origin\ResponseCollector;
use Mfonte\ProxyCdn\Origin\StreamTransport;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Memory stays flat however large the body: the peak of a process that
 * downloads PROXY_CDN_LARGE_DOWNLOAD_MB megabytes (32 by default; the
 * PRX-4 acceptance run uses 500) is the peak of one that downloads 1 MB,
 * within a small margin. Each download runs download-probe.php in its own
 * PHP process; the body is deleted afterwards.
 *
 * @covers \Mfonte\ProxyCdn\Origin\CurlTransport
 * @covers \Mfonte\ProxyCdn\Origin\ResponseCollector
 * @covers \Mfonte\ProxyCdn\Origin\StreamTransport
 *
 * @group large-download
 */
#[CoversClass(CurlTransport::class)]
#[CoversClass(ResponseCollector::class)]
#[CoversClass(StreamTransport::class)]
#[Group('large-download')]
final class LargeDownloadTest extends TestCase
{
    private const MEGABYTE = 1048576;

    /**
     * Growth of Zend's peak allowed between the small and the large download.
     */
    private const ZEND_MARGIN = 2 * self::MEGABYTE;

    /**
     * Growth of the peak resident set size allowed between them.
     */
    private const RSS_MARGIN = 8 * self::MEGABYTE;

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var ScriptedServer
     */
    private $server;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        mkdir($this->directory->path('server'));
        mkdir($this->directory->path('bodies'));
        $this->server = ScriptedServer::start($this->directory->path('server'));
    }

    protected function tear_down(): void
    {
        $this->server->stop();
        $this->directory->remove();
        parent::tear_down();
    }

    /**
     * @dataProvider provideTransports
     */
    #[DataProvider('provideTransports')]
    public function testMemoryStaysFlat(string $transport, string $mode): void
    {
        if ($transport === 'curl' && !CurlTransport::isAvailable()) {
            self::markTestSkipped('ext-curl is not available');
        }
        $size = self::largeSize();

        $small = $this->probe($transport, self::MEGABYTE, $mode);
        $large = $this->probe($transport, $size, $mode);

        self::assertSame(ScriptedServer::payloadHash(self::MEGABYTE), $small['hash']);
        self::assertSame(ScriptedServer::payloadHash($size), $large['hash']);
        self::assertLessThan(self::ZEND_MARGIN, $large['peak'] - $small['peak'], self::report($transport, $small, $large));
        if ($small['hwm'] !== null && $large['hwm'] !== null) {
            self::assertLessThan(self::RSS_MARGIN, $large['hwm'] - $small['hwm'], self::report($transport, $small, $large));
        }
        self::assertSame(['.', '..'], scandir($this->directory->path('bodies')), 'the bodies are deleted');
        if (getenv('PROXY_CDN_LARGE_DOWNLOAD_MB') !== false) {
            fwrite(STDERR, self::report($transport . ', ' . $mode, $small, $large) . "\n");
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideTransports(): array
    {
        return [
            'curl, Content-Length' => ['curl', 'length'],
            'curl, chunked gzip' => ['curl', 'chunked&gzip=1'],
            'streams, Content-Length' => ['stream', 'length'],
            'streams, chunked gzip' => ['stream', 'chunked&gzip=1'],
        ];
    }

    private static function largeSize(): int
    {
        $megabytes = getenv('PROXY_CDN_LARGE_DOWNLOAD_MB');

        return (is_string($megabytes) && ctype_digit($megabytes) && (int) $megabytes > 1 ? (int) $megabytes : 32) * self::MEGABYTE;
    }

    /**
     * @return array{outcome: string, error: string, size: int|null, hash: string|null, peak: int, hwm: int|null}
     */
    private function probe(string $transport, int $size, string $mode): array
    {
        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            '-d',
            'memory_limit=64M',
            dirname(__DIR__, 2) . '/Fixtures/Http/download-probe.php',
            $transport,
            $this->server->getBaseUrl() . '/bytes/' . $size . '?mode=' . $mode,
            $this->directory->path('bodies'),
        ]));
        $output = [];
        exec($command . ' 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
        /** @var array{outcome: string, error: string, size: int|null, hash: string|null, peak: int, hwm: int|null}|null $result */
        $result = json_decode(implode("\n", $output), true);
        self::assertIsArray($result, implode("\n", $output));
        self::assertSame('success', $result['outcome'], $result['error']);
        self::assertSame($size, $result['size']);

        return $result;
    }

    /**
     * @param array{peak: int, hwm: int|null, size: int|null} $small
     * @param array{peak: int, hwm: int|null, size: int|null} $large
     */
    private static function report(string $label, array $small, array $large): string
    {
        return sprintf(
            '%s: %d bytes -> Zend peak %d, VmHWM %s; %d bytes -> Zend peak %d, VmHWM %s',
            $label,
            (int) $small['size'],
            $small['peak'],
            $small['hwm'] === null ? 'n/a' : (string) $small['hwm'],
            (int) $large['size'],
            $large['peak'],
            $large['hwm'] === null ? 'n/a' : (string) $large['hwm']
        );
    }
}
