<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Http;

use Mfonte\ProxyCdn\Http\AcceptHeader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Http\AcceptHeader
 */
#[CoversClass(AcceptHeader::class)]
final class AcceptHeaderTest extends TestCase
{
    /**
     * @dataProvider provideHeaders
     */
    #[DataProvider('provideHeaders')]
    public function testAccepts(string $header, string $token, bool $expected): void
    {
        self::assertSame($expected, AcceptHeader::accepts($header, $token));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function provideHeaders(): array
    {
        return [
            'chrome' => ['image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8', 'image/webp', true],
            'chrome avif' => ['image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8', 'image/avif', true],
            'case' => ['IMAGE/WEBP', 'image/webp', true],
            'spaces and quality' => ['text/html, image/webp ; q=0.5', 'image/webp', true],
            'zero quality' => ['image/avif;q=0, image/webp', 'image/avif', false],
            'zero with decimals' => ['image/avif;q=0.000', 'image/avif', false],
            'invalid quality' => ['image/avif;q=high', 'image/avif', false],
            'wildcard only' => ['*/*', 'image/webp', false],
            'image wildcard' => ['image/*', 'image/webp', false],
            'absent' => ['', 'image/webp', false],
            'prefix only' => ['image/webpx', 'image/webp', false],
            'brotli' => ['gzip, deflate, br, zstd', 'br', true],
            'brotli refused' => ['gzip, br;q=0', 'br', false],
            'gzip' => ['gzip;q=0.8', 'gzip', true],
        ];
    }
}
