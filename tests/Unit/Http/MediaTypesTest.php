<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Http;

use Mfonte\ProxyCdn\Http\MediaTypes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Http\MediaTypes
 */
#[CoversClass(MediaTypes::class)]
final class MediaTypesTest extends TestCase
{
    /**
     * @dataProvider provideMediaTypes
     */
    #[DataProvider('provideMediaTypes')]
    public function testOf(string $extension, string $mediaType): void
    {
        self::assertSame($mediaType, MediaTypes::of($extension));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideMediaTypes(): array
    {
        return [
            'css' => ['css', 'text/css'],
            'js, RFC 9239' => ['js', 'text/javascript'],
            'upper case' => ['PNG', 'image/png'],
            'jpeg' => ['jpeg', 'image/jpeg'],
            'svg' => ['svg', 'image/svg+xml'],
            'ttf, RFC 8081' => ['ttf', 'font/ttf'],
            'woff2' => ['woff2', 'font/woff2'],
            'ico' => ['ico', 'image/vnd.microsoft.icon'],
            'avif' => ['avif', 'image/avif'],
            'unknown' => ['xyz', 'application/octet-stream'],
            'empty' => ['', 'application/octet-stream'],
        ];
    }

    /**
     * public/.htaccess declares exactly the types of the table, so that a
     * file has the same Content-Type whether Apache or PHP serves it
     * (finding P-23: 2.x kept two tables that disagreed with each other and
     * with the origins).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testTheApacheConfigurationDeclaresTheSameTypesP23(): void
    {
        $htaccess = (string) file_get_contents(dirname(__DIR__, 3) . '/public/.htaccess');
        self::assertSame(1, preg_match_all('/^\s*<IfModule mod_mime\.c>\s*$(.*?)^\s*<\/IfModule>/ms', $htaccess, $blocks));

        $declared = [];
        preg_match_all('/^\s*AddType\s+(\S+)((?:\s+\.\S+)+)\s*$/m', $blocks[1][0], $lines, PREG_SET_ORDER);
        foreach ($lines as $line) {
            foreach (preg_split('/\s+/', trim($line[2])) ?: [] as $extension) {
                self::assertArrayNotHasKey(substr($extension, 1), $declared, 'declared twice: ' . $extension);
                $declared[substr($extension, 1)] = $line[1];
            }
        }
        ksort($declared);
        $table = MediaTypes::TYPES;
        ksort($table);

        self::assertSame($table, $declared);
    }

    /**
     * config/nginx/proxy-cdn-types.conf declares exactly the types of the
     * table, for the same reason (finding P-23).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testTheNginxConfigurationDeclaresTheSameTypesP23(): void
    {
        $types = (string) file_get_contents(dirname(__DIR__, 3) . '/config/nginx/proxy-cdn-types.conf');
        self::assertSame(1, preg_match('/^types\s*\{\s*$(.*?)^\}/ms', $types, $block));

        $declared = [];
        preg_match_all('/^\s*(\S+)((?:\s+[a-z0-9]+)+);\s*$/m', $block[1], $lines, PREG_SET_ORDER);
        foreach ($lines as $line) {
            foreach (preg_split('/\s+/', trim($line[2])) ?: [] as $extension) {
                self::assertArrayNotHasKey($extension, $declared, 'declared twice: ' . $extension);
                $declared[$extension] = $line[1];
            }
        }
        ksort($declared);
        $table = MediaTypes::TYPES;
        ksort($table);

        self::assertSame($table, $declared);
    }

    public function testEveryTypeIsLowerCaseAndWellFormed(): void
    {
        foreach (MediaTypes::TYPES as $extension => $type) {
            self::assertMatchesRegularExpression('/\A[a-z0-9]+\z/', $extension);
            self::assertMatchesRegularExpression('#\A[a-z]+/[a-z0-9.+-]+\z#', $type);
        }
    }
}
