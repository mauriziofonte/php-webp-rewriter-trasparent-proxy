<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Invariants of composer.json and of the tool manifests under tools/ that the
 * plan fixes (section 4 and conventions): package identity, supported PHP and
 * dependency ranges, test tooling only in require-dev, Composer scripts.
 *
 * @coversNothing
 */
#[CoversNothing]
final class ComposerManifestTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testPackageIdentity(): void
    {
        $manifest = self::readManifest('composer.json');

        self::assertSame('mfonte/proxy-cdn', $manifest['name'] ?? null);
        self::assertSame('project', $manifest['type'] ?? null);
        self::assertSame('BSD-3-Clause', $manifest['license'] ?? null);
        self::assertSame(
            [['name' => 'Maurizio Fonte', 'homepage' => 'https://github.com/mauriziofonte']],
            $manifest['authors'] ?? null
        );

        $description = $manifest['description'] ?? null;
        self::assertIsString($description);
        self::assertNotSame('', trim($description));
        self::assertStringNotContainsString("\n", $description);
    }

    public function testRuntimeRequirementsAreExactlyThePlannedOnes(): void
    {
        $require = self::section(self::readManifest('composer.json'), 'require');

        // The conversion library is required from its GitHub repository (the
        // vcs entry of composer.json), at its released 1.x line (plan
        // section 9).
        self::assertSame('^1.0', $require['mfonte/secure-image-converter'] ?? null);
        unset($require['mfonte/secure-image-converter']);

        // Any further runtime dependency needs explicit approval (plan section 4).
        // Floors are security floors: the first release of each line that
        // fixes every published advisory (see the PRX-1 commit message).
        // The console and phpdotenv floors are the first releases that load
        // without deprecations on PHP 8.4 and later (implicitly nullable
        // parameters), so that bin/proxy-cdn and the install hooks print none.
        // Every extension the code calls is declared, except the optional
        // ones listed in "suggest".
        self::assertSame(
            [
                'php' => '^7.2.5 || ^8.0',
                'ext-filter' => '*',
                'ext-hash' => '*',
                'ext-json' => '*',
                'psr/log' => '^1.1 || ^2.0 || ^3.0',
                'symfony/console' => '^5.4.35 || ^6.4.3 || ^7.0.3 || ^8.0',
                'symfony/http-foundation' => '^5.4.56 || ^6.4.50 || ^7.4.23 || ^8.1.11',
                'vlucas/phpdotenv' => '^5.6.1',
            ],
            $require
        );
    }

    /**
     * symfony/string comes with symfony/console, whose 5.4 releases accept
     * it from 5.1: the conflict raises its floor to the first release of
     * each line without PHP 8.4 deprecations, without adding a dependency.
     */
    public function testTransitiveFloorsAreConflictsNotRequirements(): void
    {
        $manifest = self::readManifest('composer.json');

        self::assertSame(
            ['symfony/string' => '<5.4.35 || >=6.0,<6.4.3 || >=7.0,<7.0.3'],
            self::section($manifest, 'conflict')
        );
        self::assertArrayNotHasKey('symfony/string', self::section($manifest, 'require'));
    }

    /**
     * The library is not on Packagist: Composer reads it from its GitHub
     * repository.
     */
    public function testConversionLibraryRepositoryIsDeclared(): void
    {
        self::assertSame(
            [['type' => 'vcs', 'url' => 'https://github.com/mauriziofonte/php-secure-image-converter']],
            self::readManifest('composer.json')['repositories'] ?? null
        );
    }

    /**
     * ext-ctype is not declared, so the code must not call it: digits are
     * checked with preg_match(), which PCRE always provides.
     */
    public function testTheCodeDoesNotCallUndeclaredCtype(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/src', \FilesystemIterator::SKIP_DOTS));
        $offenders = [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php'
                && preg_match('/\bctype_[a-z]+\s*\(/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $file->getFilename();
            }
        }

        self::assertSame([], $offenders);
        self::assertArrayNotHasKey('ext-ctype', self::section(self::readManifest('composer.json'), 'require'));
    }

    public function testCurlIsSuggestedNotRequired(): void
    {
        $manifest = self::readManifest('composer.json');

        self::assertArrayNotHasKey('ext-curl', self::section($manifest, 'require'));
        self::assertArrayHasKey('ext-curl', self::section($manifest, 'suggest'));
    }

    public function testRequireDevHoldsOnlyTheTestTooling(): void
    {
        $manifest = self::readManifest('composer.json');
        $requireDev = self::section($manifest, 'require-dev');

        self::assertSame(['phpunit/phpunit', 'yoast/phpunit-polyfills'], array_keys($requireDev));

        // PHPUnit is capped at 11, one branch per PHP generation; the floors
        // are the releases that fix CVE-2026-24765.
        self::assertSame('^8.5.52 || ^9.6.33 || ^10.5.62 || ^11.5.50', $requireDev['phpunit/phpunit']);
    }

    public function testAutoloadMapsTheProjectNamespaces(): void
    {
        $manifest = self::readManifest('composer.json');

        self::assertSame(['Mfonte\\ProxyCdn\\' => 'src/'], self::section(self::section($manifest, 'autoload'), 'psr-4'));
        self::assertSame(['Mfonte\\ProxyCdn\\Tests\\' => 'tests/'], self::section(self::section($manifest, 'autoload-dev'), 'psr-4'));
    }

    public function testResolutionSettings(): void
    {
        $manifest = self::readManifest('composer.json');
        $config = self::section($manifest, 'config');

        self::assertSame('stable', $manifest['minimum-stability'] ?? null);
        self::assertTrue($manifest['prefer-stable'] ?? null);
        self::assertTrue($config['sort-packages'] ?? null);
        self::assertTrue($config['optimize-autoloader'] ?? null);
        // Each CI job resolves for its own PHP version.
        self::assertArrayNotHasKey('platform', $config);
    }

    public function testQualityScriptsHaveTheSharedNames(): void
    {
        $scripts = self::section(self::readManifest('composer.json'), 'scripts');

        foreach (['test', 'analyse', 'cs:check', 'cs:fix', 'taint', 'ci', 'check-env'] as $name) {
            self::assertArrayHasKey($name, $scripts, sprintf('Missing Composer script "%s"', $name));
        }
        self::assertSame(['@cs:check', '@analyse', '@test'], $scripts['ci']);

        $analyse = implode("\n", self::commands($scripts, 'analyse'));
        self::assertStringContainsString('--configuration=phpstan.neon.dist', $analyse);
        self::assertStringContainsString('--configuration=phpstan-php85.neon.dist', $analyse);
        self::assertStringContainsString('--taint-analysis', implode("\n", self::commands($scripts, 'taint')));
    }

    /**
     * Scripts that need a tool from tools/ first check that it is installed.
     *
     * @return array<string, array{string, string}>
     */
    public static function provideToolScripts(): array
    {
        return [
            'analyse' => ['analyse', 'phpstan'],
            'cs:check' => ['cs:check', 'php-cs-fixer'],
            'cs:fix' => ['cs:fix', 'php-cs-fixer'],
            'taint' => ['taint', 'psalm'],
        ];
    }

    /**
     * @dataProvider provideToolScripts
     */
    #[DataProvider('provideToolScripts')]
    public function testToolScriptsCheckThatTheToolIsInstalled(string $script, string $tool): void
    {
        $commands = self::commands(self::section(self::readManifest('composer.json'), 'scripts'), $script);

        self::assertSame('@php tools/require-tool.php ' . $tool, $commands[0]);
        self::assertGreaterThan(1, count($commands));
        foreach (array_slice($commands, 1) as $command) {
            self::assertStringStartsWith('@php tools/' . $tool . '/vendor/bin/', $command);
        }
    }

    /**
     * composer install must succeed before any .env exists (finding P-20).
     *
     * @return array<string, array{string}>
     */
    public static function provideInstallHooks(): array
    {
        return [
            'post-install-cmd' => ['post-install-cmd'],
            'post-update-cmd' => ['post-update-cmd'],
        ];
    }

    /**
     * @dataProvider provideInstallHooks
     */
    #[DataProvider('provideInstallHooks')]
    public function testInstallHooksOnlyReportConfigurationProblems(string $hook): void
    {
        $scripts = self::section(self::readManifest('composer.json'), 'scripts');

        foreach (self::commands($scripts, $hook) as $command) {
            self::assertNotSame('@check-env', $command, 'The strict check-env would fail the install');
            if (strpos($command, 'check-env') !== false) {
                self::assertStringContainsString('--informational', $command);
            }
        }
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function provideToolManifests(): array
    {
        return [
            // The deprecation rules make the PHP 8.5 run report curl_close() and the like.
            'phpstan' => ['phpstan', ['phpstan/phpstan', 'phpstan/phpstan-deprecation-rules', 'phpstan/phpstan-phpunit']],
            'php-cs-fixer' => ['php-cs-fixer', ['friendsofphp/php-cs-fixer']],
            'psalm' => ['psalm', ['vimeo/psalm']],
        ];
    }

    /**
     * @dataProvider provideToolManifests
     *
     * @param list<string> $packages
     */
    #[DataProvider('provideToolManifests')]
    public function testToolsAreInstalledApartFromTheProject(string $tool, array $packages): void
    {
        $manifest = self::readManifest('tools/' . $tool . '/composer.json');
        $requireDev = self::section(self::readManifest('composer.json'), 'require-dev');

        self::assertArrayNotHasKey('platform', self::section($manifest, 'config'));
        foreach ($packages as $package) {
            self::assertArrayHasKey($package, self::section($manifest, 'require'));
            self::assertArrayNotHasKey($package, $requireDev);
        }
    }

    /**
     * @return array<mixed>
     */
    private static function readManifest(string $relativePath): array
    {
        $path = self::ROOT . '/' . $relativePath;
        self::assertFileExists($path);

        $contents = file_get_contents($path);
        self::assertIsString($contents);

        $manifest = json_decode($contents, true);
        self::assertIsArray($manifest, sprintf('%s is not valid JSON', $relativePath));

        return $manifest;
    }

    /**
     * Returns the commands of a Composer script, which may be a string or a list.
     *
     * @param array<mixed> $scripts
     *
     * @return list<string>
     */
    private static function commands(array $scripts, string $name): array
    {
        self::assertArrayHasKey($name, $scripts);

        $commands = [];
        foreach ((array) $scripts[$name] as $command) {
            self::assertIsString($command);
            $commands[] = $command;
        }

        return $commands;
    }

    /**
     * @param array<mixed> $manifest
     *
     * @return array<mixed>
     */
    private static function section(array $manifest, string $key): array
    {
        $section = $manifest[$key] ?? [];
        self::assertIsArray($section, sprintf('"%s" must be an object', $key));

        return $section;
    }
}
