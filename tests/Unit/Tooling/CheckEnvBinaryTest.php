<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The Composer install hooks run "bin/proxy-cdn check-env --informational",
 * so that "composer install" never fails because the application is not
 * configured yet (finding P-20), while "composer check-env" keeps failing on
 * every configuration error.
 *
 * The binary is copied into a temporary project and run in a clean
 * environment, so that a developer's .env or exported variables never
 * influence the result.
 *
 * @coversNothing
 */
#[CoversNothing]
final class CheckEnvBinaryTest extends TestCase
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

        self::assertTrue(mkdir($this->project . '/bin', 0777, true));
        self::assertTrue(mkdir($this->project . '/vendor'));
        self::assertTrue(copy($root . '/bin/proxy-cdn', $this->project . '/bin/proxy-cdn'));
        self::assertNotFalse(file_put_contents(
            $this->project . '/vendor/autoload.php',
            "<?php\n\nreturn require " . var_export($root . '/vendor/autoload.php', true) . ";\n"
        ));
    }

    protected function tear_down(): void
    {
        foreach (['/bin/proxy-cdn', '/vendor/autoload.php', '/.env'] as $file) {
            if (is_file($this->project . $file)) {
                unlink($this->project . $file);
            }
        }
        foreach (['/bin', '/vendor', ''] as $directory) {
            if (is_dir($this->project . $directory)) {
                rmdir($this->project . $directory);
            }
        }

        parent::tear_down();
    }

    public function testInformationalRunSucceedsWithoutEnvFile(): void
    {
        list($exitCode, $output) = $this->runBinary(['--informational']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('no .env file yet', $output);
        self::assertStringContainsString('composer check-env', $output);
    }

    public function testExplicitRunFailsWithoutEnvFile(): void
    {
        list($exitCode, $output) = $this->runBinary([]);

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
                "SOURCE_ORIGIN=\"https://origin.example.test\"\nPROXY_HOSTS=\"cdn.example.test\"\nCACHEABLE_EXTENSIONS=\"png\"\nUPSTREAM_TIMEOUT=\"soon\"\n",
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

        list($exitCode, $output) = $this->runBinary(['--informational']);

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

        list($exitCode, $output) = $this->runBinary([]);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString($expectedError, $output);
    }

    public function testExplicitRunPassesOnValidConfiguration(): void
    {
        $this->writeEnv(
            "SOURCE_ORIGIN=\"https://origin.example.test\"\nPROXY_HOSTS=\"cdn.example.test\"\n"
            . "CACHEABLE_EXTENSIONS=\"css,js,png\"\n"
            . "IMAGE_CONVERSION_MODE=\"none\"\n"
            . "UPSTREAM_VERIFY_TLS=true\n"
        );

        list($exitCode, $output) = $this->runBinary([]);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Environment check passed', $output);
    }

    /**
     * The environment is read first, and is enough without .env.
     */
    public function testTheEnvironmentAloneIsAValidConfiguration(): void
    {
        list($exitCode, $output) = $this->runBinary([], [
            'SOURCE_ORIGIN' => 'https://origin.example.test',
            'PROXY_HOSTS' => 'cdn.example.test',
            'CACHEABLE_EXTENSIONS' => 'css',
            'UPSTREAM_VERIFY_TLS' => 'true',
        ]);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Environment check passed', $output);
    }

    public function testTheEnvironmentWinsOverTheEnvFile(): void
    {
        $this->writeEnv("SOURCE_ORIGIN=\"not a url\"\nCACHEABLE_EXTENSIONS=\"css\"\nUPSTREAM_VERIFY_TLS=true\n");

        list($exitCode, $output) = $this->runBinary([], ['SOURCE_ORIGIN' => 'https://origin.example.test', 'PROXY_HOSTS' => 'cdn.example.test']);

        self::assertSame(0, $exitCode, $output);
    }

    /**
     * @return array<string, array{list<string>, int}>
     */
    public static function provideRunsWithoutDependencies(): array
    {
        return [
            'informational' => [['--informational'], 0],
            'explicit' => [[], 1],
        ];
    }

    /**
     * @dataProvider provideRunsWithoutDependencies
     *
     * @param list<string> $arguments
     */
    #[DataProvider('provideRunsWithoutDependencies')]
    public function testMissingDependenciesAreReported(array $arguments, int $expectedExitCode): void
    {
        self::assertTrue(unlink($this->project . '/vendor/autoload.php'));

        list($exitCode, $output) = $this->runBinary($arguments);

        self::assertSame($expectedExitCode, $exitCode, $output);
        self::assertStringContainsString('Dependencies are missing. Run: composer install', $output);
    }

    /**
     * Composer's platform check throws when vendor/ was resolved for another
     * PHP version; the install hooks must still succeed.
     *
     * @dataProvider provideRunsWithoutDependencies
     *
     * @param list<string> $arguments
     */
    #[DataProvider('provideRunsWithoutDependencies')]
    public function testUnloadableDependenciesAreReported(array $arguments, int $expectedExitCode): void
    {
        self::assertNotFalse(file_put_contents(
            $this->project . '/vendor/autoload.php',
            "<?php\n\nthrow new \\RuntimeException('Composer detected issues in your platform: PHP too old');\n"
        ));

        list($exitCode, $output) = $this->runBinary($arguments);

        self::assertSame($expectedExitCode, $exitCode, $output);
        self::assertStringContainsString('The dependencies cannot be loaded: Composer detected issues in your platform: PHP too old', $output);
    }

    private function writeEnv(string $contents): void
    {
        self::assertNotFalse(file_put_contents($this->project . '/.env', $contents));
    }

    /**
     * Runs "bin/proxy-cdn check-env" of the temporary project with
     * PHP_BINARY in an environment that only carries PATH and $environment,
     * and returns its exit code and combined output.
     *
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     *
     * @return array{int, string}
     */
    private function runBinary(array $arguments, array $environment = []): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->project . '/bin/proxy-cdn') . ' check-env';
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $path = getenv('PATH');
        $process = proc_open(
            $command . ' 2>&1',
            [1 => ['pipe', 'w']],
            $pipes,
            $this->project,
            array_merge(['PATH' => is_string($path) ? $path : '/usr/bin:/bin'], $environment)
        );
        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        return [$exitCode, is_string($output) ? $output : ''];
    }
}
