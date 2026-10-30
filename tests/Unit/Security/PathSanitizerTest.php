<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Security;

use Mfonte\ProxyCdn\Security\PathSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Security\PathSanitizer
 */
#[CoversClass(PathSanitizer::class)]
final class PathSanitizerTest extends TestCase
{
    /**
     * @dataProvider provideAllowedPaths
     */
    #[DataProvider('provideAllowedPaths')]
    public function testAllowedPathsAreReturnedUnchanged(string $path): void
    {
        self::assertSame($path, (new PathSanitizer())->sanitize($path));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideAllowedPaths(): array
    {
        return [
            'root file' => ['/a.css'],
            'nested' => ['/css/nested/deep.css'],
            'dots inside a name' => ['/css/a..b.css'],
            'dots ending a name' => ['/css/a...'],
            'single dot segment' => ['/css/./a.css'],
            // Still encoded: 2.x neither decodes nor refuses it (finding P-8).
            'encoded dot segment P-8' => ['/css/%2e%2e/a.css'],
        ];
    }

    /**
     * @dataProvider provideRefusedPaths
     */
    #[DataProvider('provideRefusedPaths')]
    public function testDotDotSegmentsAreRefused(string $path): void
    {
        self::assertNull((new PathSanitizer())->sanitize($path));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRefusedPaths(): array
    {
        return [
            'parent segment' => ['/css/../a.css'],
            'leading parent segment' => ['/../etc/passwd'],
            'trailing parent segment' => ['/css/..'],
            // As in 2.x, any segment starting with ".." is refused.
            'name starting with dots' => ['/css/..a.css'],
        ];
    }
}
