<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Image;

use Mfonte\ProxyCdn\Image\SignedUrlVerifier;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Image\SignedUrlVerifier
 */
#[CoversClass(SignedUrlVerifier::class)]
final class SignedUrlVerifierTest extends TestCase
{
    private const KEY = 'resize-key-0123456789abcdef0123456789';

    public function testTheSignatureIsTheDocumentedHmac(): void
    {
        $expected = substr(hash_hmac('sha256', "resize\ncdn.example.test\n320\n/images/a b.png\nv=2", self::KEY), 0, 32);

        self::assertSame($expected, self::verifier()->sign('CDN.example.test', 320, '/images/a b.png', 'v=2'));
        self::assertSame('/_resize/320/' . $expected . '/images/a b.png', self::verifier()->pathOf('cdn.example.test', 320, '/images/a b.png', 'v=2'));
    }

    public function testASignedUrlOfAListedWidthIsAccepted(): void
    {
        $verifier = self::verifier();
        $path = $verifier->pathOf('cdn.example.test', 640, '/images/a.png', '');

        self::assertSame([640, '/images/a.png'], $verifier->verify('cdn.example.test', $path, ''));
    }

    /**
     * @dataProvider provideRefusedUrls
     */
    #[DataProvider('provideRefusedUrls')]
    public function testARefusedUrl(string $host, string $path, string $query): void
    {
        self::assertSame(SignedUrlVerifier::REFUSED, self::verifier()->verify($host, $path, $query));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideRefusedUrls(): array
    {
        $verifier = self::verifier();
        $signed = $verifier->sign('cdn.example.test', 320, '/images/a.png', '');
        // A width outside RESIZE_WIDTHS, signed with the key: still refused (risk L-14).
        $unlisted = $verifier->pathOf('cdn.example.test', 300, '/images/a.png', '');

        return [
            'unsigned' => ['cdn.example.test', '/_resize/320/images/a.png', ''],
            'wrong signature' => ['cdn.example.test', '/_resize/320/' . str_repeat('0', 32) . '/images/a.png', ''],
            'upper-case signature' => ['cdn.example.test', '/_resize/320/' . strtoupper($signed) . '/images/a.png', ''],
            'short signature' => ['cdn.example.test', '/_resize/320/' . substr($signed, 0, 31) . '/images/a.png', ''],
            'unlisted width' => ['cdn.example.test', $unlisted, ''],
            'width with leading zero' => ['cdn.example.test', '/_resize/0320/' . $signed . '/images/a.png', ''],
            'another host' => ['other.example.test', '/_resize/320/' . $signed . '/images/a.png', ''],
            'another query' => ['cdn.example.test', '/_resize/320/' . $signed . '/images/a.png', 'v=2'],
            'another path' => ['cdn.example.test', '/_resize/320/' . $signed . '/images/b.png', ''],
            'no image path' => ['cdn.example.test', '/_resize/320/' . $signed, ''],
        ];
    }

    public function testAnotherPathIsNotAResizeUrl(): void
    {
        self::assertSame(SignedUrlVerifier::NOT_RESIZE, self::verifier()->verify('cdn.example.test', '/images/_resize/320/x.png', ''));
        self::assertFalse(SignedUrlVerifier::isResizePath('/_resizes/a.png'));
    }

    public function testMaskHidesTheSignatures(): void
    {
        self::assertSame(
            'GET /_resize/320/<masked>/images/a.png and /_resize/640/<masked>/b.png',
            SignedUrlVerifier::mask('GET /_resize/320/0123456789abcdef0123456789abcdef/images/a.png and /_resize/640/ABC/b.png')
        );
    }

    public function testResizingIsOffWithoutKeyWidthsOrConverter(): void
    {
        self::assertNull(SignedUrlVerifier::fromConfig(TestConfig::create()));
        self::assertNull(SignedUrlVerifier::fromConfig(TestConfig::create(['IMAGE_CONVERTER' => 'local', 'RESIZE_KEY' => self::KEY])));
        $verifier = SignedUrlVerifier::fromConfig(TestConfig::create(['IMAGE_CONVERTER' => 'local', 'RESIZE_KEY' => self::KEY, 'RESIZE_WIDTHS' => '640,320']));
        self::assertNotNull($verifier);
        self::assertSame([320, 640], $verifier->getWidths());
    }

    private static function verifier(): SignedUrlVerifier
    {
        return new SignedUrlVerifier(self::KEY, [320, 640]);
    }
}
