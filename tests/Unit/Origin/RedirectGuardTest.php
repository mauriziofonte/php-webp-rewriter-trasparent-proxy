<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Origin;

use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Origin\HttpResponse;
use Mfonte\ProxyCdn\Origin\RedirectGuard;
use Mfonte\ProxyCdn\Origin\SystemHostResolver;
use Mfonte\ProxyCdn\Tests\Fixtures\Origin\FixedHostResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Origin\RedirectGuard
 * @covers \Mfonte\ProxyCdn\Origin\SystemHostResolver
 */
#[CoversClass(RedirectGuard::class)]
#[CoversClass(SystemHostResolver::class)]
final class RedirectGuardTest extends TestCase
{
    private const ORIGIN = 'http://10.0.0.5:8080/assets/a.css';

    /**
     * The origin is trusted as configured, even on a private network.
     */
    public function testARedirectWithinTheOriginIsAllowed(): void
    {
        $resolver = new FixedHostResolver([]);
        $check = RedirectGuard::check(self::options($resolver), self::ORIGIN, 'http://10.0.0.5:8080/other/b.css');

        self::assertTrue($check['allowed']);
        self::assertNull($check['address']);
        self::assertSame([], $resolver->getLookups());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideNonPublicTargets(): array
    {
        return [
            'another port of the origin' => ['http://10.0.0.5:9090/a.css'],
            'another scheme of the origin' => ['https://10.0.0.5:8080/a.css'],
            'loopback' => ['http://127.0.0.1/a.css'],
            'loopback, other form' => ['http://127.1.2.3/a.css'],
            'IPv6 loopback' => ['http://[::1]/a.css'],
            'IPv4-mapped private' => ['http://[::ffff:192.168.1.1]/a.css'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'private' => ['http://172.16.0.1/a.css'],
            'carrier-grade NAT' => ['http://100.64.0.1/a.css'],
            'unspecified' => ['http://0.0.0.0/a.css'],
            'unique local IPv6' => ['http://[fd00::1]/a.css'],
            'link-local IPv6' => ['http://[fe80::1]/a.css'],
            'NAT64 of a private address' => ['http://[64:ff9b::a00:1]/a.css'],
            '6to4 of a private address' => ['http://[2002:a00:1::1]/a.css'],
            'multicast' => ['http://224.0.0.1/a.css'],
            'host resolving to a private address' => ['http://internal.example.test/a.css'],
            'host resolving to a public and a private address' => ['http://mixed.example.test/a.css'],
        ];
    }

    /**
     * @dataProvider provideNonPublicTargets
     */
    #[DataProvider('provideNonPublicTargets')]
    public function testARedirectToANonPublicAddressIsRefused(string $target): void
    {
        $check = RedirectGuard::check(self::options(self::resolver()), self::ORIGIN, $target);

        self::assertFalse($check['allowed']);
        self::assertSame(HttpResponse::OUTCOME_REDIRECT, $check['outcome']);
        self::assertStringStartsWith('Redirect to a non-public address refused: ', $check['error']);
    }

    public function testARedirectToAPublicHostIsPinnedToItsAddress(): void
    {
        $check = RedirectGuard::check(self::options(self::resolver()), self::ORIGIN, 'https://cdn.example.test/a.css');

        self::assertTrue($check['allowed']);
        self::assertSame('203.0.114.10', $check['address']);
    }

    public function testARedirectToAPublicAddressIsAllowed(): void
    {
        $check = RedirectGuard::check(self::options(self::resolver()), self::ORIGIN, 'http://[2001:4860::8888]/a.css');

        self::assertTrue($check['allowed']);
        self::assertSame('2001:4860::8888', $check['address']);
    }

    public function testAHostThatDoesNotResolveIsADnsFailure(): void
    {
        $check = RedirectGuard::check(self::options(self::resolver()), self::ORIGIN, 'http://nowhere.example.test/a.css');

        self::assertFalse($check['allowed']);
        self::assertSame(HttpResponse::OUTCOME_DNS, $check['outcome']);
        self::assertSame(6, $check['code']);
    }

    public function testTheCheckCanBeTurnedOff(): void
    {
        $check = RedirectGuard::check(self::options(self::resolver())->withPrivateRedirectTargets(true), self::ORIGIN, 'http://127.0.0.1/a.css');

        self::assertTrue($check['allowed']);
        self::assertNull($check['address']);
    }

    public function testTheSystemResolverReadsTheHostsFile(): void
    {
        self::assertContains('127.0.0.1', (new SystemHostResolver())->resolve('localhost'));
        self::assertSame([], (new SystemHostResolver())->resolve('nowhere.invalid'));
        self::assertInstanceOf(SystemHostResolver::class, (new HttpClientOptions())->getHostResolver());
        self::assertFalse((new HttpClientOptions())->allowsPrivateRedirectTargets());
    }

    private static function resolver(): FixedHostResolver
    {
        return new FixedHostResolver([
            'internal.example.test' => ['10.1.2.3'],
            'mixed.example.test' => ['203.0.114.10', '::1'],
            'cdn.example.test' => ['203.0.114.10', '2001:4860::8888'],
        ]);
    }

    private static function options(FixedHostResolver $resolver): HttpClientOptions
    {
        return (new HttpClientOptions())->withRedirects(true)->withHostResolver($resolver);
    }
}
