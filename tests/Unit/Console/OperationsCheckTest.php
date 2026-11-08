<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Console;

use Mfonte\ProxyCdn\Config\ConfigLoader;
use Mfonte\ProxyCdn\Config\DeprecatedKeys;
use Mfonte\ProxyCdn\Console\CheckEnvCommand;
use Mfonte\ProxyCdn\Console\OperationsCheck;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The checks check-env adds for operations (PRX-9): the lock directories,
 * the log file, the deprecated keys and the 2.x leftovers.
 *
 * @covers \Mfonte\ProxyCdn\Console\OperationsCheck
 * @covers \Mfonte\ProxyCdn\Config\DeprecatedKeys
 * @covers \Mfonte\ProxyCdn\Console\CheckEnvCommand
 */
#[CoversClass(OperationsCheck::class)]
#[CoversClass(DeprecatedKeys::class)]
#[CoversClass(CheckEnvCommand::class)]
final class OperationsCheckTest extends TestCase
{
    private const VALID_ENV = "SOURCE_ORIGIN=\"https://origin.example.test\"\nPROXY_HOSTS=\"cdn.example.test\"\nCACHEABLE_EXTENSIONS=\"css,js\"\nUPSTREAM_VERIFY_TLS=true\n";

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        chmod($this->directory->path(), 0755);
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testACleanInstallationHasNoWarning(): void
    {
        $this->directory->write('public/index.php', '<?php');
        $this->directory->write('public/.htaccess', '');
        mkdir($this->directory->path('public/_cache'));

        self::assertSame("✓ Environment check passed.\n", $this->check(self::VALID_ENV . "MISS_RATE_LIMIT=60\nTRUSTED_PROXIES=10.0.0.1\n"));
    }

    /**
     * Today a refused var/locks only turns request coalescing off in the
     * log; check-env reports it, and creates nothing.
     */
    public function testARefusedLockDirectoryIsReported(): void
    {
        mkdir($this->directory->path('var/locks'), 0700, true);
        chmod($this->directory->path('var/locks'), 0777);

        $display = $this->check(self::VALID_ENV);

        self::assertStringContainsString('! WARNING: var/locks is refused as a lock directory, so requests for the same miss are not coalesced:', $display);
        self::assertStringNotContainsString('var/metrics', $display);
        self::assertStringContainsString('✓ Environment check passed.', $display);
    }

    /**
     * A directory that does not exist yet is judged by the directories it
     * would be created in, and is left uncreated.
     */
    public function testAMissingLockDirectoryIsJudgedByItsParents(): void
    {
        chmod($this->directory->path(), 0777);

        $display = $this->check(self::VALID_ENV . "MISS_RATE_LIMIT=60\n");

        self::assertStringContainsString('var/locks is refused as a lock directory', $display);
        self::assertStringContainsString('var/metrics is refused as a lock directory, so no metrics are recorded', $display);
        self::assertStringContainsString('var/ratelimit is refused as a lock directory, so MISS_RATE_LIMIT lets every miss through', $display);
        self::assertStringContainsString('is writable by its group or by other users (mode 0777)', $display);
        self::assertDirectoryDoesNotExist($this->directory->path('var'));
        self::assertNotNull(OperationsCheck::lockDirectoryProblem($this->directory->path('var/locks')));
    }

    public function testAnAcceptedMissingDirectoryIsNotCreated(): void
    {
        self::assertNull(OperationsCheck::lockDirectoryProblem($this->directory->path('var/locks')));
        self::assertDirectoryDoesNotExist($this->directory->path('var'));
    }

    public function testAnUnwritableLogFileIsReported(): void
    {
        $this->directory->write('not-a-directory', '');

        $display = $this->check(self::VALID_ENV . 'LOG_FILE=' . $this->directory->path('not-a-directory/proxy.log') . "\n");

        self::assertStringContainsString('! WARNING: the log file ' . $this->directory->path('not-a-directory/proxy.log') . ' cannot be written', $display);
        self::assertStringNotContainsString('the log file', $this->check(self::VALID_ENV . "LOG_FILE=stderr\n"));
    }

    public function testTheLeftoversOf2xAreReported(): void
    {
        $this->directory->write('.cached.env.php', '<?php return [];');
        $this->directory->write('public/index.php', '<?php');
        $this->directory->write('public/images/hero.jpg', 'x');
        $this->directory->write('public/_qs/0a/app.css', 'x');

        $display = $this->check(self::VALID_ENV);

        self::assertStringContainsString('! WARNING: .cached.env.php of 2.x is still in the project root.', $display);
        self::assertStringContainsString("! WARNING: public/ holds 2 entries that 3.x does not serve (_qs, images),\n", $display);
        self::assertStringContainsString('cache:migrate-2x', $display);
    }

    /**
     * UPSTREAM_VERIFY_TLS=false is reported (ConfigValidator) but passes.
     */
    public function testTlsVerificationOffIsReported(): void
    {
        $display = $this->check(str_replace('UPSTREAM_VERIFY_TLS=true', 'UPSTREAM_VERIFY_TLS=false', self::VALID_ENV));

        self::assertStringContainsString('! NOTE: UPSTREAM_VERIFY_TLS is off.', $display);
        self::assertStringContainsString('✓ Environment check passed.', $display);
    }

    /**
     * A key of DeprecatedKeys that is still set, in .env or in the
     * environment, is reported with its advice.
     */
    public function testDeprecatedKeysAreReported(): void
    {
        $this->directory->write('.env', self::VALID_ENV . "OLD_KEY=1\nEMPTY_KEY=\n");
        $loader = new ConfigLoader($this->directory->path('.env'), null, static function (string $name): ?string {
            return $name === 'ENV_KEY' ? 'yes' : null;
        });

        $warnings = DeprecatedKeys::warnings($loader, ['OLD_KEY' => 'Use NEW_KEY.', 'ENV_KEY' => 'Remove it.', 'EMPTY_KEY' => 'x', 'UNSET_KEY' => 'y']);

        self::assertSame([
            "WARNING: OLD_KEY is deprecated: it was removed in 3.0 and is ignored.\nUse NEW_KEY.",
            "WARNING: ENV_KEY is deprecated: it was removed in 3.0 and is ignored.\nRemove it.",
        ], $warnings);
        self::assertSame([], DeprecatedKeys::warnings($loader));
    }

    /**
     * Each 2.x key of ConfigLoader::REMOVED_KEYS has the advice of
     * DeprecatedKeys, which names its replacement, a key that is read.
     */
    public function testTheDeprecatedKeysAreTheRemoved2xKeys(): void
    {
        foreach (ConfigLoader::REMOVED_KEYS as $key => $replacement) {
            self::assertStringStartsWith('Use ' . $replacement . ' instead', DeprecatedKeys::KEYS[$key]);
            self::assertContains($replacement, ConfigLoader::KEYS);
        }
    }

    private function check(string $env): string
    {
        $this->directory->write('.env', $env);
        $command = new CheckEnvCommand(
            new ConfigLoader($this->directory->path('.env'), null, static function (string $name): ?string {
                return null;
            }),
            null,
            static function (string $binary): bool {
                return true;
            },
            static function (string $extension): bool {
                return true;
            },
            $this->directory->path()
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester->getDisplay();
    }
}
