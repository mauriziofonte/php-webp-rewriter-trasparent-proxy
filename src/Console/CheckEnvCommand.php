<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Console;

use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Config\ConfigLoader;
use Mfonte\ProxyCdn\Config\ConfigValidation;
use Mfonte\ProxyCdn\Config\ConfigValidator;
use Mfonte\ProxyCdn\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * "check-env": validates the configuration with the rules the proxy applies
 * at boot (ConfigValidator), plus the host requirements: the curl extension
 * and, in native conversion mode, the cwebp binary. Prints the 2.x messages
 * and exits with 1 on any error.
 *
 * With --informational, used by the Composer install hooks, it always exits
 * with 0, so that installing the dependencies never fails because the proxy
 * is not configured yet (finding P-20).
 */
final class CheckEnvCommand extends Command
{
    public const NAME = 'check-env';

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
     * @param ConfigLoader                  $loader          reads the environment and the .env file of the installation
     * @param (callable(string): bool)|null $binaryExists    whether a binary is in PATH; "command -v" by default
     * @param (callable(string): bool)|null $extensionLoaded whether a PHP extension is loaded; extension_loaded() by default
     */
    public function __construct(
        ConfigLoader $loader,
        ?ConfigValidator $validator = null,
        ?callable $binaryExists = null,
        ?callable $extensionLoaded = null
    ) {
        $this->loader = $loader;
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
        if (!($this->extensionLoaded)('curl')) {
            $validation = $validation->withError('cURL extension is not installed');
        }
        if (($values['IMAGE_CONVERSION_MODE'] ?? null) === Config::CONVERSION_NATIVE && !($this->binaryExists)('cwebp')) {
            $validation = $validation->withError('cwebp binary not found in PATH (required when IMAGE_CONVERSION_MODE = "native")');
        }

        return $validation;
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
