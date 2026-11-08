<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Image;

use Mfonte\ProxyCdn\Image\FormatNegotiator;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Image\FormatNegotiator
 */
#[CoversClass(FormatNegotiator::class)]
final class FormatNegotiatorTest extends TestCase
{
    /**
     * The web server's rules (PRX-6): AVIF before WEBP whatever the
     * qualities, explicit types only, q=0 refuses.
     *
     * @dataProvider provideAcceptHeaders
     *
     * @param list<string> $expected
     */
    #[DataProvider('provideAcceptHeaders')]
    public function testAcceptedFormatsFollowTheWebServer(string $accept, array $expected): void
    {
        self::assertSame($expected, (new FormatNegotiator(['avif', 'webp']))->acceptedFormats($accept));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function provideAcceptHeaders(): array
    {
        return [
            'none' => ['', []],
            'browser' => ['image/avif,image/webp,image/apng,*/*;q=0.8', ['avif', 'webp']],
            'webp only' => ['image/webp,*/*', ['webp']],
            'avif preferred whatever the quality' => ['image/webp;q=1, image/avif;q=0.1', ['avif', 'webp']],
            'refused with q=0' => ['image/avif;q=0, image/webp', ['webp']],
            'wildcards do not count' => ['image/*,*/*', []],
            'case and spaces' => ['IMAGE/WEBP ; q=0.5', ['webp']],
        ];
    }

    public function testTheEnabledFormatsKeepTheOrderOfTheWebServer(): void
    {
        $negotiator = new FormatNegotiator(['webp', 'avif', 'jxl']);

        self::assertSame(['avif', 'webp'], $negotiator->getEnabledFormats());
        self::assertTrue($negotiator->isEnabled('webp'));
        self::assertFalse($negotiator->isEnabled('jxl'));
    }

    public function testNoFormatIsMadeWithoutConverter(): void
    {
        self::assertSame([], FormatNegotiator::fromConfig(TestConfig::create())->getEnabledFormats());
        self::assertSame(['webp'], FormatNegotiator::fromConfig(TestConfig::create(['IMAGE_CONVERTER' => 'local', 'IMAGE_FORMATS' => 'webp']))->getEnabledFormats());
    }
}
