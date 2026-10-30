<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Console;

use Mfonte\ProxyCdn\Config\ConfigLoader;
use Mfonte\ProxyCdn\Console\CheckEnvCommand;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The output is the one of the 2.x check-env script.
 *
 * @covers \Mfonte\ProxyCdn\Console\CheckEnvCommand
 */
#[CoversClass(CheckEnvCommand::class)]
final class CheckEnvCommandTest extends TestCase
{
    private const VALID_ENV = "SOURCE_ORIGIN=\"https://origin.example.test\"\nCACHEABLE_EXTENSIONS=\"css,js,png\"\nUPSTREAM_VERIFY_TLS=true\n";

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var array<string, string>
     */
    private $environment = [];

    /**
     * @var bool
     */
    private $cwebpInstalled = true;

    /**
     * @var bool
     */
    private $curlLoaded = true;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->environment = [];
        $this->cwebpInstalled = true;
        $this->curlLoaded = true;
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testTheNameAndTheOption(): void
    {
        $command = $this->command();

        self::assertSame('check-env', $command->getName());
        self::assertTrue($command->getDefinition()->hasOption('informational'));
    }

    public function testAValidConfigurationPasses(): void
    {
        $this->directory->write('.env', self::VALID_ENV);

        list($exitCode, $display) = $this->runCommand([]);

        self::assertSame(0, $exitCode);
        self::assertSame("✓ Environment check passed.\n", $display);
    }

    public function testAnEnvironmentOnlyConfigurationPasses(): void
    {
        $this->environment = [
            'SOURCE_ORIGIN' => 'https://origin.example.test',
            'CACHEABLE_EXTENSIONS' => 'css',
            'UPSTREAM_VERIFY_TLS' => 'true',
        ];

        list($exitCode, $display) = $this->runCommand([]);

        self::assertSame(0, $exitCode, $display);
        self::assertSame("✓ Environment check passed.\n", $display);
    }

    public function testWithoutAnyConfigurationTheInformationalRunOnlyNotes(): void
    {
        list($exitCode, $display) = $this->runCommand(['--informational' => true]);

        self::assertSame(0, $exitCode);
        self::assertSame(
            "! NOTE: no .env file yet. Copy .env.example to .env, adjust it,\n"
            . "    then run \"composer check-env\" to validate the configuration.\n",
            $display
        );
    }

    public function testWithoutAnyConfigurationTheExplicitRunFails(): void
    {
        list($exitCode, $display) = $this->runCommand([]);

        self::assertSame(1, $exitCode);
        self::assertSame(
            "✗ Environment check failed: you must first create the .env file with the desired configuration options.\n",
            $display
        );
    }

    public function testErrorsAreListed(): void
    {
        $this->directory->write('.env', "SOURCE_ORIGIN=\"origin\"\nUPSTREAM_VERIFY_TLS=true\nCACHE_EXPIRY=\"<soon>\"\n");

        list($exitCode, $display) = $this->runCommand([]);

        self::assertSame(1, $exitCode);
        self::assertSame(
            "✗ Environment check failed:\n"
            . "    • SOURCE_ORIGIN must be a valid URL (got: 'origin')\n"
            . "    • CACHEABLE_EXTENSIONS must be set (comma-separated list of file extensions)\n"
            . "    • CACHE_EXPIRY must be a non-negative integer (got: '<soon>')\n",
            $display
        );
    }

    public function testTheInformationalRunReportsErrorsWithoutFailing(): void
    {
        $this->directory->write('.env', "DEBUG=false\n");

        list($exitCode, $display) = $this->runCommand(['--informational' => true]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString("    • SOURCE_ORIGIN must be set\n", $display);
        self::assertStringEndsWith("    Fix the errors above, then run \"composer check-env\" to validate the configuration.\n", $display);
    }

    public function testAnUnparsableEnvFileIsAnError(): void
    {
        $this->directory->write('.env', "INVALID KEY=value\n");

        list($exitCode, $display) = $this->runCommand([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('    • The .env file cannot be parsed: ', $display);
    }

    public function testWarningsAreIndented(): void
    {
        $this->directory->write('.env', "SOURCE_ORIGIN=\"https://origin.example.test\"\nCACHEABLE_EXTENSIONS=\"css,html\"\nDEBUG=true\n");

        list($exitCode, $display) = $this->runCommand([]);

        self::assertSame(0, $exitCode);
        self::assertSame(
            "! WARNING: CACHEABLE_EXTENSIONS includes session/auth-sensitive types: html.\n"
            . "    If the origin ever serves authenticated content with these extensions,\n"
            . "    the proxy will cache and re-serve it to unrelated clients.\n"
            . "! WARNING: DEBUG is enabled. MUST be disabled in production,\n"
            . "    or PHP warnings will poison asset bodies streamed to clients.\n"
            . "! NOTE: UPSTREAM_VERIFY_TLS is off. The origin TLS certificate\n"
            . "    will NOT be validated. Acceptable on trusted networks only.\n"
            . "✓ Environment check passed.\n",
            $display
        );
    }

    /**
     * @dataProvider provideModes
     */
    #[DataProvider('provideModes')]
    public function testCwebpIsRequiredInNativeModeOnly(string $mode, int $expectedExitCode): void
    {
        $this->directory->write('.env', self::VALID_ENV . 'IMAGE_CONVERSION_MODE="' . $mode . "\"\nWEBP_API_SERVER=\"https://api.example.test\"\nWEBP_API_KEY=\"key\"\n");
        $this->cwebpInstalled = false;

        list($exitCode, $display) = $this->runCommand([]);

        self::assertSame($expectedExitCode, $exitCode, $display);
        if ($expectedExitCode === 1) {
            self::assertStringContainsString('    • cwebp binary not found in PATH (required when IMAGE_CONVERSION_MODE = "native")', $display);
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function provideModes(): array
    {
        return [
            'native' => ['native', 1],
            'api' => ['api', 0],
            'none' => ['none', 0],
        ];
    }

    public function testTheCurlExtensionIsRequired(): void
    {
        $this->directory->write('.env', self::VALID_ENV);
        $this->curlLoaded = false;

        list($exitCode, $display) = $this->runCommand([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('    • cURL extension is not installed', $display);
    }

    public function testTheDefaultHostChecksRun(): void
    {
        $this->directory->write('.env', self::VALID_ENV);
        $command = new CheckEnvCommand(new ConfigLoader($this->directory->path('.env'), null, $this->environmentReader()));

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(extension_loaded('curl') ? 0 : 1, $tester->getStatusCode(), $tester->getDisplay());
    }

    private function command(): CheckEnvCommand
    {
        return new CheckEnvCommand(
            new ConfigLoader($this->directory->path('.env'), null, $this->environmentReader()),
            null,
            function (string $binary): bool {
                return $binary === 'cwebp' && $this->cwebpInstalled;
            },
            function (string $extension): bool {
                return $extension === 'curl' && $this->curlLoaded;
            }
        );
    }

    /**
     * @return callable(string): ?string
     */
    private function environmentReader(): callable
    {
        return function (string $name): ?string {
            return $this->environment[$name] ?? null;
        };
    }

    /**
     * @param array<string, bool> $input
     *
     * @return array{int, string}
     */
    private function runCommand(array $input): array
    {
        $tester = new CommandTester($this->command());
        $exitCode = $tester->execute($input);

        return [$exitCode, $tester->getDisplay()];
    }
}
