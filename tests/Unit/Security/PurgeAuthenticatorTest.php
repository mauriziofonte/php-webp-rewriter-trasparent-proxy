<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Security;

use Mfonte\ProxyCdn\Security\PurgeAuthenticator;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Security\PurgeAuthenticator
 */
#[CoversClass(PurgeAuthenticator::class)]
final class PurgeAuthenticatorTest extends TestCase
{
    private const NOW = 1790000000;

    private const ORIGIN_SECRET = 'origin-secret-0123456789';

    private const OTHER_SECRET = 'other-secret-0123456789';

    private const ANY_SECRET = 'any-origin-secret-0123456789';

    /**
     * A purge without a key of the origin is refused (risk L-7: 2.x
     * purged for anyone).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testARequestWithoutCredentialsIsRefusedL7(): void
    {
        self::assertNull(self::authenticator()->authenticate(self::purge(), 'origin.example.test', self::NOW));
        self::assertTrue((new PurgeAuthenticator([]))->isDisabled());
        self::assertNull((new PurgeAuthenticator([]))->authenticate(self::purge(['HTTP_AUTHORIZATION' => 'Bearer ' . self::ORIGIN_SECRET]), 'origin.example.test', self::NOW));
    }

    public function testABearerKeyOfTheOriginIsAccepted(): void
    {
        $request = self::purge(['HTTP_AUTHORIZATION' => 'Bearer ' . self::ORIGIN_SECRET]);

        self::assertSame(PurgeAuthenticator::SCHEME_BEARER, self::authenticator()->authenticate($request, 'ORIGIN.example.test', self::NOW));
    }

    /**
     * A key opens the origins of its scope only (risk L-13).
     */
    public function testAKeyOfAnotherOriginIsRefused(): void
    {
        $request = self::purge(['HTTP_AUTHORIZATION' => 'Bearer ' . self::OTHER_SECRET]);

        self::assertNull(self::authenticator()->authenticate($request, 'origin.example.test', self::NOW));
        self::assertSame(PurgeAuthenticator::SCHEME_BEARER, self::authenticator()->authenticate($request, 'other.example.test', self::NOW));
    }

