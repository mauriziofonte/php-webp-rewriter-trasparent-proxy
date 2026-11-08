<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Observability;

use Mfonte\ProxyCdn\Observability\MetricsAuthenticator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Observability\MetricsAuthenticator
 */
#[CoversClass(MetricsAuthenticator::class)]
final class MetricsAuthenticatorTest extends TestCase
{
    private const KEY = 'metrics-key-0123456789';

    /**
     * @dataProvider provideAuthorizations
     */
    #[DataProvider('provideAuthorizations')]
    public function testOnlyAConfiguredKeyIsAccepted(?string $authorization, bool $accepted): void
    {
        $authenticator = new MetricsAuthenticator([self::KEY, 'sha256:' . hash('sha256', 'digest-only-key-000001')]);

        self::assertSame($accepted, $authenticator->authenticate(self::request($authorization)));
    }

    /**
     * @return array<string, array{string|null, bool}>
     */
    public static function provideAuthorizations(): array
    {
        return [
            'key' => ['Bearer ' . self::KEY, true],
            'scheme in any case' => ['bearer ' . self::KEY, true],
            'key of a digest' => ['Bearer digest-only-key-000001', true],
            'the digest itself' => ['Bearer ' . hash('sha256', 'digest-only-key-000001'), false],
            'wrong key' => ['Bearer metrics-key-0123456780', false],
            'no header' => [null, false],
            'basic' => ['Basic ' . base64_encode('user:' . self::KEY), false],
            'key with suffix' => ['Bearer ' . self::KEY . ' extra', false],
        ];
    }

    public function testWithoutKeysEveryRequestIsRefused(): void
    {
        $authenticator = new MetricsAuthenticator([]);

        self::assertTrue($authenticator->isDisabled());
        self::assertFalse($authenticator->authenticate(self::request('Bearer ' . self::KEY)));
    }

    public function testParseKeys(): void
    {
        self::assertSame([hash('sha256', self::KEY), str_repeat('ab', 32)], MetricsAuthenticator::parseKeys([self::KEY, 'SHA256:' . str_repeat('AB', 32)]));
        self::assertNull(MetricsAuthenticator::parseKeys(['short']));
        self::assertNull(MetricsAuthenticator::parseKeys(['sha256:' . str_repeat('g', 64)]));
        self::assertNull(MetricsAuthenticator::parseKeys(["metrics-key-\u{e9}-0123456789"]));
        self::assertSame([], MetricsAuthenticator::parseKeys([]));
    }

    private static function request(?string $authorization): Request
    {
        $server = $authorization === null ? [] : ['HTTP_AUTHORIZATION' => $authorization];

        return Request::create('https://cdn.example.test/metrics', 'GET', [], [], [], $server);
    }
}
