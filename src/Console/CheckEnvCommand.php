<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Console;

use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Config\ConfigLoader;
use Mfonte\ProxyCdn\Config\ConfigValidation;
use Mfonte\ProxyCdn\Config\ConfigValidator;
use Mfonte\ProxyCdn\Config\DeprecatedKeys;
use Mfonte\ProxyCdn\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * "check-env": validates the configuration with the rules the proxy applies
 * at boot (ConfigValidator), plus the host requirements: the curl extension
 * and, in local conversion mode, a conversion backend and ext-posix (as
 * warnings); it warns about the 2.x keys that were removed
 * (ConfigLoader::REMOVED_KEYS). Prints the 2.x messages and exits with 1
 * on any error.
 *
 * It also warns about the keys of DeprecatedKeys that are still set and,
 * with a project root, about the state directories, the log file and the
 * 2.x leftovers of the installation (OperationsCheck).
 *
 * With --informational, used by the Composer install hooks, it always exits
 * with 0, so that installing the dependencies never fails because the proxy
 * is not configured yet (finding P-20).
 */
final class CheckEnvCommand extends Command
{
    public const NAME = 'check-env';

    /**
     * The web server configurations of the installation, relative to its
     * root, that send the Cache-Control of the hits they serve: its
     * max-age is written there, and must equal CACHE_EXPIRY.
     */
    public const WEB_SERVER_CONFIGS = ['public/.htaccess', 'config/nginx/proxy-cdn-headers.conf'];

    /**
     * The PHP extensions and the binaries of the conversion backends of the
     * library, one of which local conversion needs.
     */
    private const LOCAL_BACKEND_EXTENSIONS = ['gd', 'imagick'];

    private const LOCAL_BACKEND_BINARIES = ['cwebp', 'avifenc', 'vips'];

    /**
     * @var ConfigLoader
     */
    private $loader;

    /**
     * @var ConfigValidator
     */
    private $validator;

    /**
     * @var callable(string): bool
     */
    private $binaryExists;

    /**
     * @var callable(string): bool
     */
    private $extensionLoaded;

    /**
     * @var string|null
     */
    private $projectRoot;

