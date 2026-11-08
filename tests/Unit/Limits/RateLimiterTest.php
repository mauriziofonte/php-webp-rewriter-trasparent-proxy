<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Limits;

use Mfonte\ProxyCdn\Limits\RateLimiter;
use Mfonte\ProxyCdn\Limits\RateLimitStore;
use Mfonte\ProxyCdn\Tests\Fixtures\Limits\MemoryRateLimitStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Limits\RateLimiter
 */
#[CoversClass(RateLimiter::class)]
final class RateLimiterTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';

    /**
     * @var int
     */
    private $now = 1000000000000;

    public function testTheBurstPassesThenTheRateApplies(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 60, 3);

        self::assertSame([0, 0, 0], [$limiter->consume(self::KEY), $limiter->consume(self::KEY), $limiter->consume(self::KEY)]);
        self::assertSame(1, $limiter->consume(self::KEY), 'one token per second at 60 per minute');
        $this->now += 1000000;
        self::assertSame(0, $limiter->consume(self::KEY));
        self::assertSame(1, $limiter->consume(self::KEY));
    }

    public function testARefusedRequestDoesNotPushTheWaitFurther(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 6, 1);

        self::assertSame(0, $limiter->consume(self::KEY));
        self::assertSame(10, $limiter->consume(self::KEY));
        self::assertSame(10, $limiter->consume(self::KEY));
        $this->now += 4000000;
        self::assertSame(6, $limiter->consume(self::KEY));
        $this->now += 6000000;
        self::assertSame(0, $limiter->consume(self::KEY));
    }

    public function testKeysHaveTheirOwnBuckets(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 60, 1);

        self::assertSame(0, $limiter->consume(self::KEY));
        self::assertSame(0, $limiter->consume(str_repeat('f', 32)));
        self::assertSame(1, $limiter->consume(self::KEY));
    }

    public function testAStoreFailureIsReported(): void
    {
        $store = new class () implements RateLimitStore {
            public function update(string $key, callable $decide): bool
            {
                return false;
            }
        };

        self::assertNull($this->limiter($store, 60, 1)->consume(self::KEY));
    }

    private function limiter(RateLimitStore $store, int $perMinute, int $burst): RateLimiter
    {
        return new RateLimiter($store, $perMinute, $burst, function (): int {
            return $this->now;
        });
    }
}
