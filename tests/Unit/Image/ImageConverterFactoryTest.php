<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Image;

use Mfonte\ProxyCdn\Http\ProxyKernelFactory;
use Mfonte\ProxyCdn\Image\FallbackConverter;
use Mfonte\ProxyCdn\Image\ImageConverterFactory;
use Mfonte\ProxyCdn\Image\LocalConverter;
use Mfonte\ProxyCdn\Image\RemoteConverter;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Image\ImageConverterFactory
 * @covers \Mfonte\ProxyCdn\Image\LocalConverter
 */
#[CoversClass(ImageConverterFactory::class)]
#[CoversClass(LocalConverter::class)]
final class ImageConverterFactoryTest extends TestCase
{
    private const REMOTE = ['IMAGE_CONVERTER' => 'remote', 'IMAGE_API_URL' => 'https://api.example.test', 'IMAGE_API_KEY' => '0123456789abcdef'];

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testTheConverterOfTheConfiguration(): void
    {
        $logger = new MemoryLogger();
        $state = $this->directory->path('var');

        self::assertNull(ImageConverterFactory::create(TestConfig::create(), $state, $logger));
        self::assertInstanceOf(LocalConverter::class, ImageConverterFactory::create(TestConfig::create(['IMAGE_CONVERTER' => 'local']), $state, $logger));
        self::assertInstanceOf(RemoteConverter::class, ImageConverterFactory::create(TestConfig::create(self::REMOTE), $state, $logger));
        self::assertInstanceOf(FallbackConverter::class, ImageConverterFactory::create(TestConfig::create(['IMAGE_API_FALLBACK' => 'true'] + self::REMOTE), $state, $logger));
    }

    public function testTheLocalStateIsPrivate(): void
    {
        ImageConverterFactory::create(TestConfig::create(['IMAGE_CONVERTER' => 'local']), $this->directory->path('var'), new MemoryLogger());

        self::assertSame(0700, fileperms($this->directory->path('var/converter-cache')) & 0777);
        self::assertSame(0700, fileperms($this->directory->path('var/convert')) & 0777);
    }

    /**
     * Local conversion with what this PHP has: the capabilities are read
     * without an exception, and a PNG becomes a WEBP when a backend can
     * make one.
     */
    public function testLocalConversionWithTheBackendsOfThisHost(): void
    {
        $converter = ImageConverterFactory::create(TestConfig::create(['IMAGE_CONVERTER' => 'local']), $this->directory->path('var'), new MemoryLogger());
        self::assertNotNull($converter);
        $capabilities = $converter->getCapabilities();
        if ($capabilities === null || !$capabilities->supportsInput('png') || !$capabilities->supportsOutput('webp')) {
            self::markTestSkipped('No local backend converts PNG to WEBP here');
        }
        $source = $this->directory->write('in.png', (string) file_get_contents(__DIR__ . '/../../Fixtures/Image/files/image.png'));

        $result = $converter->convert($source, $this->directory->path('out.webp'), new ConversionRequest('webp'));

        self::assertSame([4, 3], [$result->getWidth(), $result->getHeight()]);
        self::assertFileExists($result->getOutputPath());
    }

    /**
     * Carried item: the coalescing timeout covers the fetch and the
     * conversions of an entry.
     */
    public function testTheWorstCaseOfTheConversionsOfAnEntry(): void
    {
        self::assertSame(0, ImageConverterFactory::worstCaseSeconds(TestConfig::create()));
        // Two formats, 55 seconds each.
        self::assertSame(110, ImageConverterFactory::worstCaseSeconds(TestConfig::create(['IMAGE_CONVERTER' => 'local'])));
        // Two formats of 55 + 5 (connect), one discovery of 30 + 5.
        self::assertSame(155, ImageConverterFactory::worstCaseSeconds(TestConfig::create(self::REMOTE)));
        // With the local fallback, a resize width, and WEBP only: 2 x (60 + 55) + 35.
        self::assertSame(265, ImageConverterFactory::worstCaseSeconds(TestConfig::create([
            'IMAGE_API_FALLBACK' => 'true',
            'IMAGE_FORMATS' => 'webp',
            'RESIZE_WIDTHS' => '320',
            'RESIZE_KEY' => str_repeat('k', 32),
        ] + self::REMOTE)));

        self::assertSame(60, ProxyKernelFactory::coalescingTimeout(TestConfig::create(), false));
        self::assertSame(60 + 155, ProxyKernelFactory::coalescingTimeout(TestConfig::create(self::REMOTE), true));
    }
}