    public function testAKeyOfEveryOriginIsAccepted(): void
    {
        $request = self::purge(['HTTP_AUTHORIZATION' => 'bearer ' . self::ANY_SECRET]);

        self::assertSame(PurgeAuthenticator::SCHEME_BEARER, self::authenticator()->authenticate($request, 'third.example.test', self::NOW));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideWrongAuthorizations(): array
    {
        return [
            'wrong secret' => ['Bearer wrong-secret-0123456789'],
            'prefix of the secret' => ['Bearer ' . substr(self::ORIGIN_SECRET, 0, -1)],
            'basic scheme' => ['Basic ' . base64_encode('purge:' . self::ORIGIN_SECRET)],
            'no scheme' => [self::ORIGIN_SECRET],
            'two tokens' => ['Bearer ' . self::ORIGIN_SECRET . ' x'],
        ];
    }

    /**
     * @dataProvider provideWrongAuthorizations
     */
    #[DataProvider('provideWrongAuthorizations')]
    public function testAWrongAuthorizationIsRefused(string $authorization): void
    {
        $request = self::purge(['HTTP_AUTHORIZATION' => $authorization]);

        self::assertNull(self::authenticator()->authenticate($request, 'origin.example.test', self::NOW));
    }

    public function testAnHmacSignatureIsAccepted(): void
    {
        $request = self::signed(self::ORIGIN_SECRET, (string) self::NOW);

        self::assertSame(PurgeAuthenticator::SCHEME_HMAC, self::authenticator()->authenticate($request, 'origin.example.test', self::NOW));
        self::assertSame(PurgeAuthenticator::SCHEME_HMAC, self::authenticator()->authenticate($request, 'origin.example.test', self::NOW + 300));
        self::assertSame(PurgeAuthenticator::SCHEME_HMAC, self::authenticator()->authenticate($request, 'origin.example.test', self::NOW - 300));
    }

    public function testAnOldOrFutureSignatureIsRefused(): void
    {
        $request = self::signed(self::ORIGIN_SECRET, (string) self::NOW);

        self::assertNull(self::authenticator()->authenticate($request, 'origin.example.test', self::NOW + 301));
        self::assertNull(self::authenticator()->authenticate($request, 'origin.example.test', self::NOW - 301));
    }

    public function testASignatureCoversThePathTheQueryAndTheHost(): void
    {
        $signature = hash_hmac('sha256', PurgeAuthenticator::signedMessage('cdn.example.test', '/css/a.css?v=1', (string) self::NOW), self::ORIGIN_SECRET);
        $headers = ['HTTP_X_PURGE_TIMESTAMP' => (string) self::NOW, 'HTTP_X_PURGE_SIGNATURE' => $signature];

        $authenticator = self::authenticator();
        self::assertSame(PurgeAuthenticator::SCHEME_HMAC, $authenticator->authenticate(self::purge($headers, '/css/a.css?v=1'), 'origin.example.test', self::NOW));
        self::assertNull($authenticator->authenticate(self::purge($headers, '/css/b.css?v=1'), 'origin.example.test', self::NOW));
        self::assertNull($authenticator->authenticate(self::purge($headers, '/css/a.css?v=2'), 'origin.example.test', self::NOW));
        self::assertNull($authenticator->authenticate(self::purge($headers, '/css/a.css?v=1', 'other.example.test'), 'origin.example.test', self::NOW));
    }

    public function testASignedRequestWithAnInvalidHostIsRefused(): void
    {
        $signature = hash_hmac('sha256', PurgeAuthenticator::signedMessage('bad host', '/css/a.css', (string) self::NOW), self::ORIGIN_SECRET);
        $request = Request::create('/css/a.css', 'PURGE', [], [], [], [
            'HTTP_HOST' => 'bad host',
            'HTTP_X_PURGE_TIMESTAMP' => (string) self::NOW,
            'HTTP_X_PURGE_SIGNATURE' => $signature,
        ]);

        self::assertNull(self::authenticator()->authenticate($request, 'origin.example.test', self::NOW));
    }

    public function testASignatureWithTheKeyOfAnotherOriginIsRefused(): void
    {
        self::assertNull(self::authenticator()->authenticate(self::signed(self::OTHER_SECRET, (string) self::NOW), 'origin.example.test', self::NOW));
    }

    public function testAMalformedSignatureIsRefused(): void
    {
        $request = self::purge(['HTTP_X_PURGE_TIMESTAMP' => 'yesterday', 'HTTP_X_PURGE_SIGNATURE' => 'not hex']);

        self::assertNull(self::authenticator()->authenticate($request, 'origin.example.test', self::NOW));
    }

    /**
     * @return array<string, array{list<string>, list<array{string, string}>|null}>
     */
    public static function provideKeys(): array
    {
        return [
            'none' => [[], []],
            'origin and any' => [
                ['Origin.Example.Test=' . self::ORIGIN_SECRET, ' * = ' . self::ANY_SECRET],
                [['origin.example.test', self::ORIGIN_SECRET], ['*', self::ANY_SECRET]],
            ],
            'secret with equals signs' => [['a.test=c2VjcmV0LXNlY3JldC1zZWNyZXQ='], [['a.test', 'c2VjcmV0LXNlY3JldC1zZWNyZXQ=']]],
            'no equals sign' => [[self::ORIGIN_SECRET], null],
            'short secret' => [['a.test=0123456789abcde'], null],
            'secret with a space' => [['a.test=0123456789 abcdef'], null],
            'non-ASCII secret' => [['a.test=0123456789abcdefé'], null],
            'scope with a port' => [['a.test:8080=' . self::ORIGIN_SECRET], null],
            'scope with a path' => [['a.test/x=' . self::ORIGIN_SECRET], null],
            'empty scope' => [['=' . self::ORIGIN_SECRET], null],
        ];
    }

    /**
     * @dataProvider provideKeys
     *
     * @param list<string>                     $entries
     * @param list<array{string, string}>|null $keys
     */
    #[DataProvider('provideKeys')]
    public function testParseKeys(array $entries, ?array $keys): void
    {
        self::assertSame($keys, PurgeAuthenticator::parseKeys($entries));
    }

    public function testFromConfig(): void
    {
        $authenticator = PurgeAuthenticator::fromConfig(TestConfig::create(['PURGE_KEYS' => 'origin.example.test=' . self::ORIGIN_SECRET]));

        self::assertFalse($authenticator->isDisabled());
        self::assertTrue(PurgeAuthenticator::fromConfig(TestConfig::create())->isDisabled());
    }

    /**
     * A purge fanned out by a peer is accepted with the fan-out HMAC and
     * reported as such, so that it is not fanned out again.
     */
    public function testAFanoutSignatureIsAccepted(): void
    {
        $request = self::fanout(self::ORIGIN_SECRET, '1');

        self::assertTrue(PurgeAuthenticator::isFanout($request));
        self::assertFalse(PurgeAuthenticator::isFanout(self::signed(self::ORIGIN_SECRET, (string) self::NOW)));
        self::assertSame(PurgeAuthenticator::SCHEME_FANOUT, self::authenticator()->authenticate($request, 'origin.example.test', self::NOW));
        self::assertNull(self::authenticator()->authenticate($request, 'origin.example.test', self::NOW + 301));
    }

    /**
     * The marker is signed: a fan-out cannot lose it (and be fanned out
     * again), a client signature cannot gain it, and a Bearer key never
     * passes for a fan-out.
     */
    public function testTheFanoutMarkerIsBoundToTheSignature(): void
    {
        $fanout = self::fanout(self::ORIGIN_SECRET, '1');
        $stripped = self::purge([
            'HTTP_X_PURGE_TIMESTAMP' => (string) $fanout->headers->get('X-Purge-Timestamp'),
            'HTTP_X_PURGE_SIGNATURE' => (string) $fanout->headers->get('X-Purge-Signature'),
        ]);
        $client = self::signed(self::ORIGIN_SECRET, (string) self::NOW);
        $client->headers->set(PurgeAuthenticator::FANOUT_HEADER, '1');
        $bearer = self::purge(['HTTP_AUTHORIZATION' => 'Bearer ' . self::ORIGIN_SECRET, 'HTTP_X_PURGE_FANOUT' => '1']);

        $authenticator = self::authenticator();
        self::assertNull($authenticator->authenticate($stripped, 'origin.example.test', self::NOW));
        self::assertNull($authenticator->authenticate($client, 'origin.example.test', self::NOW));
        self::assertNull($authenticator->authenticate($bearer, 'origin.example.test', self::NOW));
        self::assertNull($authenticator->authenticate(self::fanout(self::ORIGIN_SECRET, 'yes'), 'origin.example.test', self::NOW));
        self::assertNull($authenticator->authenticate(self::fanout(self::OTHER_SECRET, '1'), 'origin.example.test', self::NOW));
    }

    public function testTheFanoutMessageCoversTheBody(): void
    {
        $empty = PurgeAuthenticator::fanoutMessage('cdn.example.test', '/css/a.css', '1', '');

        self::assertSame(PurgeAuthenticator::signedMessage('cdn.example.test', '/css/a.css', '1') . "\nfanout\n" . hash('sha256', ''), $empty);
        self::assertNotSame($empty, PurgeAuthenticator::fanoutMessage('cdn.example.test', '/css/a.css', '1', 'x'));
    }

    public function testSecretForPrefersTheKeyOfTheOrigin(): void
    {
        self::assertSame(self::ORIGIN_SECRET, self::authenticator()->secretFor('Origin.Example.Test'));
        self::assertSame(self::ANY_SECRET, self::authenticator()->secretFor('third.example.test'));
        self::assertNull((new PurgeAuthenticator([['origin.example.test', self::ORIGIN_SECRET]]))->secretFor('third.example.test'));
    }

    private static function fanout(string $secret, string $marker): Request
    {
        $signature = hash_hmac('sha256', PurgeAuthenticator::fanoutMessage('cdn.example.test', '/css/a.css', (string) self::NOW, ''), $secret);

        return self::purge(['HTTP_X_PURGE_TIMESTAMP' => (string) self::NOW, 'HTTP_X_PURGE_SIGNATURE' => $signature, 'HTTP_X_PURGE_FANOUT' => $marker]);
    }

    private static function authenticator(): PurgeAuthenticator
    {
        return new PurgeAuthenticator([
            ['origin.example.test', self::ORIGIN_SECRET],
            ['other.example.test', self::OTHER_SECRET],
            ['*', self::ANY_SECRET],
        ]);
    }

    private static function signed(string $secret, string $timestamp): Request
    {
        $signature = hash_hmac('sha256', PurgeAuthenticator::signedMessage('cdn.example.test', '/css/a.css', $timestamp), $secret);

        return self::purge(['HTTP_X_PURGE_TIMESTAMP' => $timestamp, 'HTTP_X_PURGE_SIGNATURE' => $signature]);
    }

    /**
     * @param array<string, string> $server
     */
    private static function purge(array $server = [], string $target = '/css/a.css', string $host = 'cdn.example.test'): Request
    {
        return Request::create('http://' . $host . $target, 'PURGE', [], [], [], $server);
    }
}
