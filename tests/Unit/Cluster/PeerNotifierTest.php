<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Cluster;

use Mfonte\ProxyCdn\Cluster\PeerNotifier;
use Mfonte\ProxyCdn\Origin\HttpResponse;
use Mfonte\ProxyCdn\Origin\MockTransport;
use Mfonte\ProxyCdn\Security\PurgeAuthenticator;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Cluster\PeerNotifier
 */
#[CoversClass(PeerNotifier::class)]
final class PeerNotifierTest extends TestCase
{
    private const SECRET = 'cluster-secret-0123456789';

    private const PEER_A = 'https://peer-a.example.test';

    private const PEER_B = 'https://peer-b.example.test:8443';

    /**
     * With CLUSTER_PEERS empty there is no notifier: a single instance
     * sends nothing.
     */
    public function testNoPeersMeansNoNotifier(): void
    {
        self::assertNull(PeerNotifier::fromConfig(TestConfig::create(['PURGE_KEYS' => 'origin.example.test=' . self::SECRET]), new MemoryLogger()));
        $notifier = PeerNotifier::fromConfig(TestConfig::create([
            'PURGE_KEYS' => 'origin.example.test=' . self::SECRET,
            'CLUSTER_PEERS' => self::PEER_A . ',' . self::PEER_B . '/',
        ]), new MemoryLogger());
        self::assertNotNull($notifier);
        self::assertSame([self::PEER_A, self::PEER_B], $notifier->getPeers());
    }

    /**
     * Each peer gets a PURGE of the same target and Host, signed for a
     * fan-out, that its PurgeAuthenticator accepts as SCHEME_FANOUT.
     */
    public function testEveryPeerGetsASignedFanout(): void
    {
        $transport = new MockTransport();
        $transport->respond(self::PEER_A . '/css/a.css?v=1', 200);
        $transport->respond(self::PEER_B . '/css/a.css?v=1', 200);

        $results = self::notifier($transport, new MemoryLogger())->notify('CDN.example.test', '/css/a.css?v=1', 'req-42');

        self::assertSame([self::PEER_A => true, self::PEER_B => true], $results);
        $requests = $transport->getRequests();
        self::assertCount(2, $requests);
        foreach ($requests as $request) {
            self::assertSame('PURGE', $request->getMethod());
            self::assertSame('cdn.example.test', $request->getHeader('host'));
            self::assertSame('1', $request->getHeader('x-purge-fanout'));
            self::assertSame('req-42', $request->getHeader('x-request-id'));
            self::assertNull($request->getHeader('authorization'));
            $received = Request::create('https://cdn.example.test/css/a.css?v=1', 'PURGE', [], [], [], [
                'HTTP_X_PURGE_FANOUT' => (string) $request->getHeader('x-purge-fanout'),
                'HTTP_X_PURGE_TIMESTAMP' => (string) $request->getHeader('x-purge-timestamp'),
                'HTTP_X_PURGE_SIGNATURE' => (string) $request->getHeader('x-purge-signature'),
            ]);
            $peer = new PurgeAuthenticator([['origin.example.test', self::SECRET]]);
            self::assertSame(PurgeAuthenticator::SCHEME_FANOUT, $peer->authenticate($received, 'origin.example.test', time()));
        }
    }

    /**
     * A peer that answers anything but 200, or cannot be reached, is a
     * failure that is logged; the other peers are still asked.
     */
    public function testAFailingPeerDoesNotStopTheOthers(): void
    {
        $transport = new MockTransport();
        $transport->fail(self::PEER_A . '/css/a.css', HttpResponse::OUTCOME_CONNECT, 7, 'refused');
        $transport->respond(self::PEER_B . '/css/a.css', 200);
        $logger = new MemoryLogger();

        $results = self::notifier($transport, $logger)->notify('cdn.example.test', '/css/a.css', 'req-1');

        self::assertSame([self::PEER_A => false, self::PEER_B => true], $results);
        self::assertCount(1, $logger->recordsWithMessage('peer purge failed'));
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($logger->recordsWithMessage('peer purge failed')));
    }

    public function testARefusingPeerIsAFailure(): void
    {
        $transport = new MockTransport();
        $transport->respond(self::PEER_A . '/css/a.css', 401);
        $transport->respond(self::PEER_B . '/css/a.css', 200);

        self::assertSame([self::PEER_A => false, self::PEER_B => true], self::notifier($transport, new MemoryLogger())->notify('cdn.example.test', '/css/a.css', 'r'));
    }

    /**
     * Without a purge key of the origin of the host nothing can be signed,
     * so nothing is sent.
     */
    public function testNothingIsSentWithoutAKeyOfTheOrigin(): void
    {
        $transport = new MockTransport();
        $logger = new MemoryLogger();
        $notifier = new PeerNotifier(
            [self::PEER_A],
            new PurgeAuthenticator([['other.example.test', self::SECRET]]),
            TestConfig::create()->getHostAllowlist(),
            $transport,
            null,
            $logger
        );

        self::assertSame([self::PEER_A => false], $notifier->notify('cdn.example.test', '/css/a.css', 'r'));
        self::assertSame([self::PEER_A => false], $notifier->notify('unknown.example.test', '/css/a.css', 'r'));
        self::assertSame([], $transport->getRequests());
        self::assertCount(2, $logger->recordsWithMessage('purge not fanned out: no purge key covers the host'));
    }

    private static function notifier(MockTransport $transport, MemoryLogger $logger): PeerNotifier
    {
        return new PeerNotifier(
            [self::PEER_A, self::PEER_B],
            new PurgeAuthenticator([['origin.example.test', self::SECRET]]),
            TestConfig::create()->getHostAllowlist(),
            $transport,
            null,
            $logger
        );
    }
}
