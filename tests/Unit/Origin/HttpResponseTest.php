<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Origin;

use Mfonte\ProxyCdn\Origin\HttpResponse;
use Mfonte\ProxyCdn\Origin\ResponseBody;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Origin\HttpResponse
 */
#[CoversClass(HttpResponse::class)]
final class HttpResponseTest extends TestCase
{
    /**
     * 3xx, 403, 404, 410, other 4xx and 5xx stay distinct (finding P-13).
     *
     * @dataProvider provideStatuses
     */
    #[DataProvider('provideStatuses')]
    public function testOutcomeOfStatus(int $status, string $outcome): void
    {
        self::assertSame($outcome, HttpResponse::outcomeOfStatus($status));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function provideStatuses(): array
    {
        return [
            'status 200' => [200, HttpResponse::OUTCOME_SUCCESS],
            'status 204' => [204, HttpResponse::OUTCOME_SUCCESS],
            'status 206' => [206, HttpResponse::OUTCOME_SUCCESS],
            'status 301' => [301, HttpResponse::OUTCOME_REDIRECT],
            'status 302' => [302, HttpResponse::OUTCOME_REDIRECT],
            'status 304' => [304, HttpResponse::OUTCOME_NOT_MODIFIED],
            'status 308' => [308, HttpResponse::OUTCOME_REDIRECT],
            'status 400' => [400, HttpResponse::OUTCOME_CLIENT_ERROR],
            'status 401' => [401, HttpResponse::OUTCOME_CLIENT_ERROR],
            'status 403' => [403, HttpResponse::OUTCOME_FORBIDDEN],
            'status 404' => [404, HttpResponse::OUTCOME_NOT_FOUND],
            'status 410' => [410, HttpResponse::OUTCOME_GONE],
            'status 429' => [429, HttpResponse::OUTCOME_CLIENT_ERROR],
            'status 500' => [500, HttpResponse::OUTCOME_SERVER_ERROR],
            'status 503' => [503, HttpResponse::OUTCOME_SERVER_ERROR],
            'no status' => [0, HttpResponse::OUTCOME_OTHER],
            'status 103' => [103, HttpResponse::OUTCOME_OTHER],
            'status 600' => [600, HttpResponse::OUTCOME_OTHER],
        ];
    }

    public function testASuccess(): void
    {
        $directory = new TemporaryDirectory();
        try {
            $body = new ResponseBody($directory->write('body', 'abc'), 3);
            $response = new HttpResponse(HttpResponse::OUTCOME_SUCCESS, 200, ['content-type' => 'text/css'], $body, '2', 'https://o.test/a.css', 0.25);

            self::assertTrue($response->isSuccessful());
            self::assertFalse($response->isTransportFailure());
            self::assertSame(200, $response->getStatus());
            self::assertSame('text/css', $response->getHeader('Content-Type'));
            self::assertSame(['content-type' => 'text/css'], $response->getHeaders());
            self::assertSame($body, $response->getBody());
            self::assertNull($response->getRedirectLocation());
            self::assertSame('2', $response->getProtocolVersion());
            self::assertSame('https://o.test/a.css', $response->getEffectiveUrl());
            self::assertSame(0.25, $response->getDuration());
            self::assertSame(0, $response->getErrorCode());
            self::assertSame('', $response->getError());
        } finally {
            $directory->remove();
        }
    }

    public function testARedirectExposesItsLocation(): void
    {
        $response = new HttpResponse(HttpResponse::OUTCOME_REDIRECT, 301, ['location' => '/new.css'], null, '1.1', 'https://o.test/a.css', 0.1);

        self::assertSame('/new.css', $response->getRedirectLocation());
        self::assertFalse($response->isSuccessful());
        self::assertFalse($response->isTransportFailure());
    }

    /**
     * @dataProvider provideTransportFailures
     */
    #[DataProvider('provideTransportFailures')]
    public function testTransportFailures(string $outcome): void
    {
        $response = new HttpResponse($outcome, 0, [], null, '', 'https://o.test/a.css', 1.0, 7, 'Failed to connect');

        self::assertTrue($response->isTransportFailure());
        self::assertNull($response->getBody());
        self::assertSame(7, $response->getErrorCode());
        self::assertSame('Failed to connect', $response->getError());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideTransportFailures(): array
    {
        $outcomes = [];
        foreach ([
            HttpResponse::OUTCOME_TOO_LARGE,
            HttpResponse::OUTCOME_TIMEOUT,
            HttpResponse::OUTCOME_DNS,
            HttpResponse::OUTCOME_CONNECT,
            HttpResponse::OUTCOME_TLS,
            HttpResponse::OUTCOME_NETWORK,
            HttpResponse::OUTCOME_OTHER,
        ] as $outcome) {
            $outcomes[$outcome] = [$outcome];
        }

        return $outcomes;
    }
}