    /**
     * @param ConfigLoader                  $loader          reads the environment and the .env file of the installation
     * @param (callable(string): bool)|null $binaryExists    whether a binary is in PATH; "command -v" by default
     * @param (callable(string): bool)|null $extensionLoaded whether a PHP extension is loaded; extension_loaded() by default
     * @param string|null                   $projectRoot     the installation, whose web server configurations
     *                                                       (WEB_SERVER_CONFIGS) must send the CACHE_EXPIRY of PHP
     *                                                       on hits; null to skip that check
     */
    public function __construct(
        ConfigLoader $loader,
        ?ConfigValidator $validator = null,
        ?callable $binaryExists = null,
        ?callable $extensionLoaded = null,
        ?string $projectRoot = null
    ) {
        $this->loader = $loader;
        $this->projectRoot = $projectRoot === null ? null : rtrim($projectRoot, '/');
        $this->validator = $validator ?? new ConfigValidator();
        $this->binaryExists = $binaryExists ?? static function (string $binary): bool {
            return self::commandExists($binary);
        };
        $this->extensionLoaded = $extensionLoaded ?? static function (string $extension): bool {
            return extension_loaded($extension);
        };
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Validate the configuration (environment and .env) and the host requirements')
            ->addOption(
                'informational',
                null,
                InputOption::VALUE_NONE,
                'Report the problems but always exit with 0 (used by the Composer install hooks)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $informational = $input->getOption('informational') === true;

        if (!$this->loader->hasEnvFile() && $this->loader->readEnvironmentValues() === []) {
            if ($informational) {
                $output->writeln('<fg=yellow>!</> NOTE: no .env file yet. Copy .env.example to .env, adjust it,');
                $output->writeln('    then run "composer check-env" to validate the configuration.');

                return 0;
            }
            $output->writeln('<fg=red>✗ Environment check failed: you must first create the .env file with the desired configuration options.</>');

            return 1;
        }

        $validation = $this->validate();

        foreach ($validation->getWarnings() as $warning) {
            $lines = explode("\n", $warning);
            $output->writeln('<fg=yellow>!</> ' . array_shift($lines));
            foreach ($lines as $line) {
                $output->writeln('    ' . $line);
            }
        }

        if (!$validation->isValid()) {
            $output->writeln('<fg=red>✗ Environment check failed:</>');
            foreach ($validation->getErrors() as $error) {
                $output->writeln('    <fg=red>•</> ' . OutputFormatter::escape($error));
            }
            if ($informational) {
                $output->writeln('    Fix the errors above, then run "composer check-env" to validate the configuration.');

                return 0;
            }

            return 1;
        }

        $output->writeln('<fg=green>✓ Environment check passed.</>');

        return 0;
    }

    private function validate(): ConfigValidation
    {
        try {
            $values = $this->loader->readValues();
        } catch (InvalidConfigurationException $exception) {
            return new ConfigValidation($exception->getErrors(), []);
        }

        $validation = $this->validator->validate($values);
        try {
            foreach (DeprecatedKeys::warnings($this->loader) as $warning) {
                $validation = $validation->withWarning($warning);
            }
        } catch (InvalidConfigurationException $exception) {
            // readValues() parsed the same .env above.
        }
        if (!($this->extensionLoaded)('curl')) {
            $validation = $validation->withError('cURL extension is not installed');
        }
        foreach ($this->loader->readRemovedKeys() as $key) {
            $validation = $validation->withWarning(
                'WARNING: ' . $key . ' is deprecated: it was removed in 3.0 and is ignored.' . "\n"
                . 'Use ' . ConfigLoader::REMOVED_KEYS[$key] . ' instead (see the Image conversion section of the README).'
            );
        }
        if (in_array($values['IMAGE_CONVERTER'] ?? null, [Config::CONVERTER_LOCAL, Config::CONVERTER_REMOTE], true)) {
            $validation = $this->checkLocalConversion($validation, $values);
        }
        if ($this->projectRoot !== null && $validation->isValid()) {
            try {
                $loaded = $this->loader->loadFromArray($values);
            } catch (InvalidConfigurationException $exception) {
                return $validation;
            }
            $expiry = $loaded->getCacheExpiry();
            foreach (self::WEB_SERVER_CONFIGS as $config) {
                $maxAge = self::hitMaxAge($this->projectRoot . '/' . $config);
                if ($maxAge !== null && $maxAge !== $expiry) {
                    $validation = $validation->withWarning(
                        'WARNING: CACHE_EXPIRY is ' . $expiry . ', but ' . $config . ' sends max-age=' . $maxAge . "\n"
                        . 'on the hits the web server serves. Set the same value in both.'
                    );
                }
            }
            foreach ((new OperationsCheck($this->projectRoot))->warnings($loaded) as $warning) {
                $validation = $validation->withWarning($warning);
            }
        }

        return $validation;
    }

    /**
     * The warnings of local conversion (IMAGE_CONVERTER="local", or the
     * fallback of the API): no conversion backend, or no ext-posix.
     *
     * @param array<string, string|null> $values
     */
    private function checkLocalConversion(ConfigValidation $validation, array $values): ConfigValidation
    {
        $local = ($values['IMAGE_CONVERTER'] ?? null) === Config::CONVERTER_LOCAL
            || ConfigValidator::booleanOf((string) ($values['IMAGE_API_FALLBACK'] ?? '')) === true;
        if (!$local) {
            return $validation;
        }
        $backends = array_merge(
            array_filter(self::LOCAL_BACKEND_EXTENSIONS, $this->extensionLoaded),
            array_filter(self::LOCAL_BACKEND_BINARIES, $this->binaryExists)
        );
        if ($backends === []) {
            $validation = $validation->withWarning(
                'WARNING: local image conversion has no backend: install the gd or imagick extension,' . "\n"
                . 'or cwebp, avifenc or vips in PATH. The originals are served meanwhile.'
            );
        }
        if (!($this->extensionLoaded)('posix')) {
            $validation = $validation->withWarning(
                'WARNING: the posix extension is not loaded: local image conversion runs without' . "\n"
                . 'process isolation, and the library does not cache what it detects.'
            );
        }

        return $validation;
    }

    /**
     * The max-age of the Cache-Control that a web server configuration
     * sends on hits; null when the file is missing or sets none.
     */
    public static function hitMaxAge(string $file): ?int
    {
        $contents = is_file($file) ? @file_get_contents($file) : false;
        if ($contents === false || preg_match('/^[ \t]*(?:Header[ \t]+set|add_header)[ \t]+Cache-Control[ \t]+"public, max-age=([0-9]{1,10}),/mi', $contents, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }

    /**
     * Whether "command -v" finds $binary, as 2.x checked it.
     */
    private static function commandExists(string $binary): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $output = [];
        $exitCode = 1;
        exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null', $output, $exitCode);

        return $exitCode === 0 && $output !== [];
    }
}
