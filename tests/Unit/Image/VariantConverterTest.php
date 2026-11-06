<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Image;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Cache\CacheLocation;
use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Image\ConversionFailures;
use Mfonte\ProxyCdn\Image\FormatNegotiator;
use Mfonte\ProxyCdn\Image\VariantConverter;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Image\FakeImageConverter;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Image\VariantConverter
 */
#[CoversClass(VariantConverter::class)]
final class VariantConverterTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/Image/files/';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var FakeImageConverter
     */
    private $converter;

    /**
     * @var MemoryLogger
     */
    private $logger;

    /**
     * @var CacheLocation
     */
    private $location;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->converter = new FakeImageConverter();
        $this->logger = new MemoryLogger();
        $this->location = (new CacheKeyResolver($this->directory->path('_cache')))->resolve('cdn.example.test', '/images/a.png', '');
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testEveryEnabledVariantIsMadeVerifiedAndMovedIntoPlace(): void
    {
        $source = $this->directory->write('source.png', 'PNG-BYTES');

        $this->variants()->convertAll($this->location, $source, 'req-1');

        self::assertFileEquals(self::FIXTURES . 'variant.avif', $this->location->getVariantFile('avif'));
        self::assertFileEquals(self::FIXTURES . 'variant.webp', $this->location->getVariantFile('webp'));
        self::assertSame(0644, fileperms($this->location->getVariantFile('webp')) & 0777);
        self::assertSame([['avif', 'PNG-BYTES', null, 'req-1'], ['webp', 'PNG-BYTES', null, 'req-1']], $this->converter->getCalls());
        // The private directories are gone.
        self::assertSame(['.', '..'], scandir($this->directory->path('_cache/.work')));
    }

    public function testAFormatTheConverterCannotMakeIsSkippedQuietly(): void
    {
        $this->converter->setCapabilities(FakeImageConverter::capabilities(['webp']));
        $source = $this->directory->write('source.png', 'PNG-BYTES');

        $this->variants()->convertAll($this->location, $source, null);

        self::assertFileDoesNotExist($this->location->getVariantFile('avif'));
        self::assertFileExists($this->location->getVariantFile('webp'));
        self::assertSame(['webp'], $this->converter->getFormats());
        self::assertSame([], $this->logger->recordsWithMessage('image conversion failed, the original is served'));
    }

    /**
     * A format the converter cannot produce is remembered: the hits that
     * PHP serves to the clients that accept it never ask the converter
     * again (no capability query, no build of the library's converter, no
     * /healthz) until the capabilities may have changed.
     */
    public function testAFormatTheConverterCannotMakeIsNotAskedAgainOnHits(): void
    {
        $this->converter->setCapabilities(FakeImageConverter::capabilities(['webp']));
        $variants = $this->variants();
        $variants->convertAll($this->location, $this->directory->write('source.png', 'PNG-BYTES'), null);
        $asked = $this->converter->getCapabilityCalls();

        for ($hit = 0; $hit < 3; ++$hit) {
            self::assertFalse($variants->convertOnHit($this->location, 'avif', null));
        }

        self::assertSame($asked, $this->converter->getCapabilityCalls());
        self::assertSame(['webp'], $this->converter->getFormats());
    }

    /**
     * Finding C-11: an output that is not what the converter reported is
     * not stored.
     */
    public function testAnOutputThatFailsTheVerificationIsNotStoredC11(): void
    {
        $this->converter->reportFormat('avif');
        $source = $this->directory->write('source.png', 'PNG-BYTES');

        (new VariantConverter($this->converter, new FormatNegotiator(['webp']), $this->failures(), new FilesystemStorage(), $this->directory->path('_cache/.work'), 30, 1048576, $this->logger))
            ->convertAll($this->location, $source, null);

        self::assertFileDoesNotExist($this->location->getVariantFile('webp'));
        $records = $this->logger->recordsWithMessage('image conversion failed, the original is served');
        self::assertCount(1, $records);
        self::assertSame('output_verification_failed', $records[0]['context']['conversion_error_code'] ?? null);
        self::assertArrayNotHasKey('error_code', $records[0]['context']);
        self::assertSame('/images/a.png', $records[0]['context']['path'] ?? null);
    }

    public function testAnOutputLargerThanTheLimitIsNotStored(): void
    {
        $source = $this->directory->write('source.png', 'PNG-BYTES');

        (new VariantConverter($this->converter, new FormatNegotiator(['webp']), $this->failures(), new FilesystemStorage(), $this->directory->path('_cache/.work'), 30, 10, $this->logger))
            ->convertAll($this->location, $source, null);

        self::assertFileDoesNotExist($this->location->getVariantFile('webp'));
    }

    /**
     * Finding P-10: a failed conversion is not tried again on every hit.
     */
    public function testAFailureIsNotRetriedOnEveryHitP10(): void
    {
        $this->directory->write('_cache/cdn.example.test/images/a.png', 'broken PNG');
        $variants = $this->variants();

        self::assertFalse($variants->convertOnHit($this->location, 'webp', null));
        self::assertFalse($variants->convertOnHit($this->location, 'webp', null));

        self::assertSame(['webp'], $this->converter->getFormats());
        $records = $this->logger->recordsWithMessage('image conversion failed, the original is served');
        self::assertCount(1, $records);
        self::assertFalse($records[0]['context']['transient'] ?? null);
        self::assertSame('conversion_failed', $records[0]['context']['conversion_error_code'] ?? null);
        self::assertSame(422, $records[0]['context']['status'] ?? null);
    }

    /**
     * An entry waits for one conversion timeout at most, not one per
     * format, when the converter cannot convert now.
     */
    public function testTheOtherFormatsAreNotTriedOnceTheConverterIsUnavailable(): void
    {
        $this->directory->write('_cache/cdn.example.test/images/a.png', 'busy PNG');
        $variants = $this->variants();

        $variants->convertAll($this->location, $this->location->getFile(), null);

        self::assertSame(['avif'], $this->converter->getFormats());
        self::assertFalse($variants->convertOnHit($this->location, 'webp', null), 'remembered as transient');
        self::assertSame(['avif'], $this->converter->getFormats());
    }

    public function testANewVersionOfTheEntryIsConvertedDespiteAMarker(): void
    {
        $variants = $this->variants();
        $variants->convertAll($this->location, $this->directory->write('old.png', 'broken PNG'), null);
        self::assertFileDoesNotExist($this->location->getVariantFile('webp'));

        $variants->convertAll($this->location, $this->directory->write('new.png', 'PNG'), null);

        self::assertFileExists($this->location->getVariantFile('webp'));
    }

    public function testAnUnavailableConverterIsRetriedAfterTheTransientTtl(): void
    {
        $this->converter->setCapabilities(null);
        $this->directory->write('_cache/cdn.example.test/images/a.png', 'PNG');
        $variants = $this->variants();

        self::assertFalse($variants->convertOnHit($this->location, 'webp', null));
        $this->converter->setCapabilities(FakeImageConverter::capabilities(['webp']));
        self::assertFalse($variants->convertOnHit($this->location, 'webp', null), 'remembered for the transient TTL');

        $records = $this->logger->recordsWithMessage('image conversion failed, the original is served');
        self::assertTrue($records[0]['context']['transient'] ?? null);
        self::assertSame('converter_unavailable', $records[0]['context']['error_code'] ?? null);
        self::assertSame([], $this->converter->getCalls());
    }

    public function testADisabledFormatIsNotConvertedOnHit(): void
    {
        $this->directory->write('_cache/cdn.example.test/images/a.png', 'PNG');

        $converted = (new VariantConverter($this->converter, new FormatNegotiator(['webp']), $this->failures(), new FilesystemStorage(), $this->directory->path('_cache/.work'), 30, 1048576, $this->logger))
            ->convertOnHit($this->location, 'avif', null);

        self::assertFalse($converted);
        self::assertSame([], $this->converter->getCalls());
    }

    public function testAResizedEntryIsStoredWithItsVariants(): void
    {
        $resized = (new CacheKeyResolver($this->directory->path('_cache')))->resolve('cdn.example.test', '/_resize/320/' . str_repeat('a', 32) . '/images/a.png', '');

        $outcome = $this->variants()->resizeEntry($resized, self::FIXTURES . 'image.png', 320, 'req-2');

        self::assertSame(VariantConverter::RESIZED, $outcome);
        self::assertFileEquals(self::FIXTURES . 'resized.png', $resized->getFile());
        self::assertFileExists($resized->getVariantFile('webp'));
        self::assertFileExists($resized->getVariantFile('avif'));
        $calls = $this->converter->getCalls();
        self::assertSame(['original', 320, 'req-2'], [$calls[0][0], $calls[0][2], $calls[0][3]]);
        // The variants are made from the resized image.
        self::assertSame((string) file_get_contents(self::FIXTURES . 'resized.png'), $calls[1][1]);
    }

    public function testAnImageOfNoKnownFormatIsNotResized(): void
    {
        $outcome = $this->variants()->resizeEntry($this->location, $this->directory->write('source.png', 'PNG-BYTES'), 320, null);

        self::assertSame(VariantConverter::REJECTED, $outcome);
        self::assertFileDoesNotExist($this->location->getFile());
        self::assertSame([], $this->converter->getCalls());
    }

    public function testAResizeTheConverterCannotMakeNowIsUnavailable(): void
    {
        $this->converter->setUnavailable(true);

        $outcome = $this->variants()->resizeEntry($this->location, self::FIXTURES . 'image.png', 320, null);

        self::assertSame(VariantConverter::UNAVAILABLE, $outcome);
        self::assertFileDoesNotExist($this->location->getFile());
        self::assertSame('concurrency_unavailable', $this->logger->recordsWithMessage('image resize failed')[0]['context']['error_code'] ?? null);
    }

    /**
     * A resize URL as the first request of an empty cache: the cache root
     * that the work directory creates keeps the mode of the directories
     * of the cache (the web server must enter it); only the work
     * directory is private.
     */
    public function testTheWorkDirectoryDoesNotMakeTheCacheRootPrivate(): void
    {
        self::assertDirectoryDoesNotExist($this->directory->path('_cache'));
        $resized = (new CacheKeyResolver($this->directory->path('_cache')))->resolve('cdn.example.test', '/_resize/320/' . str_repeat('a', 32) . '/images/a.png', '');

        $this->variants()->resizeEntry($resized, self::FIXTURES . 'image.png', 320, null);

        (new FilesystemStorage())->ensureDirectory($this->directory->path('reference'));
        $expected = fileperms($this->directory->path('reference')) & 0777;
        self::assertSame($expected, fileperms($this->directory->path('_cache')) & 0777);
        self::assertSame(0700, fileperms($this->directory->path('_cache/.work')) & 0777);
    }

    private function variants(): VariantConverter
    {
        return new VariantConverter($this->converter, new FormatNegotiator(['avif', 'webp']), $this->failures(), new FilesystemStorage(), $this->directory->path('_cache/.work'), 30, 1048576, $this->logger);
    }

    private function failures(): ConversionFailures
    {
        return new ConversionFailures(new FilesystemStorage(), $this->directory->path('state/conversion-failures'));
    }
}
