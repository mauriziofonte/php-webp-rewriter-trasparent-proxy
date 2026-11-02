<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Console;

use Mfonte\ProxyCdn\Config\ConfigLoader;
use Mfonte\ProxyCdn\Console\CheckEnvCommand;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
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
    private const VALID_ENV = "SOURCE_ORIGIN=\"https://origin.example.test\"\nPROXY_HOSTS=\"cdn.example.test\"\nCACHEABLE_EXTENSIONS=\"css,js,png\"\nUPSTREAM_VERIFY_TLS=true\n";

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var array<string, string>
     */
    private $environment = [];

    /**
     * The binaries in PATH.
     *
     * @var list<string>
     */
    private $binaries = [];

    /**
     * @var bool
     */
    private $posixLoaded = true;

    /**
     * @var bool
     */
    private $curlLoaded = true;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->environment = [];
        $this->binaries = [];
        $this->posixLoaded = true;
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
            'PROXY_HOSTS' => 'cdn.example.test',
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
            . "    • PROXY_HOSTS must be set: the host names the proxy answers for, separated by commas (\"cdn.example.com\", or \"cdn.example.com=https://origin.example.com\" for another origin than SOURCE_ORIGIN)\n"
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
        $this->directory->write('.env', "SOURCE_ORIGIN=\"https://origin.example.test\"\nPROXY_HOSTS=\"cdn.example.test\"\nCACHEABLE_EXTENSIONS=\"css,html\"\nDEBUG=true\n");

        list($exitCode, $display) = $this->runCommand([]);

        self::assertSame(0, $exitCode);
        self::assertSame(
            "! WARNING: CACHEABLE_EXTENSIONS includes session/auth-sensitive types: html.\n"
            . "    If the origin ever serves authenticated content with these extensions,\n"
            . "    the proxy will cache and re-serve it to unrelated clients.\n"
            . "! WARNING: DEBUG is enabled. MUST be disabled in production,\n"
            . "    or PHP warnings will poison asset bodies streamed to clients.\n"
            . "! NOTE: UPSTREAM_VERIFY_TLS is not set, so the origin TLS certificate\n"
            . "    is verified (the default since 3.0; 2.x did not verify). An origin\n"
            . "    with a self-signed or mismatched certificate now answers 503: fix\n"
            . "    the certificate, or set UPSTREAM_VERIFY_TLS=false on trusted\n"
            . "    networks only.\n"
            . "✓ Environment check passed.\n",
            $display
        );
    }

    public function testLocalConversionWithoutBackendOrPosixIsWarnedAbout(): void
    {
        $this->directory->write('.env', self::VALID_ENV . "IMAGE_CONVERTER=\"local\"\n");
        $this->posixLoaded = false;

        list($exitCode, $display) = $this->runCommand([]);

        self::assertSame(0, $exitCode, $display);
        self::assertStringContainsString('! WARNING: local image conversion has no backend', $display);
        self::assertStringContainsString('! WARNING: the posix extension is not loaded', $display);
    }

    public function testLocalConversionWithABackendIsNotWarnedAbout(): void
    {
        $this->directory->write('.env', self::VALID_ENV . "IMAGE_CONVERTER=\"local\"\n");
        $this->binaries = ['avifenc'];

        list($exitCode, $display) = $this->runCommand([]);

        self::assertSame(0, $exitCode, $display);
        self::assertStringNotContainsString('image conversion', $display);
        self::assertStringNotContainsString('posix', $display);
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

    public function testTheWebServerConfigurationsMustSendCacheExpiryOnHits(): void
    {
        $this->directory->write('.env', self::VALID_ENV . "CACHE_EXPIRY=86400\n");
        $this->directory->write('public/.htaccess', "    Header set Cache-Control \"public, max-age=31536000, must-revalidate\" env=PCDN_HIT\n");
        $this->directory->write('config/nginx/proxy-cdn-headers.conf', "add_header Cache-Control \"public, max-age=86400, must-revalidate\" always;\n");

        list($exitCode, $display) = $this->runCommand([]);

        self::assertSame(0, $exitCode, 'a warning');
        self::assertSame(
            "! WARNING: CACHE_EXPIRY is 86400, but public/.htaccess sends max-age=31536000\n"
            . "    on the hits the web server serves. Set the same value in both.\n"
            . "✓ Environment check passed.\n",
            $display
        );
    }

    public function testTheShippedConfigurationsSendTheCacheExpiryOfEnvExample(): void
    {
        $root = dirname(__DIR__, 3);
        $env = (string) file_get_contents($root . '/.env.example');
        self::assertSame(1, preg_match('/^CACHE_EXPIRY\s*=\s*([0-9]+)$/m', $env, $match));
        foreach (CheckEnvCommand::WEB_SERVER_CONFIGS as $config) {
            self::assertSame((int) $match[1], CheckEnvCommand::hitMaxAge($root . '/' . $config), $config);
        }
    }

    private function command(): CheckEnvCommand
    {
        return new CheckEnvCommand(
            new ConfigLoader($this->directory->path('.env'), null, $this->environmentReader()),
            null,
            function (string $binary): bool {
                return in_array($binary, $this->binaries, true);
            },
            function (string $extension): bool {
                return ($extension === 'curl' && $this->curlLoaded) || ($extension === 'posix' && $this->posixLoaded);
            },
            $this->directory->path()
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
