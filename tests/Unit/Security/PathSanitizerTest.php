<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Security;

use Mfonte\ProxyCdn\Security\PathSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
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
    public function testAllowedPathsAreDecoded(string $path, string $decoded): void
    {
        self::assertSame($decoded, (new PathSanitizer())->sanitize($path));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideAllowedPaths(): array
    {
        return [
            'root file' => ['/a.css', '/a.css'],
            'nested' => ['/css/nested/deep.css', '/css/nested/deep.css'],
            'dots inside a name' => ['/css/a..b.css', '/css/a..b.css'],
            'dots ending a name' => ['/css/a...', '/css/a...'],
            'trailing slash' => ['/images/', '/images/'],
            'encoded space P-8' => ['/images/my%20file.png', '/images/my file.png'],
            'lower-case escape' => ['/a%2bb.png', '/a+b.png'],
            'plus stays a plus' => ['/a+b.png', '/a+b.png'],
            'encoded percent' => ['/100%25.png', '/100%.png'],
            'encoded letters' => ['/%61.css', '/a.css'],
            'UTF-8 L-15' => ['/images/caf%C3%A9.png', '/images/café.png'],
            'raw UTF-8' => ['/images/café.png', '/images/café.png'],
            'four-byte UTF-8' => ['/%F0%9F%98%80.png', "/\u{1F600}.png"],
            'sub-delimiters' => ["/a!\$&'()*,;=:@b.css", "/a!\$&'()*,;=:@b.css"],
            'reserved name below the root' => ['/images/_qs/a.png', '/images/_qs/a.png'],
            'longest segment' => ['/' . str_repeat('a', 251) . '.png', '/' . str_repeat('a', 251) . '.png'],
        ];
    }

    /**
     * Decoded once, the path is refused when it could name another file
     * than the one meant (finding P-8: 2.x let "%2e%2e" through, and kept
     * escapes in the file names).
     *
     * @dataProvider provideRefusedPaths
     *
     * @group regression
     */
    #[DataProvider('provideRefusedPaths')]
    #[Group('regression')]
    public function testUnsafePathsAreRefusedP8(string $path): void
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
            'name starting with dots' => ['/css/..a.css'],
            'single dot segment' => ['/css/./a.css'],
            'hidden file' => ['/.htaccess'],
            'hidden directory' => ['/a/.git/config.css'],
            'encoded parent segment' => ['/css/%2e%2e/a.css'],
            'encoded parent segment, upper case' => ['/css/%2E%2E/a.css'],
            'half-encoded parent segment' => ['/css/.%2e/a.css'],
            'encoded slash' => ['/css%2fa.css'],
            'encoded slash, upper case' => ['/css%2Fa.css'],
            'encoded backslash' => ['/css%5ca.css'],
            'raw backslash' => ['/css\\a.css'],
            'encoded NUL' => ['/a.css%00.png'],
            'encoded control character' => ['/a%0A.css'],
            'raw control character' => ["/a\t.css"],
            'DEL' => ['/a%7F.css'],
            'C1 control character' => ['/a%C2%85.css'],
            'invalid UTF-8' => ['/a%FF.css'],
            'overlong UTF-8 slash' => ['/a%C0%AF.css'],
            'malformed escape' => ['/a%zz.css'],
            'truncated escape' => ['/a%2'],
            'empty segment' => ['/css//a.css'],
            'leading empty segment' => ['//a.css'],
            'query-string bucket' => ['/_qs/a798de8ee75aeb55/css/a.css'],
            'query-string bucket, upper case' => ['/_QS/a/b.css'],
            'encoded bucket name' => ['/%5Fqs/a/b.css'],
            'segment too long' => ['/' . str_repeat('a', 252) . '.png'],
            'path too long' => ['/' . str_repeat('a/', 2048) . 'a.css'],
            'relative' => ['a.css'],
            'empty' => [''],
        ];
    }

    /**
     * @dataProvider provideEncodings
     */
    #[DataProvider('provideEncodings')]
    public function testEncode(string $path, string $encoded): void
    {
        self::assertSame($encoded, PathSanitizer::encode($path));
        self::assertSame($path, (new PathSanitizer())->sanitize($encoded));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEncodings(): array
    {
        return [
            'plain' => ['/css/a-b_c.d~e.css', '/css/a-b_c.d~e.css'],
            'space' => ['/images/my file.png', '/images/my%20file.png'],
            'percent' => ['/100%.png', '/100%25.png'],
            'UTF-8' => ['/images/café.png', '/images/caf%C3%A9.png'],
            'sub-delimiters kept' => ["/a!\$&'()*+,;=:@b.css", "/a!\$&'()*+,;=:@b.css"],
            'query and fragment characters' => ['/a?b#c.css', '/a%3Fb%23c.css'],
        ];
    }
}
