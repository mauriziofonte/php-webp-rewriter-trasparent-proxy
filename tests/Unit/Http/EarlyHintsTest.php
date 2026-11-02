<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Http;

use Mfonte\ProxyCdn\Http\EarlyHints;
use Mfonte\ProxyCdn\Observability\Metrics;
use Mfonte\ProxyCdn\Observability\MetricsStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Http\EarlyHints
 */
#[CoversClass(EarlyHints::class)]
final class EarlyHintsTest extends TestCase
{
    private const LINKS = '</css/app.css>; rel=preload; as=style';

    public function testTheLinksAreSentBeforeAGetIsFetched(): void
    {
        $sent = [];
        $store = new class () implements MetricsStore {
            /**
             * @var array<string, int>
             */
            public $counters = [];

            public function add(array $increments): bool
            {
                $this->counters = $increments + $this->counters;

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
        $hints = new EarlyHints(self::LINKS, $metrics, static function (string $links) use (&$sent): bool {
            $sent[] = $links;

            return true;
        });

        self::assertNull($hints->beforeFetch(Request::create('https://cdn.example.test/index.html'), '/index.html'));
        self::assertNull($hints->beforeFetch(Request::create('https://cdn.example.test/index.html', 'HEAD'), '/index.html'));
        $metrics->flush();

        self::assertSame([self::LINKS], $sent, 'never for HEAD');
        self::assertSame(['proxy_cdn_early_hints_total' => 1], $store->counters);
    }

    public function testHintsTheServerApiCannotSendAreNotCounted(): void
    {
        $store = new class () implements MetricsStore {
            /**
             * @var array<string, int>
             */
            public $counters = [];

            public function add(array $increments): bool
            {
                $this->counters = $increments + $this->counters;

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
        $hints = new EarlyHints(self::LINKS, $metrics, static function (string $links): bool {
            return false;
        });

        $hints->beforeFetch(Request::create('https://cdn.example.test/index.html'), '/index.html');
        $metrics->flush();

        self::assertSame([], $store->counters);
    }

    /**
     * The CLI and PHP-FPM have no headers_send(): nothing is sent.
     */
    public function testWithoutSupportNothingIsSent(): void
    {
        if (EarlyHints::isSupported()) {
            self::markTestSkipped('this server API sends interim responses');
        }

        self::assertFalse(EarlyHints::emit(self::LINKS));
    }
}
