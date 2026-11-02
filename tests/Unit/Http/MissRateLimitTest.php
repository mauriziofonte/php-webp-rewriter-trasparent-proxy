<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Http;

use Mfonte\ProxyCdn\Http\EarlyHints;
use Mfonte\ProxyCdn\Http\MissRateLimit;
use Mfonte\ProxyCdn\Http\OriginFetchGuards;
use Mfonte\ProxyCdn\Http\TrustedProxies;
use Mfonte\ProxyCdn\Limits\RateLimiter;
use Mfonte\ProxyCdn\Limits\RateLimitStore;
use Mfonte\ProxyCdn\Net\IpRange;
use Mfonte\ProxyCdn\Observability\FileMetricsStore;
use Mfonte\ProxyCdn\Observability\Metrics;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Limits\MemoryRateLimitStore;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Http\MissRateLimit
 * @covers \Mfonte\ProxyCdn\Http\OriginFetchGuards
 */
#[CoversClass(MissRateLimit::class)]
#[CoversClass(OriginFetchGuards::class)]
final class MissRateLimitTest extends TestCase
{
    public function testAClientOverTheLimitGets429WithRetryAfter(): void
    {
        $logger = new MemoryLogger();
        $guard = new MissRateLimit(new RateLimiter(new MemoryRateLimitStore(), 6, 2), new TrustedProxies(), new Metrics(null), $logger);

        self::assertNull($guard->beforeFetch(self::request('192.0.2.1'), '/a.png'));
        self::assertNull($guard->beforeFetch(self::request('192.0.2.1'), '/b.png'));
        $refused = $guard->beforeFetch(self::request('192.0.2.1'), '/c.png');
        $other = $guard->beforeFetch(self::request('192.0.2.2'), '/c.png');

        self::assertInstanceOf(Response::class, $refused);
        self::assertSame(429, $refused->getStatusCode());
        self::assertSame('10', $refused->headers->get('Retry-After'));
        self::assertSame('no-store, private', $refused->headers->get('Cache-Control'));
        self::assertNull($other, 'another client has its own bucket');
        self::assertCount(1, $logger->recordsWithMessage('miss rate limited'));
    }

    /**
     * Behind a trusted proxy the client is the address it forwards.
     */
    public function testTheClientIsReadThroughTrustedProxies(): void
    {
        $guard = new MissRateLimit(new RateLimiter(new MemoryRateLimitStore(), 6, 1), new TrustedProxies([IpRange::fromString('10.0.0.1')]), new Metrics(null), new MemoryLogger());

        self::assertNull($guard->beforeFetch(self::request('10.0.0.1', '198.51.100.7'), '/a.png'));
        self::assertNull($guard->beforeFetch(self::request('10.0.0.1', '198.51.100.8'), '/a.png'));
        self::assertNotNull($guard->beforeFetch(self::request('10.0.0.1', '198.51.100.7'), '/a.png'));
        self::assertNotNull($guard->beforeFetch(self::request('192.0.2.9', '198.51.100.99'), '/a.png') ?? $guard->beforeFetch(self::request('192.0.2.9', '198.51.100.100'), '/a.png'), 'an untrusted forwarder is the client');
    }

    public function testAnIpv6ClientIsItsSlash64(): void
    {
        self::assertSame(MissRateLimit::keyOf('2001:db8:1:2::1'), MissRateLimit::keyOf('2001:db8:1:2:ffff::9'));
        self::assertNotSame(MissRateLimit::keyOf('2001:db8:1:2::1'), MissRateLimit::keyOf('2001:db8:1:3::1'));
        self::assertNotSame(MissRateLimit::keyOf('192.0.2.1'), MissRateLimit::keyOf('192.0.2.2'));
        self::assertSame(MissRateLimit::keyOf(null), MissRateLimit::keyOf('not an address'));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', MissRateLimit::keyOf('192.0.2.1'));
        self::assertStringNotContainsString('192', MissRateLimit::keyOf('192.0.2.1'));
    }

    /**
     * A store that cannot be updated lets the miss through: the limit
     * protects the origin, it never decides who may read an asset. Each
     * such miss is logged and counted, so that a limit that silently stopped
     * working shows in /metrics.
     */
    public function testAnUnavailableStoreLetsMissesThrough(): void
    {
        $store = new class () implements RateLimitStore {
            public function update(string $key, callable $decide): bool
            {
                return false;
            }
        };
        $directory = new TemporaryDirectory();

        try {
            $metrics = new Metrics(new FileMetricsStore($directory->path('metrics')));
            $logger = new MemoryLogger();
            $guard = new MissRateLimit(new RateLimiter($store, 1, 1), new TrustedProxies(), $metrics, $logger);

            self::assertNull($guard->beforeFetch(self::request('192.0.2.1'), '/a.png'));
            self::assertNull($guard->beforeFetch(self::request('192.0.2.1'), '/a.png'));
            self::assertTrue($metrics->flush());
            self::assertCount(2, $logger->recordsWithMessage('miss rate limit unavailable: the buckets cannot be updated'));
            self::assertStringContainsString(
                "# TYPE proxy_cdn_miss_rate_limit_unavailable_total counter\nproxy_cdn_miss_rate_limit_unavailable_total 2\n",
                (new Metrics(new FileMetricsStore($directory->path('metrics'))))->render()
            );
        } finally {
            $directory->remove();
        }
    }

    public function testGuardsRunInOrderUntilOneAnswers(): void
    {
        $sent = 0;
        $hints = new EarlyHints('</a.css>; rel=preload; as=style', new Metrics(null), static function (string $links) use (&$sent): bool {
            ++$sent;

            return true;
        });
        $limit = new MissRateLimit(new RateLimiter(new MemoryRateLimitStore(), 6, 1), new TrustedProxies(), new Metrics(null), new MemoryLogger());
        $guards = new OriginFetchGuards([$limit, $hints]);

        self::assertNull($guards->beforeFetch(self::request('192.0.2.1'), '/a.png'));
        self::assertNotNull($guards->beforeFetch(self::request('192.0.2.1'), '/a.png'));
        self::assertSame(1, $sent, 'no hints for a refused miss');
    }

    public function testFromConfig(): void
    {
        $metrics = new Metrics(null);
        $logger = new MemoryLogger();

        self::assertNull(OriginFetchGuards::fromConfig(TestConfig::create(), '/nonexistent', $metrics, $logger));
        self::assertInstanceOf(MissRateLimit::class, OriginFetchGuards::fromConfig(TestConfig::create(['MISS_RATE_LIMIT' => '60']), '/nonexistent', $metrics, $logger));
        self::assertInstanceOf(EarlyHints::class, OriginFetchGuards::fromConfig(TestConfig::create(['EARLY_HINTS' => '</a.css>; rel=preload']), '/nonexistent', $metrics, $logger));
        self::assertInstanceOf(OriginFetchGuards::class, OriginFetchGuards::fromConfig(TestConfig::create(['MISS_RATE_LIMIT' => '60', 'EARLY_HINTS' => '</a.css>; rel=preload']), '/nonexistent', $metrics, $logger));
    }

    private static function request(string $remoteAddress, ?string $forwardedFor = null): Request
    {
        $server = ['REMOTE_ADDR' => $remoteAddress];
        if ($forwardedFor !== null) {
            $server['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }

        return Request::create('https://cdn.example.test/a.png', 'GET', [], [], [], $server);
    }
}
