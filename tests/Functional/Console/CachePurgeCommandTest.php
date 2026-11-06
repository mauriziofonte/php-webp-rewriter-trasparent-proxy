<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Functional\Console;

use Mfonte\ProxyCdn\Cluster\PeerNotifier;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Config\ConfigLoader;
use Mfonte\ProxyCdn\Console\CachePurgeCommand;
use Mfonte\ProxyCdn\Console\Installation;
use Mfonte\ProxyCdn\Console\ProxyUrls;
use Mfonte\ProxyCdn\Exception\InvalidArgumentException;
use Mfonte\ProxyCdn\Origin\MockTransport;
use Mfonte\ProxyCdn\Security\PurgeAuthenticator;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * cache:purge: the purge of a PURGE, from the command line, here and on
 * the peers.
 *
 * @covers \Mfonte\ProxyCdn\Console\CachePurgeCommand
 * @covers \Mfonte\ProxyCdn\Console\ProxyUrls
 */
#[CoversClass(CachePurgeCommand::class)]
#[CoversClass(ProxyUrls::class)]
final class CachePurgeCommandTest extends TestCase
{
    private const ENV = "SOURCE_ORIGIN=\"https://origin.example.test\"\nPROXY_HOSTS=\"cdn.example.test,other.example.test\"\nCACHEABLE_EXTENSIONS=\"css,js,jpg\"\nUPSTREAM_VERIFY_TLS=true\nLOG_FILE=stderr\nLOG_LEVEL=emergency\nPURGE_KEYS=\"*=purge-secret-0123456789\"\n";

    private const PEER = 'https://peer.example.test';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var MockTransport
     */
    private $transport;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->directory->write('.env', self::ENV);
        $this->transport = new MockTransport();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    /**
     * Without CLUSTER_PEERS the command purges here, as a PURGE would:
     * the entry, its siblings, both tiers and every query string.
     */
    public function testAUrlIsPurgedWithItsSiblings(): void
    {
        $removed = [
            $this->directory->write('public/_cache/cdn.example.test/css/a.css', 'a'),
            $this->directory->write('public/_cache/cdn.example.test/.br/css/a.css', 'a'),
            $this->directory->write('public/_cache/cdn.example.test/.q/v=1/css/a.css', 'a'),
            $this->directory->write('public/_cache/cdn.example.test/.m/css/a.css', 'a'),
            $this->directory->write('public/_cache/cdn.example.test/.m/.meta/css/a.css.json', '{}'),
        ];
        $kept = [
            $this->directory->write('public/_cache/cdn.example.test/css/b.css', 'b'),
            $this->directory->write('public/_cache/other.example.test/css/a.css', 'a'),
        ];

        list($exitCode, $display) = $this->purge(['urls' => ['https://cdn.example.test/css/a.css']], false);

        self::assertSame(0, $exitCode, $display);
        self::assertStringContainsString('✓ https://cdn.example.test/css/a.css: 5 files removed', $display);
        foreach ($removed as $file) {
            self::assertFileDoesNotExist($file);
        }
        foreach ($kept as $file) {
            self::assertFileExists($file);
        }
    }

