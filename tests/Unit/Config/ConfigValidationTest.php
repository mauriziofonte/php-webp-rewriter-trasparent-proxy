<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Config;

use Mfonte\ProxyCdn\Config\ConfigValidation;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Config\ConfigValidation
 */
#[CoversClass(ConfigValidation::class)]
final class ConfigValidationTest extends TestCase
{
    public function testWithoutErrorsItIsValid(): void
    {
        $validation = new ConfigValidation([], ['NOTE: something']);

        self::assertTrue($validation->isValid());
        self::assertSame([], $validation->getErrors());
        self::assertSame(['NOTE: something'], $validation->getWarnings());
    }

    public function testWithErrorsItIsInvalid(): void
    {
        $validation = new ConfigValidation(['broken'], []);

        self::assertFalse($validation->isValid());
        self::assertSame(['broken'], $validation->getErrors());
    }

    public function testWithErrorReturnsACopy(): void
    {
        $validation = new ConfigValidation([], []);

        $copy = $validation->withError('cwebp binary not found in PATH');

        self::assertTrue($validation->isValid());
        self::assertFalse($copy->isValid());
        self::assertSame(['cwebp binary not found in PATH'], $copy->getErrors());
    }

    public function testWithWarningReturnsACopy(): void
    {
        $validation = new ConfigValidation(['broken'], ['WARNING: one']);

        $copy = $validation->withWarning('NOTE: two');

        self::assertSame(['WARNING: one'], $validation->getWarnings());
        self::assertSame(['WARNING: one', 'NOTE: two'], $copy->getWarnings());
        self::assertSame(['broken'], $copy->getErrors());
    }
}
