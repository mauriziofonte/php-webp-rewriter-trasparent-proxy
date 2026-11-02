<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Image;

use Mfonte\ProxyCdn\Image\LocalConverter;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\DetectionResult;
use Mfonte\SecureImageConverter\Isolation\IsolationDetectorInterface;
use Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Image\LocalConverter
 */
#[CoversClass(LocalConverter::class)]
final class LocalConverterTest extends TestCase
{
    /**
     * The library's converter, with its isolation detection, is built on
     * the first conversion or capability query only, once per request:
     * SecureConverterBuilder::build() costs about 35 ms cold.
     */
    public function testTheConverterIsBuiltOnFirstUseOnly(): void
    {
        $detector = new class () implements IsolationDetectorInterface {
            /**
             * @var int
             */
            public $detections = 0;

            public function detect(): DetectionResult
            {
                ++$this->detections;
                $reasons = [];
                foreach (IsolationLevel::all() as $level) {
                    if (IsolationLevel::rank($level) > IsolationLevel::rank(IsolationLevel::NONE)) {
                        $reasons[$level] = 'not probed in this test';
                    }
                }

                return new DetectionResult(IsolationLevel::NONE, $reasons, time());
            }

            public function refresh(): DetectionResult
            {
                return $this->detect();
            }
        };

        $converter = new LocalConverter((new SecureConverterBuilder())->withIsolationDetector($detector), new MemoryLogger());

        self::assertSame('local', $converter->getName());
        self::assertSame(0, $detector->detections);

        $converter->getCapabilities();
        $converter->getCapabilities();

        self::assertSame(1, $detector->detections);
    }
}
