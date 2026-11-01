<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Origin;

use Mfonte\ProxyCdn\Origin\OriginResponse;
use Mfonte\ProxyCdn\Origin\ResponseBody;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Origin\OriginResponse
 */
#[CoversClass(OriginResponse::class)]
final class OriginResponseTest extends TestCase
{
    public function testStreamedBodyStaysInItsFileUntilReleased(): void
    {
        $body = ResponseBody::fromString('body {}', sys_get_temp_dir());
        self::assertNotNull($body);

        $response = OriginResponse::streamed(['content-type' => 'text/css'], $body);

        self::assertTrue($response->isSuccessful());
        self::assertSame($body, $response->getBodyFile());
        self::assertSame('body {}', $response->getBody());
        self::assertSame('text/css', $response->getHeader('content-type'));
        self::assertNull($response->getHeader('etag'));
        $response->release();
        self::assertFileDoesNotExist($body->getPath());
    }

    public function testNotModified(): void
    {
        $response = OriginResponse::notModified(['etag' => '"v2"']);

        self::assertTrue($response->isNotModified());
        self::assertFalse($response->isSuccessful());
        self::assertNull($response->getFailureReason());
        self::assertSame(304, $response->getStatus());
        self::assertNull($response->getBodyFile());
        $response->release();
    }

    public function testSuccess(): void
    {
        $response = OriginResponse::success(['content-type' => 'text/css'], 'body {}');

        self::assertTrue($response->isSuccessful());
        self::assertFalse($response->isNotFound());
        self::assertSame(200, $response->getStatus());
        self::assertSame(['content-type' => 'text/css'], $response->getHeaders());
        self::assertSame('body {}', $response->getBody());
        self::assertNull($response->getFailureReason());
        self::assertSame(0, $response->getTransportErrorCode());
        self::assertSame('', $response->getTransportError());
    }

    public function testNotFound(): void
    {
        $response = OriginResponse::failure(OriginResponse::FAILURE_NOT_FOUND, 404);

        self::assertFalse($response->isSuccessful());
        self::assertTrue($response->isNotFound());
        self::assertSame(404, $response->getStatus());
        self::assertSame([], $response->getHeaders());
        self::assertSame('', $response->getBody());
        self::assertSame('not_found', $response->getFailureReason());
    }

    public function testTransportFailure(): void
    {
        $response = OriginResponse::failure(OriginResponse::FAILURE_CONNECT, 0, 7, 'Connection refused');

        self::assertFalse($response->isSuccessful());
        self::assertFalse($response->isNotFound());
        self::assertSame(0, $response->getStatus());
        self::assertSame('connect', $response->getFailureReason());
        self::assertSame(7, $response->getTransportErrorCode());
        self::assertSame('Connection refused', $response->getTransportError());
    }
}
