<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Observability;

use Mfonte\ProxyCdn\Observability\MeteredOriginFetcher;
use Mfonte\ProxyCdn\Observability\Metrics;
use Mfonte\ProxyCdn\Observability\MetricsStore;
use Mfonte\ProxyCdn\Origin\OriginRequest;
use Mfonte\ProxyCdn\Origin\OriginResponse;
use Mfonte\ProxyCdn\Tests\Fixtures\Origin\FakeOriginFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Observability\MeteredOriginFetcher
 */
#[CoversClass(MeteredOriginFetcher::class)]
final class MeteredOriginFetcherTest extends TestCase
{
    public function testEveryOutcomeIsCountedAndTheResponseKept(): void
    {
        $origin = new FakeOriginFetcher();
        $origin->serve('https://origin.example.test/a.css', 'a');
        $origin->respond('https://origin.example.test/b.css', OriginResponse::notModified([]));
        $origin->respond('https://origin.example.test/c.css', OriginResponse::failure(OriginResponse::FAILURE_TIMEOUT, 0, 28, 'timed out'));
        $store = new class () implements MetricsStore {
            /**
             * @var array<string, int>
             */
            public $counters = [];

            public function add(array $increments): bool
            {
                foreach ($increments as $name => $amount) {
                    $this->counters[$name] = ($this->counters[$name] ?? 0) + $amount;
                }

                return true;
            }

            /**
             * @return array<string, int>
             */
            public function read(): array
            {
                return $this->counters;
            }
        };
        $metrics = new Metrics($store);
        $fetcher = new MeteredOriginFetcher($origin, $metrics);

        $first = $fetcher->fetch(new OriginRequest('https://origin.example.test/a.css'));
        $fetcher->fetch(new OriginRequest('https://origin.example.test/b.css'));
        $fetcher->fetch(new OriginRequest('https://origin.example.test/c.css'));
        $fetcher->fetch(new OriginRequest('https://origin.example.test/d.css'));
        $metrics->flush();

        self::assertSame('a', $first->getBody());
        self::assertSame([
            'proxy_cdn_origin_fetches_total{outcome="success"}' => 1,
            'proxy_cdn_origin_fetches_total{outcome="not_modified"}' => 1,
            'proxy_cdn_origin_fetches_total{outcome="timeout"}' => 1,
            'proxy_cdn_origin_fetches_total{outcome="not_found"}' => 1,
        ], $store->counters);
    }
}
