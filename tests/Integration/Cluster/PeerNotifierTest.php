<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Cluster;

use Mfonte\ProxyCdn\Cluster\PeerNotifier;
use Mfonte\ProxyCdn\Security\PurgeAuthenticator;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\ScriptedServer;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\TestCertificate;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * PeerNotifier::fromConfig() against TLS peers (ScriptedServer): the
 * certificate of each peer is verified, a failing peer does not stop the
 * others, and a slow peer costs CLUSTER_TIMEOUT at most.
 *
 * @covers \Mfonte\ProxyCdn\Cluster\PeerNotifier
 */
#[CoversClass(PeerNotifier::class)]
final class PeerNotifierTest extends TestCase
{
    private const SECRET = 'cluster-secret-0123456789';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var TestCertificate
     */
    private $certificate;

    /**
     * @var list<ScriptedServer>
     */
    private $servers = [];

    protected function set_up(): void
    {
        if (!TestCertificate::isSupported()) {
            self::markTestSkipped('ext-openssl is not available');
        }
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        mkdir($this->directory->path('tls'));
        $this->certificate = TestCertificate::create($this->directory->path('tls'));
        for ($i = 0; $i < 2; ++$i) {
            mkdir($this->directory->path('peer' . $i));
            $this->servers[] = ScriptedServer::start($this->directory->path('peer' . $i), $this->certificate->getServerPem());
        }
    }

    protected function tear_down(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->directory->remove();
        parent::tear_down();
    }

    public function testTrustedPeersReceiveTheSignedPurge(): void
    {
        $notifier = $this->notifier(['CLUSTER_CA_FILE' => $this->certificate->getCaPem()]);

        $results = $notifier->notify('cdn.example.test', '/echo?v=1', 'req-7');

        self::assertSame([$this->peer(0) => true, $this->peer(1) => true], $results);
        foreach ($this->servers as $server) {
            $received = $server->getLastRequest();
            self::assertSame('PURGE', $received['method']);
            self::assertSame('/echo?v=1', $received['target']);
            self::assertSame('cdn.example.test', $received['headers']['host'] ?? null);
            self::assertSame('req-7', $received['headers']['x-request-id'] ?? null);
            $request = Request::create('https://cdn.example.test/echo?v=1', 'PURGE', [], [], [], [
                'HTTP_X_PURGE_FANOUT' => $received['headers']['x-purge-fanout'] ?? '',
                'HTTP_X_PURGE_TIMESTAMP' => $received['headers']['x-purge-timestamp'] ?? '',
                'HTTP_X_PURGE_SIGNATURE' => $received['headers']['x-purge-signature'] ?? '',
            ]);
            self::assertSame(PurgeAuthenticator::SCHEME_FANOUT, (new PurgeAuthenticator([['origin.example.test', self::SECRET]]))->authenticate($request, 'origin.example.test', time()));
        }
    }

    /**
     * Without CLUSTER_CA_FILE the system store does not trust the test
     * certificate: the purge is not delivered.
     */
    public function testAnUntrustedPeerIsAFailure(): void
    {
        $logger = new MemoryLogger();

        $results = $this->notifier([], $logger)->notify('cdn.example.test', '/echo', 'r');

        self::assertSame([$this->peer(0) => false, $this->peer(1) => false], $results);
        self::assertSame([], $this->servers[0]->getRequests());
        self::assertSame([], $this->servers[1]->getRequests());
        self::assertSame('tls', $logger->recordsWithMessage('peer purge failed')[0]['context']['outcome'] ?? null);
    }

    /**
     * A peer that refuses the connection fails alone; a peer that does not
     * answer costs CLUSTER_TIMEOUT, not more.
     */
    public function testAFailingOrSlowPeerIsBounded(): void
    {
        $closed = $this->peer(1);
        $this->servers[1]->stop();
        $notifier = $this->notifier(['CLUSTER_CA_FILE' => $this->certificate->getCaPem(), 'CLUSTER_TIMEOUT' => '1']);

        $results = $notifier->notify('cdn.example.test', '/echo', 'r');
        $started = microtime(true);
        $slow = $notifier->notify('cdn.example.test', '/bytes/10?header_delay=5000', 'r');
        $elapsed = microtime(true) - $started;

        self::assertSame([$this->peer(0) => true, $closed => false], $results);
        self::assertFalse($slow[$this->peer(0)]);
        self::assertLessThan(3.0, $elapsed);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function notifier(array $overrides, ?MemoryLogger $logger = null): PeerNotifier
    {
        $notifier = PeerNotifier::fromConfig(TestConfig::create($overrides + [
            'PURGE_KEYS' => 'origin.example.test=' . self::SECRET,
            'CLUSTER_PEERS' => $this->peer(0) . ',' . $this->peer(1),
        ]), $logger ?? new MemoryLogger());
        self::assertNotNull($notifier);

        return $notifier;
    }

    private function peer(int $index): string
    {
        return 'https://' . ($index === 0 ? '127.0.0.1' : 'localhost') . ':' . $this->servers[$index]->getPort();
    }
}
