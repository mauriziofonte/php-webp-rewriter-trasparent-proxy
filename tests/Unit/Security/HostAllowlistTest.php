<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Security;

use Mfonte\ProxyCdn\Security\HostAllowlist;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Security\HostAllowlist
 */
#[CoversClass(HostAllowlist::class)]
final class HostAllowlistTest extends TestCase
{
    public function testEntriesMapHostsToOrigins(): void
    {
        $origins = HostAllowlist::parse(['CDN.example.test', 'static.example.test=https://other.example.test/base/'], 'https://origin.example.test');

        self::assertSame([
            'cdn.example.test' => 'https://origin.example.test',
            'static.example.test' => 'https://other.example.test/base',
        ], $origins);
    }

    /**
     * @dataProvider provideInvalidEntries
     *
     * @param list<string> $entries
     */
    #[DataProvider('provideInvalidEntries')]
    public function testInvalidEntriesAreRefused(array $entries): void
    {
        self::assertNull(HostAllowlist::parse($entries, 'https://origin.example.test'));
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function provideInvalidEntries(): array
    {
        return [
            'wildcard' => [['*.example.test']],
            'ipv6' => [['[::1]']],
            'empty label' => [['a..b']],
            'leading dot' => [['.example.test']],
            'underscore' => [['a_b.example.test']],
            'twice' => [['a.example.test', 'A.EXAMPLE.TEST.']],
            'origin not http' => [['a.example.test=ftp://o.example.test']],
            'origin with query' => [['a.example.test=https://o.example.test?x']],
            'origin with fragment' => [['a.example.test=https://o.example.test#x']],
            'empty host' => [['=https://o.example.test']],
        ];
    }

    /**
     * The list is strict: a host matches only itself, in any case, with or
     * without port and trailing dot (risk L-13).
     *
     * @dataProvider provideHosts
     */
    #[DataProvider('provideHosts')]
    public function testMatch(string $host, ?string $expected): void
    {
        $allowlist = new HostAllowlist(['cdn.example.test' => 'https://o.example.test', '192.0.2.1' => 'https://o.example.test']);

        self::assertSame($expected, $allowlist->match($host));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function provideHosts(): array
    {
        return [
            'exact' => ['cdn.example.test', 'cdn.example.test'],
            'upper case' => ['CDN.Example.Test', 'cdn.example.test'],
            'port' => ['cdn.example.test:8443', 'cdn.example.test'],
            'trailing dot' => ['cdn.example.test.', 'cdn.example.test'],
            'ipv4' => ['192.0.2.1:80', '192.0.2.1'],
            'subdomain' => ['a.cdn.example.test', null],
            'suffix' => ['evilcdn.example.test', null],
            'other' => ['attacker.example.test', null],
            'traversal' => ['..', null],
            'empty' => ['', null],
            'ipv6' => ['[::1]', null],
        ];
    }

    public function testOriginsAndHosts(): void
    {
        $allowlist = new HostAllowlist(['a.test' => 'https://o.test', 'b.test' => 'https://o.test', 'c.test' => 'https://p.test']);

        self::assertSame('https://p.test', $allowlist->originOf('c.test'));
        self::assertNull($allowlist->originOf('d.test'));
        self::assertSame(['a.test', 'b.test', 'c.test'], $allowlist->getHosts());
        self::assertSame(['https://o.test', 'https://p.test'], $allowlist->getOrigins());
    }
}
