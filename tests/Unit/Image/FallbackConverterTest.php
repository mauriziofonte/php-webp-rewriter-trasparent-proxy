<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Image;

use Mfonte\ProxyCdn\Image\ConversionFailure;
use Mfonte\ProxyCdn\Image\FallbackConverter;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Image\FakeImageConverter;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Image\FallbackConverter
 */
#[CoversClass(FallbackConverter::class)]
final class FallbackConverterTest extends TestCase
{
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

    public function testAnUnavailableApiFallsBackToLocalConversion(): void
    {
        $remote = new FakeImageConverter();
        $remote->setUnavailable(true);
        $local = new FakeImageConverter();
        $logger = new MemoryLogger();

        $result = (new FallbackConverter($remote, $local, $logger))->convert($this->directory->write('in', 'PNG'), $this->directory->path('out'), new ConversionRequest('webp'), 'req');

        self::assertSame('webp', $result->getOutputFormat());
        self::assertCount(1, $remote->getCalls());
        self::assertSame([['webp', 'PNG', null, 'req']], $local->getCalls());
        self::assertSame('concurrency_unavailable', $logger->recordsWithMessage('remote conversion unavailable, converting locally')[0]['context']['error_code'] ?? null);
    }

    public function testARejectedImageIsNotTriedAgainLocally(): void
    {
        $local = new FakeImageConverter();

        try {
            (new FallbackConverter(new FakeImageConverter(), $local, new MemoryLogger()))->convert($this->directory->write('in', 'broken'), $this->directory->path('out'), new ConversionRequest('webp'));
            self::fail('The conversion did not fail');
        } catch (ConversionFailure $failure) {
            self::assertFalse($failure->isTransient());
        }
        self::assertSame([], $local->getCalls());
    }

    public function testTheCapabilitiesAreTheApisWhileItAnswers(): void
    {
        $remote = new FakeImageConverter();
        $remote->setCapabilities(FakeImageConverter::capabilities(['avif']));
        $local = new FakeImageConverter();
        $local->setCapabilities(FakeImageConverter::capabilities(['webp']));
        $converter = new FallbackConverter($remote, $local, new MemoryLogger());

        self::assertSame(['avif'], $converter->getCapabilities() === null ? null : $converter->getCapabilities()->getOutputFormats());
        $remote->setCapabilities(null);
        self::assertSame(['webp'], $converter->getCapabilities() === null ? null : $converter->getCapabilities()->getOutputFormats());
        self::assertSame('fake', $converter->getName());
    }
}
