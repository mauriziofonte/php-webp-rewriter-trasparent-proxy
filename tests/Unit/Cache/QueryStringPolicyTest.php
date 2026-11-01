<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Cache;

use Mfonte\ProxyCdn\Cache\QueryString;
use Mfonte\ProxyCdn\Cache\QueryStringPolicy;
use Mfonte\ProxyCdn\Exception\InvalidArgumentException;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Cache\QueryString
 * @covers \Mfonte\ProxyCdn\Cache\QueryStringPolicy
 */
#[CoversClass(QueryString::class)]
#[CoversClass(QueryStringPolicy::class)]
final class QueryStringPolicyTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideEveryParameter(): array
    {
        return [
            'empty' => ['', '', ''],
            'one parameter' => ['v=1', 'v=1', 'v=1'],
            'sorted for the key only' => ['b=2&a=1', 'b=2&a=1', 'a=1&b=2'],
            'repeated names keep their order' => ['a=2&b=1&a=1', 'a=2&b=1&a=1', 'a=2&a=1&b=1'],
            'dots are kept P-9' => ['a.b=1', 'a.b=1', 'a.b=1'],
            'a bare key stays bare P-9' => ['v', 'v', 'v'],
            'a bare key and an empty value differ' => ['v=', 'v=', 'v='],
            'escapes kept, upper-cased for the key' => ['q=a%2fb', 'q=a%2fb', 'q=a%2Fb'],
            'plus is not a space' => ['q=a+b', 'q=a+b', 'q=a+b'],
            'arrays as sent' => ['a[]=1&a[]=2', 'a[]=1&a[]=2', 'a[]=1&a[]=2'],
            'empty pairs dropped' => ['&a=1&&b=2&', 'a=1&b=2', 'a=1&b=2'],
            'purge dropped' => ['v=1&purge=1', 'v=1', 'v=1'],
            'purge_all dropped' => ['purge_all=true', '', ''],
            'upper-case PURGE is a parameter' => ['PURGE=1', 'PURGE=1', 'PURGE=1'],
        ];
    }

    /**
     * With "*" every parameter is kept: the origin receives the query string
     * as the client wrote it, and only the cache key is canonical (finding
     * P-9: 2.x forwarded "a_b=1" for "a.b=1" and "v=" for "v").
     *
     * @dataProvider provideEveryParameter
     *
     * @group regression
     */
    #[DataProvider('provideEveryParameter')]
    #[Group('regression')]
    public function testTheOriginalQueryIsForwardedP9(string $raw, string $forwarded, string $canonical): void
    {
        $query = (new QueryStringPolicy(['*']))->apply($raw);

        self::assertNotNull($query);
        self::assertSame($forwarded, $query->getForwarded());
        self::assertSame($canonical, $query->getCanonical());
        self::assertSame([], $query->getIgnored());
    }

    /**
     * @return array<string, array{string, string, string, list<string>}>
     */
    public static function provideIgnoredParameters(): array
    {
        return [
            'only allowlisted' => ['v=1', 'v=1', 'v=1', []],
            'others ignored' => ['utm_source=mail&v=2&fbclid=x', 'v=2', 'v=2', ['utm_source', 'fbclid']],
            'random cache buster' => ['_=1700000000', '', '', ['_']],
            'names compare decoded' => ['%76=3', '%76=3', '%76=3', []],
            'names compare with letter case' => ['V=1', '', '', ['V']],
            'bare allowlisted key' => ['ver', 'ver', 'ver', []],
        ];
    }

    /**
     * Parameters outside the allowlist neither key the cache nor reach the
     * origin, so random query strings share the entry of the path (finding
     * P-5).
     *
     * @dataProvider provideIgnoredParameters
     *
     * @param list<string> $ignored
     *
     * @group regression
     */
    #[DataProvider('provideIgnoredParameters')]
    #[Group('regression')]
    public function testOtherParametersAreIgnoredP5(string $raw, string $forwarded, string $canonical, array $ignored): void
    {
        $query = (new QueryStringPolicy())->apply($raw);

        self::assertNotNull($query);
        self::assertSame($forwarded, $query->getForwarded());
        self::assertSame($canonical, $query->getCanonical());
        self::assertSame($ignored, $query->getIgnored());
    }

    public function testRejectModeRefusesOtherParameters(): void
    {
        $policy = new QueryStringPolicy(['v'], QueryStringPolicy::MODE_REJECT);

        self::assertNull($policy->apply('v=1&x=2'));
        self::assertSame('v=1', self::canonical($policy->apply('v=1&purge=1')));
        self::assertSame('', self::canonical($policy->apply('')));
        self::assertSame(QueryStringPolicy::MODE_REJECT, $policy->getMode());
    }

    public function testAnEmptyAllowlistKeepsNothing(): void
    {
        $query = (new QueryStringPolicy([]))->apply('v=1&w=2');

        self::assertSame('', self::canonical($query));
        self::assertFalse((new QueryStringPolicy([]))->keepsEveryParameter());
        self::assertTrue((new QueryStringPolicy(['*']))->keepsEveryParameter());
    }

    public function testAnUnknownModeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new QueryStringPolicy(['v'], 'drop');
    }

    public function testFromConfig(): void
    {
        $default = QueryStringPolicy::fromConfig(TestConfig::create());
        $configured = QueryStringPolicy::fromConfig(TestConfig::create(['QUERY_STRING_ALLOWLIST' => 'lang, v', 'QUERY_STRING_MODE' => 'reject']));

        self::assertSame('ver=1', self::canonical($default->apply('ver=1&x=1')));
        // Laravel Mix: mix('/css/app.css') is "/css/app.css?id=<hash>".
        self::assertSame('id=8f3a', self::canonical($default->apply('id=8f3a&utm_source=x')));
        self::assertSame(QueryStringPolicy::MODE_IGNORE, $default->getMode());
        self::assertSame('lang=it&v=1', self::canonical($configured->apply('v=1&lang=it')));
        self::assertNull($configured->apply('ver=1'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function provideParameterNames(): array
    {
        return [
            'letters' => ['v', true],
            'punctuation' => ['a.b[]', true],
            'empty' => ['', false],
            'space' => ['a b', false],
            'equals' => ['a=b', false],
            'ampersand' => ['a&b', false],
            'comma' => ['a,b', false],
            'hash' => ['a#b', false],
            'non-ASCII' => ['é', false],
        ];
    }

    /**
     * @dataProvider provideParameterNames
     */
    #[DataProvider('provideParameterNames')]
    public function testIsParameterName(string $name, bool $valid): void
    {
        self::assertSame($valid, QueryStringPolicy::isParameterName($name));
    }

    private static function canonical(?QueryString $query): ?string
    {
        return $query === null ? null : $query->getCanonical();
    }
}