    /**
     * An image is purged with what the conversions left of it: its
     * variants, and its pending copy with the variants staged for it.
     */
    public function testAnImageIsPurgedWithItsVariantsAndPendingFiles(): void
    {
        $removed = [
            $this->directory->write('public/_cache/cdn.example.test/img/a.jpg', 'a'),
            $this->directory->write('public/_cache/cdn.example.test/.webp/img/a.jpg.webp', 'w'),
            $this->directory->write('public/_cache/cdn.example.test/.avif/img/a.jpg.avif', 'v'),
            $this->directory->write('public/_cache/cdn.example.test/.pending/img/a.jpg', 'p'),
            $this->directory->write('public/_cache/cdn.example.test/.pending/.webp/img/a.jpg.webp', 'w'),
            $this->directory->write('public/_cache/cdn.example.test/.pending/.avif/img/a.jpg.avif', 'v'),
        ];
        $kept = $this->directory->write('public/_cache/cdn.example.test/.pending/img/b.jpg', 'b');

        list($exitCode, $display) = $this->purge(['urls' => ['https://cdn.example.test/img/a.jpg']], false);

        self::assertSame(0, $exitCode, $display);
        self::assertStringContainsString('✓ https://cdn.example.test/img/a.jpg: 6 files removed', $display);
        foreach ($removed as $file) {
            self::assertFileDoesNotExist($file);
        }
        self::assertFileExists($kept);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRefusedUrls(): array
    {
        return [
            'unknown host' => ['https://evil.example.test/css/a.css'],
            'not cacheable' => ['https://cdn.example.test/index.html'],
            'traversal' => ['https://cdn.example.test/%2e%2e/%2e%2e/etc/passwd.css'],
            'relative' => ['/css/a.css'],
            'other scheme' => ['ftp://cdn.example.test/css/a.css'],
        ];
    }

    /**
     * @dataProvider provideRefusedUrls
     */
    #[DataProvider('provideRefusedUrls')]
    public function testUrlsOutsideTheRulesOfLiveTrafficAreRefused(string $url): void
    {
        $this->directory->write('outside/etc/passwd.css', 'keep');

        list($exitCode, $display) = $this->purge(['urls' => [$url]]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('✗ ', $display);
        self::assertFileExists($this->directory->path('outside/etc/passwd.css'));
        self::assertSame([], $this->transport->getRequests());
    }

    public function testThePeersAreToldUnlessLocal(): void
    {
        $this->transport->respond(self::PEER . '/css/a.css?v=1', 200);

        list($exitCode, $display) = $this->purge(['urls' => ['https://cdn.example.test/css/a.css?v=1']]);
        list($localExitCode) = $this->purge(['urls' => ['https://cdn.example.test/css/a.css?v=1'], '--local' => true]);

        self::assertSame(0, $exitCode, $display);
        self::assertSame(0, $localExitCode);
        self::assertStringContainsString('  ✓ ' . self::PEER, $display);
        $requests = $this->transport->getRequests();
        self::assertCount(1, $requests);
        self::assertSame('PURGE', $requests[0]->getMethod());
        self::assertSame('cdn.example.test', $requests[0]->getHeader('host'));
        self::assertSame('1', $requests[0]->getHeader('x-purge-fanout'));
    }

    /**
     * A peer that fails does not undo the local purge: exit code 2.
     */
    public function testAFailingPeerIsExitCode2(): void
    {
        $entry = $this->directory->write('public/_cache/cdn.example.test/css/a.css', 'a');
        $this->transport->respond(self::PEER . '/css/a.css', 503);

        list($exitCode, $display) = $this->purge(['urls' => ['https://cdn.example.test/css/a.css']]);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('✗ ' . self::PEER, $display);
        self::assertFileDoesNotExist($entry);
    }

    /**
     * --all removes one host here; a host directory that is a link loses
     * the link only.
     */
    public function testAllRemovesOneHostHere(): void
    {
        $entry = $this->directory->write('public/_cache/cdn.example.test/css/a.css', 'a');
        $other = $this->directory->write('public/_cache/other.example.test/css/a.css', 'a');

        list($exitCode, $display) = $this->purge(['--all' => true, '--host' => 'CDN.example.test']);
        self::assertSame(0, $exitCode, $display);
        self::assertStringContainsString('cdn.example.test: 1 files removed on this instance', $display);
        self::assertFileDoesNotExist($entry);
        self::assertFileExists($other);
        self::assertSame([], array_values(array_diff((array) scandir($this->directory->path('public/_cache')), ['.', '..', 'other.example.test'])));

        $this->directory->remove();
        $this->directory = new TemporaryDirectory();
        $this->directory->write('.env', self::ENV);
        $outside = $this->directory->write('outside/css/a.css', 'keep');
        mkdir($this->directory->path('public/_cache'), 0755, true);
        symlink($this->directory->path('outside'), $this->directory->path('public/_cache/cdn.example.test'));
        self::assertSame(0, $this->purge(['--all' => true, '--host' => 'cdn.example.test'])[0]);
        self::assertFileExists($outside);
        self::assertFalse(is_link($this->directory->path('public/_cache/cdn.example.test')));

        self::assertSame(1, $this->purge(['--all' => true, '--host' => 'evil.example.test'])[0]);
        self::assertSame(1, $this->purge(['--all' => true])[0]);
        self::assertSame(1, $this->purge([])[0]);
        self::assertSame([], $this->transport->getRequests());
    }

    /**
     * The guard on the directory of --all --host throws an exception of
     * the package (InvalidArgumentException, an ExceptionInterface), never
     * a bare SPL one.
     */
    public function testANameThatIsNoHostIsRefusedWithAPackageException(): void
    {
        $directoryName = \Closure::bind(static function (string $host): string {
            return CachePurgeCommand::directoryName($host);
        }, null, CachePurgeCommand::class);
        self::assertSame('cdn.example.test', $directoryName('cdn.example.test'));

        $this->expectException(InvalidArgumentException::class);

        $directoryName('../outside');
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function purge(array $input, bool $withPeers = true): array
    {
        $installation = new Installation(new ConfigLoader($this->directory->path('.env'), null, static function (string $name): ?string {
            return null;
        }), $this->directory->path());
        $transport = $this->transport;
        $peers = static function (Config $config, LoggerInterface $logger) use ($transport, $withPeers): ?PeerNotifier {
            return $withPeers
                ? new PeerNotifier([self::PEER], PurgeAuthenticator::fromConfig($config), $config->getHostAllowlist(), $transport, null, $logger)
                : PeerNotifier::fromConfig($config, $logger);
        };
        $tester = new CommandTester(new CachePurgeCommand($installation, $peers));
        $exitCode = $tester->execute($input);

        return [$exitCode, $tester->getDisplay()];
    }
}
