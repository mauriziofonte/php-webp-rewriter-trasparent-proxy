<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Image;

use Mfonte\ProxyCdn\Image\WebpConversion;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Image\WebpConversion
 */
#[CoversClass(WebpConversion::class)]
final class WebpConversionTest extends TestCase
{
    public function testConverted(): void
    {
        $conversion = WebpConversion::converted('/cache/a.webp');

        self::assertTrue($conversion->isConverted());
        self::assertSame('/cache/a.webp', $conversion->getPath());
        self::assertNull($conversion->getError());
    }

    public function testFailed(): void
    {
        $conversion = WebpConversion::failed('Conversion failed: output file not created');

        self::assertFalse($conversion->isConverted());
        self::assertNull($conversion->getPath());
        self::assertSame('Conversion failed: output file not created', $conversion->getError());
    }
}
