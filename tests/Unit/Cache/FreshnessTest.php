<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Cache;

use Mfonte\ProxyCdn\Cache\Freshness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Cache\Freshness
 */
#[CoversClass(Freshness::class)]
final class FreshnessTest extends TestCase
{
    private const NOW = 1790000000;

    /**
     * @dataProvider provideHeaders
     *
     * @param array<string, string> $headers
     */
    #[DataProvider('provideHeaders')]
    public function testTheHeadersOfTheOrigin(array $headers, bool $storable, ?int $lifetime, ?int $swr, ?int $sie, bool $mustRevalidate): void
    {
        $freshness = Freshness::fromHeaders($headers, self::NOW);

        self::assertSame($storable, $freshness->isStorable());
        self::assertSame($lifetime, $freshness->getLifetime());
        self::assertSame($swr, $freshness->getStaleWhileRevalidate());
        self::assertSame($sie, $freshness->getStaleIfError());
        self::assertSame($mustRevalidate, $freshness->mustRevalidate());
    }

    /**
     * @return array<string, array{array<string, string>, bool, int|null, int|null, int|null, bool}>
     */
    public static function provideHeaders(): array
    {
        $date = gmdate('D, d M Y H:i:s', self::NOW) . ' GMT';
        $later = gmdate('D, d M Y H:i:s', self::NOW + 300) . ' GMT';

        return [
            'nothing: kept until purged' => [[], true, null, null, null, false],
            'no-store' => [['cache-control' => 'no-store'], false, null, null, null, false],
            'private' => [['cache-control' => 'Private, max-age=60'], false, 60, null, null, false],
            'private with fields' => [['cache-control' => 'private="set-cookie"'], false, null, null, null, false],
            'max-age' => [['cache-control' => 'public, max-age=600'], true, 600, null, null, false],
            's-maxage wins' => [['cache-control' => 'max-age=600, s-maxage=30'], true, 30, null, null, false],
            'quoted max-age' => [['cache-control' => 'max-age="45"'], true, 45, null, null, false],
            'invalid max-age' => [['cache-control' => 'max-age=soon'], true, null, null, null, false],
            'first duplicate wins' => [['cache-control' => 'max-age=10, max-age=20'], true, 10, null, null, false],
            'huge max-age' => [['cache-control' => 'max-age=99999999999999'], true, 2147483648, null, null, false],
            'no-cache' => [['cache-control' => 'no-cache, max-age=600'], true, 0, null, null, false],
            'no-cache with fields' => [['cache-control' => 'no-cache="set-cookie", max-age=600'], true, 600, null, null, false],
            'expires' => [['expires' => $later, 'date' => $date], true, 300, null, null, false],
            'expires without date' => [['expires' => $later], true, 300, null, null, false],
            'invalid expires' => [['expires' => '0'], true, 0, null, null, false],
            'max-age beats expires' => [['cache-control' => 'max-age=5', 'expires' => $later], true, 5, null, null, false],
            'age' => [['cache-control' => 'max-age=600', 'age' => '100'], true, 500, null, null, false],
            'stale directives' => [['cache-control' => 'max-age=60, stale-while-revalidate=30, stale-if-error=600'], true, 60, 30, 600, false],
            'must-revalidate' => [['cache-control' => 'max-age=60, must-revalidate'], true, 60, null, null, true],
            'proxy-revalidate' => [['cache-control' => 'max-age=60, proxy-revalidate'], true, 60, null, null, true],
        ];
    }

    public function testNoStoreIsToldFromPrivate(): void
    {
        self::assertTrue(Freshness::fromHeaders(['cache-control' => 'no-store'], self::NOW)->isNoStore());
        self::assertFalse(Freshness::fromHeaders(['cache-control' => 'private'], self::NOW)->isNoStore());
    }
}
