<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Support;

use Mfonte\ProxyCdn\Tests\Support\StrictErrorHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Tests\Support\StrictErrorHandler
 */
#[CoversClass(StrictErrorHandler::class)]
final class StrictErrorHandlerTest extends TestCase
{
    private const VENDOR = '/srv/app/vendor';

    /**
     * @var int
     */
    private $errorReporting;

    protected function set_up(): void
    {
        parent::set_up();
        $this->errorReporting = error_reporting();
        error_reporting(E_ALL);
    }

    protected function tear_down(): void
    {
        error_reporting($this->errorReporting);
        parent::tear_down();
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function provideReportedErrors(): array
    {
        return [
            'deprecation in src/' => [E_DEPRECATED, '/srv/app/src/Kernel.php'],
            'user deprecation in tests/' => [E_USER_DEPRECATED, '/srv/app/tests/Unit/KernelTest.php'],
            'warning in src/' => [E_WARNING, '/srv/app/src/Kernel.php'],
            'notice in src/' => [E_NOTICE, '/srv/app/src/Kernel.php'],
            'warning in vendor/' => [E_WARNING, self::VENDOR . '/symfony/console/Application.php'],
            'deprecation in a sibling of vendor/' => [E_DEPRECATED, '/srv/app/vendor-old/lib.php'],
        ];
    }

    /**
     * @dataProvider provideReportedErrors
     */
    #[DataProvider('provideReportedErrors')]
    public function testReportedErrorsBecomeExceptions(int $severity, string $file): void
    {
        $handler = new StrictErrorHandler(self::VENDOR);

        try {
            $handler($severity, 'Something happened', $file, 42);
            self::fail('An ErrorException was expected');
        } catch (\ErrorException $exception) {
            self::assertSame('Something happened', $exception->getMessage());
            self::assertSame($severity, $exception->getSeverity());
            self::assertSame($file, $exception->getFile());
            self::assertSame(42, $exception->getLine());
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideDeprecationSeverities(): array
    {
        return [
            'E_DEPRECATED' => [E_DEPRECATED],
            'E_USER_DEPRECATED' => [E_USER_DEPRECATED],
        ];
    }

    /**
     * @dataProvider provideDeprecationSeverities
     */
    #[DataProvider('provideDeprecationSeverities')]
    public function testDeprecationsRaisedInsideVendorAreIgnored(int $severity): void
    {
        $handler = new StrictErrorHandler(self::VENDOR . '/');

        self::assertTrue($handler($severity, 'Deprecated', self::VENDOR . '/symfony/console/Application.php', 1));
    }

    public function testErrorsExcludedFromErrorReportingAreLeftToPhp(): void
    {
        $handler = new StrictErrorHandler(self::VENDOR);
        error_reporting(E_ALL & ~E_WARNING);

        self::assertFalse($handler(E_WARNING, 'Silenced', '/srv/app/src/Kernel.php', 1));
    }

    public function testTheBootstrapHandlerIsActiveWhileTestsRun(): void
    {
        $this->expectException(\ErrorException::class);
        $this->expectExceptionMessage('Raised from tests/');

        trigger_error('Raised from tests/', E_USER_DEPRECATED);
    }
}
