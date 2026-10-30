<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The Composer install hooks run scripts/check-env.php with --informational,
 * so that "composer install" never fails because the application is not
 * configured yet (finding P-20), while "composer check-env" keeps failing on
 * every configuration error.
 *
 * The script is copied into a temporary project and run in a clean
 * environment, so that a developer's .env or exported variables never
 * influence the result.
 *
 * @coversNothing
 */
#[CoversNothing]
final class CheckEnvScriptTest extends TestCase
{
    /**
     * @var string
     */
    private $project;

    protected function set_up(): void
    {
        parent::set_up();

        $root = dirname(__DIR__, 3);
        $this->project = sys_get_temp_dir() . '/proxy-cdn-check-env-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($this->project . '/scripts', 0777, true));
        self::assertTrue(mkdir($this->project . '/vendor'));
        self::assertTrue(copy($root . '/scripts/check-env.php', $this->project . '/scripts/check-env.php'));
        self::assertNotFalse(file_put_contents(
            $this->project . '/vendor/autoload.php',
            "<?php\n\nrequire " . var_export($root . '/vendor/autoload.php', true) . ";\n"
        ));
    }

    protected function tear_down(): void
    {
        foreach (['/scripts/check-env.php', '/vendor/autoload.php', '/.env'] as $file) {
            if (is_file($this->project . $file)) {
                unlink($this->project . $file);
            }
        }
        foreach (['/scripts', '/vendor', ''] as $directory) {
            if (is_dir($this->project . $directory)) {
                rmdir($this->project . $directory);
            }
        }

        parent::tear_down();
    }

    public function testInformationalRunSucceedsWithoutEnvFile(): void
    {
        list($exitCode, $output) = $this->runScript(['--informational']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('no .env file yet', $output);
        self::assertStringContainsString('composer check-env', $output);
    }

    public function testExplicitRunFailsWithoutEnvFile(): void
    {
        list($exitCode, $output) = $this->runScript([]);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('Environment check failed', $output);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideInvalidConfigurations(): array
    {
        return [
            'required values missing' => ["DEBUG=false\n", 'SOURCE_ORIGIN must be set'],
            'unparsable file' => ["INVALID KEY=value\n", 'The .env file cannot be parsed'],
            'invalid value' => [
                "SOURCE_ORIGIN=\"https://origin.example.test\"\nCACHEABLE_EXTENSIONS=\"png\"\nUPSTREAM_TIMEOUT=\"soon\"\n",
                'UPSTREAM_TIMEOUT must be a non-negative integer',
            ],
        ];
    }

    /**
     * @dataProvider provideInvalidConfigurations
     */
    #[DataProvider('provideInvalidConfigurations')]
    public function testInformationalRunReportsErrorsWithoutFailing(string $env, string $expectedError): void
    {
        $this->writeEnv($env);

        list($exitCode, $output) = $this->runScript(['--informational']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString($expectedError, $output);
    }

    /**
     * @dataProvider provideInvalidConfigurations
     */
    #[DataProvider('provideInvalidConfigurations')]
    public function testExplicitRunFailsOnErrors(string $env, string $expectedError): void
    {
        $this->writeEnv($env);

        list($exitCode, $output) = $this->runScript([]);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString($expectedError, $output);
    }

    public function testExplicitRunPassesOnValidConfiguration(): void
    {
        $this->writeEnv(
            "SOURCE_ORIGIN=\"https://origin.example.test\"\n"
            . "CACHEABLE_EXTENSIONS=\"css,js,png\"\n"
            . "IMAGE_CONVERSION_MODE=\"none\"\n"
            . "UPSTREAM_VERIFY_TLS=true\n"
        );

        list($exitCode, $output) = $this->runScript([]);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Environment check passed', $output);
    }

    private function writeEnv(string $contents): void
    {
        self::assertNotFalse(file_put_contents($this->project . '/.env', $contents));
    }

    /**
     * Runs the copied script with PHP_BINARY in an environment that only
     * carries PATH, and returns its exit code and combined output.
     *
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private function runScript(array $arguments): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->project . '/scripts/check-env.php');
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $path = getenv('PATH');
        $process = proc_open(
            $command . ' 2>&1',
            [1 => ['pipe', 'w']],
            $pipes,
            $this->project,
            ['PATH' => is_string($path) ? $path : '/usr/bin:/bin']
        );
        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        return [$exitCode, is_string($output) ? $output : ''];
    }
}
