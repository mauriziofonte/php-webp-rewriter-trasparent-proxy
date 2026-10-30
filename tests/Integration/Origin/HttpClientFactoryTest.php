<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Origin;

use Mfonte\ProxyCdn\Origin\CurlTransport;
use Mfonte\ProxyCdn\Origin\HttpClientFactory;
use Mfonte\ProxyCdn\Origin\StreamTransport;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The factory picks CurlTransport, and StreamTransport once the curl
 * functions are disabled; both fetch the same body. Each case runs
 * factory-probe.php in a separate PHP process.
 *
 * @covers \Mfonte\ProxyCdn\Origin\HttpClientFactory
 */
#[CoversClass(HttpClientFactory::class)]
final class HttpClientFactoryTest extends TestCase
{
    private const CURL_FUNCTIONS = 'curl_init,curl_exec,curl_multi_init,curl_multi_exec,curl_setopt_array';

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

    public function testCurlIsPreferred(): void
    {
        if (!CurlTransport::isAvailable()) {
            self::markTestSkipped('ext-curl is not available');
        }

        self::assertSame(
            ['transport' => CurlTransport::class, 'outcome' => 'success', 'hash' => ScriptedServer::payloadHash(300000)],
            $this->probe([])
        );
    }

    public function testStreamsAreTheFallbackWithoutCurl(): void
    {
        self::assertSame(
            ['transport' => StreamTransport::class, 'outcome' => 'success', 'hash' => ScriptedServer::payloadHash(300000)],
            $this->probe(['-d', 'disable_functions=' . self::CURL_FUNCTIONS])
        );
        self::assertSame(['.', '..'], scandir($this->directory->path('bodies')));
    }

    /**
     * @param list<string> $phpOptions
     *
     * @return array<mixed>
     */
    private function probe(array $phpOptions): array
    {
        $command = array_merge(
            [PHP_BINARY],
            $phpOptions,
            [dirname(__DIR__, 2) . '/Fixtures/Http/factory-probe.php', $this->server->getBaseUrl() . '/bytes/300000?gzip=1', $this->directory->path('bodies')]
        );
        $output = [];
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true);
        self::assertIsArray($result, implode("\n", $output));

        return $result;
    }
}
