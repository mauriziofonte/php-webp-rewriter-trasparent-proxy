<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Image;

use Mfonte\ProxyCdn\Image\ConversionFailure;
use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\IsolationUnavailableException;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Image\ConversionFailure
 */
#[CoversClass(ConversionFailure::class)]
final class ConversionFailureTest extends TestCase
{
    /**
     * The shared log schema: the API's (and the proxy's) codes under
     * error_code, the library's under conversion_error_code.
     */
    public function testTheCodesAreLoggedApart(): void
    {
        $api = ConversionFailure::unavailable('remote', 'The conversion API answered 503', 'concurrency_unavailable', 503);
        $library = ConversionFailure::fromLibrary('local', new ConversionFailedException('The backend failed'));

        self::assertSame(
            ['converter' => 'remote', 'transient' => true, 'reason' => 'The conversion API answered 503', 'error_code' => 'concurrency_unavailable', 'status' => 503],
            $api->logContext()
        );
        self::assertSame(
            ['converter' => 'local', 'transient' => false, 'reason' => 'The backend failed', 'conversion_error_code' => ErrorCode::CONVERSION_FAILED],
            $library->logContext()
        );
    }

    /**
     * A code of the library is a conversion_error_code whoever raised it:
     * the API that answered it, or the proxy itself; every other code,
     * of the API or of the proxy, is an error_code.
     */
    public function testALibraryCodeIsAConversionErrorCodeWhoeverRaisedIt(): void
    {
        $answered = ConversionFailure::rejected('remote', 'The conversion API answered 422', ErrorCode::INVALID_IMAGE, 422);
        $raised = ConversionFailure::rejected('remote', 'too large', ErrorCode::FILE_TOO_LARGE);
        $isolation = ConversionFailure::unavailable('remote', 'The conversion API answered 503', ErrorCode::ISOLATION_UNAVAILABLE, 503);
        $proxy = ConversionFailure::unavailable('remote', 'unreachable', 'unreachable');

        foreach ([$answered, $raised, $isolation] as $failure) {
            self::assertNull($failure->getErrorCode());
            self::assertArrayNotHasKey('error_code', $failure->logContext());
        }
        self::assertSame([ErrorCode::INVALID_IMAGE, ErrorCode::FILE_TOO_LARGE, ErrorCode::ISOLATION_UNAVAILABLE], [
            $answered->logContext()['conversion_error_code'] ?? null,
            $raised->logContext()['conversion_error_code'] ?? null,
            $isolation->getConversionErrorCode(),
        ]);
        self::assertSame(['unreachable', null], [$proxy->logContext()['error_code'] ?? null, $proxy->getConversionErrorCode()]);
        self::assertSame(['conversion_error_code' => 'invalid_image'], ConversionFailure::codeContext('invalid_image'));
        self::assertSame(['error_code' => 'rate_limit_unavailable'], ConversionFailure::codeContext('rate_limit_unavailable'));
    }

    public function testTheBusyLibraryCodesAreTransient(): void
    {
        self::assertTrue(ConversionFailure::fromLibrary('local', ConcurrencyLimitException::afterWaiting(1.0, 'busy'))->isTransient());
        self::assertTrue(ConversionFailure::fromLibrary('local', new IsolationUnavailableException('no isolation'))->isTransient());
        self::assertFalse(ConversionFailure::rejected('remote', 'too large', 'body_too_large', 413)->isTransient());
    }
}
