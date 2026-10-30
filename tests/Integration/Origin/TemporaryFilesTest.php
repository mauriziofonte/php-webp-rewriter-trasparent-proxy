<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Origin;

use Mfonte\ProxyCdn\Origin\CurlTransport;
use Mfonte\ProxyCdn\Origin\ResponseBody;
use Mfonte\ProxyCdn\Origin\ResponseCollector;
use Mfonte\ProxyCdn\Origin\TemporaryFiles;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Body files do not outlive the PHP request, even when a fatal error
 * skips the destructors.
 *
 * @covers \Mfonte\ProxyCdn\Origin\TemporaryFiles
 */
#[CoversClass(TemporaryFiles::class)]
final class TemporaryFilesTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testDeleteAllDeletesWhatIsStillTracked(): void
    {
        $kept = $this->directory->path(ResponseCollector::FILE_PREFIX . 'kept');
        $tracked = $this->directory->path(ResponseCollector::FILE_PREFIX . 'tracked');
        touch($kept);
        touch($tracked);
        TemporaryFiles::track($kept);
        TemporaryFiles::track($tracked);
        TemporaryFiles::forget($kept);

        TemporaryFiles::deleteAll();

        self::assertFileExists($kept);
        self::assertFileDoesNotExist($tracked);
    }

    /**
     * Shutdown functions run before the destructors of the objects still
     * alive: an owner whose file is gone must not unlink it again, which
     * a strict error handler would turn into an exception.
     */
    public function testDeletingTwiceIsQuiet(): void
    {
        $path = $this->directory->path(ResponseCollector::FILE_PREFIX . 'body');
        file_put_contents($path, 'body');
        $body = new ResponseBody($path, 4);
        TemporaryFiles::track($path);
        $raised = [];
        set_error_handler(static function (int $level, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });

        try {
            TemporaryFiles::deleteAll();
            $body->delete();
        } finally {
            restore_error_handler();
        }

        self::assertFileDoesNotExist($path);
        self::assertSame([], $raised);
    }

    /**
     * @dataProvider provideTransports
     */
    #[DataProvider('provideTransports')]
    public function testAFatalErrorLeavesNoBodyFile(string $transport): void
    {
        if ($transport === 'curl' && !CurlTransport::isAvailable()) {
            self::markTestSkipped('ext-curl is not available');
        }
        mkdir($this->directory->path('server'));
        mkdir($this->directory->path('bodies'));
        $server = ScriptedServer::start($this->directory->path('server'));

        try {
            $command = implode(' ', array_map('escapeshellarg', [
                PHP_BINARY,
                '-d',
                'memory_limit=16M',
                dirname(__DIR__, 2) . '/Fixtures/Http/fatal-probe.php',
                $transport,
                $server->getBaseUrl() . '/bytes/20971520',
                $this->directory->path('bodies'),
            ]));
            $output = [];
            exec($command . ' 2>&1', $output, $status);
        } finally {
            $server->stop();
        }

        self::assertNotSame(0, $status, implode("\n", $output));
        self::assertStringContainsString('last error: Allowed memory size', implode("\n", $output));
        self::assertSame(['.', '..'], scandir($this->directory->path('bodies')), 'the body file is deleted');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideTransports(): array
    {
        return [
            'curl' => ['curl'],
            'streams' => ['stream'],
        ];
    }
}
