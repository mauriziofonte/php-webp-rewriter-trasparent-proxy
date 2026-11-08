<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Origin;

use Mfonte\ProxyCdn\Exception\InvalidArgumentException;
use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Origin\HttpClientOptions
 */
#[CoversClass(HttpClientOptions::class)]
final class HttpClientOptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $options = new HttpClientOptions();

        self::assertSame(5.0, $options->getConnectTimeout());
        self::assertSame(30.0, $options->getTimeout());
        self::assertSame(1024, $options->getLowSpeedLimit());
        self::assertSame(10, $options->getLowSpeedTime());
        self::assertTrue($options->shouldVerifyTls());
        self::assertNull($options->getCaFile());
        self::assertFalse($options->shouldFollowRedirects());
        self::assertSame(5, $options->getMaxRedirects());
        self::assertSame(64 * 1024 * 1024, $options->getMaxBodySize());
        self::assertNull($options->getConnectTo());
        self::assertTrue($options->isCompressionEnabled());
        self::assertTrue($options->isHttp2Enabled());
        self::assertSame(sys_get_temp_dir(), $options->getTemporaryDirectory());
    }

    /**
     * The default User-Agent names this software, not a browser
     * (finding P-22).
     */
    public function testTheDefaultUserAgentIsHonestP22(): void
    {
        $userAgent = (new HttpClientOptions())->getUserAgent();

        self::assertStringStartsWith('mfonte-proxy-cdn/3 ', $userAgent);
        self::assertStringContainsString('https://github.com/mauriziofonte/php-webp-rewriter-trasparent-proxy', $userAgent);
        self::assertStringNotContainsString('Mozilla', $userAgent);
    }

    public function testWithMethodsReturnModifiedCopies(): void
    {
        $options = new HttpClientOptions();

        $copy = $options
            ->withTimeouts(0.5, 2.5)
            ->withLowSpeedLimit(10, 3)
            ->withTlsVerification(false, '/etc/ssl/ca.pem')
            ->withRedirects(true, 3)
            ->withMaxBodySize(1000)
            ->withUserAgent('custom/1.0')
            ->withConnectTo('192.0.2.10:8443')
            ->withCompression(false)
            ->withHttp2(false)
            ->withTemporaryDirectory('/var/tmp');

        self::assertSame([0.5, 2.5], [$copy->getConnectTimeout(), $copy->getTimeout()]);
        self::assertSame([10, 3], [$copy->getLowSpeedLimit(), $copy->getLowSpeedTime()]);
        self::assertFalse($copy->shouldVerifyTls());
        self::assertSame('/etc/ssl/ca.pem', $copy->getCaFile());
        self::assertTrue($copy->shouldFollowRedirects());
        self::assertSame(3, $copy->getMaxRedirects());
        self::assertSame(1000, $copy->getMaxBodySize());
        self::assertSame('custom/1.0', $copy->getUserAgent());
        self::assertSame('192.0.2.10:8443', $copy->getConnectTo());
        self::assertFalse($copy->isCompressionEnabled());
        self::assertFalse($copy->isHttp2Enabled());
        self::assertSame('/var/tmp', $copy->getTemporaryDirectory());
        self::assertEquals(new HttpClientOptions(), $options);
    }

    /**
     * @dataProvider provideInvalidSettings
     */
    #[DataProvider('provideInvalidSettings')]
    public function testInvalidSettingsAreRefused(string $method, string $argument): void
    {
        $options = new HttpClientOptions();
        $this->expectException(InvalidArgumentException::class);

        switch ($method) {
            case 'timeouts':
                list($connect, $total) = explode(',', $argument);
                $options->withTimeouts((float) $connect, (float) $total);
                break;
            case 'lowSpeed':
                list($limit, $time) = explode(',', $argument);
                $options->withLowSpeedLimit((int) $limit, (int) $time);
                break;
            case 'redirects':
                $options->withRedirects(true, (int) $argument);
                break;
            case 'maxBodySize':
                $options->withMaxBodySize((int) $argument);
                break;
            case 'userAgent':
                $options->withUserAgent($argument);
                break;
            default:
                $options->withConnectTo($argument);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideInvalidSettings(): array
    {
        return [
            'zero connect timeout' => ['timeouts', '0,10'],
            'total below connect' => ['timeouts', '5,1'],
            'zero low speed limit' => ['lowSpeed', '0,10'],
            'zero low speed time' => ['lowSpeed', '1024,0'],
            'negative redirects' => ['redirects', '-1'],
            'too many redirects' => ['redirects', '51'],
            'zero body size' => ['maxBodySize', '0'],
            'empty User-Agent' => ['userAgent', ''],
            'User-Agent with CRLF' => ['userAgent', "agent\r\nX-Injected: 1"],
            'User-Agent with UTF-8' => ['userAgent', 'agént'],
            'User-Agent too long' => ['userAgent', str_repeat('a', 513)],
            'connect-to with a path' => ['connectTo', '192.0.2.10:80/x'],
            'connect-to with a bad port' => ['connectTo', '192.0.2.10:99999'],
            'connect-to with port 0' => ['connectTo', '192.0.2.10:0'],
            'connect-to with an unbracketed IPv6' => ['connectTo', '2001:db8::1:443'],
            'connect-to with a bracketed name' => ['connectTo', '[origin]:443'],
            'empty connect-to' => ['connectTo', ''],
        ];
    }

    /**
     * @dataProvider provideAddresses
     *
     * @param array{0: string, 1: int|null} $expected
     */
    #[DataProvider('provideAddresses')]
    public function testParseAddress(string $address, array $expected): void
    {
        self::assertSame($expected, HttpClientOptions::parseAddress($address));
        self::assertSame($address, (new HttpClientOptions())->withConnectTo($address)->getConnectTo());
    }

    /**
     * @return array<string, array{string, array{0: string, 1: int|null}}>
     */
    public static function provideAddresses(): array
    {
        return [
            'IPv4 and port' => ['192.0.2.10:443', ['192.0.2.10', 443]],
            'IPv4 alone' => ['192.0.2.10', ['192.0.2.10', null]],
            'name and port' => ['origin.internal:8080', ['origin.internal', 8080]],
            'IPv6 and port' => ['[2001:db8::1]:443', ['2001:db8::1', 443]],
            'IPv6 alone' => ['[::1]', ['::1', null]],
        ];
    }
}
