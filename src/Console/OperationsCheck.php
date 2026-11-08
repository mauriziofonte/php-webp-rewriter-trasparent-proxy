<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Console;

use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Config\OperationsConfig;
use Mfonte\ProxyCdn\Observability\JsonLogger;
use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;

/**
 * The checks of check-env on the installation itself (PRX-9), which the
 * proxy would otherwise only report in its log, request after request:
 *
 * - a state directory the conversion library's LockDirectory refuses (a
 *   parent writable by other users, another owner): var/locks turns
 *   request coalescing off, var/metrics stops the metrics, var/ratelimit
 *   lets every miss through. A directory that does not exist yet is
 *   judged by the directories it would be created in, and is not created;
 * - a log file (LOG_FILE, else logs/proxy.log) that cannot be written;
 * - what 2.x left in the installation, which 3.x never reads or serves:
 *   .cached.env.php, and entries of public/ other than those of 3.x (see
 *   cache:migrate-2x).
 *
 * Every finding is a warning: the proxy still answers.
 */
final class OperationsCheck
{
    /**
     * The entries of public/ that 3.x ships or writes.
     */
    public const PUBLIC_ENTRIES = ['index.php', '.htaccess', '_cache'];

    /**
     * The compiled configuration of 2.x, in the project root.
     */
    public const LEGACY_CONFIG_CACHE = '.cached.env.php';

    /**
     * @var string
     */
    private $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/');
    }

    /**
     * @return list<string>
     */
    public function warnings(Config $config): array
    {
        $operations = $config->getOperations();
        $state = $this->projectRoot . '/var';
        $directories = ['locks' => 'requests for the same miss are not coalesced'];
        if ($operations->getMetricsStore() === OperationsConfig::METRICS_STORE_FILE) {
            $directories['metrics'] = 'no metrics are recorded';
        }
        if ($operations->getMissRateLimit() > 0) {
            $directories['ratelimit'] = 'MISS_RATE_LIMIT lets every miss through';
        }

        $warnings = [];
        foreach ($directories as $name => $effect) {
            $problem = self::lockDirectoryProblem($state . '/' . $name);
            if ($problem !== null) {
                $warnings[] = 'WARNING: var/' . $name . ' is refused as a lock directory, so ' . $effect . ":\n" . $problem;
            }
        }

        $logFile = $operations->getLogFile() ?? $this->projectRoot . '/logs/proxy.log';
        if ($logFile !== JsonLogger::DESTINATION_STDERR && $logFile !== JsonLogger::DESTINATION_ERROR_LOG && !self::isWritableFile($logFile)) {
            $warnings[] = 'WARNING: the log file ' . $logFile . " cannot be written: nothing is logged.\n"
                . 'Make it writable by the PHP user, or set LOG_FILE=stderr or LOG_FILE=error_log.';
        }

        return array_merge($warnings, $this->legacyWarnings());
    }

    /**
     * Why LockDirectory refuses $directory; null when it accepts it. A
     * missing directory is not created: the directories it would be
     * created in must be owned by root or by this user, must not be
     * writable by others without the sticky bit, and the nearest one
     * must be writable.
     */
    public static function lockDirectoryProblem(string $directory): ?string
    {
        if (file_exists($directory) || is_link($directory)) {
            try {
                new LockDirectory($directory);
            } catch (ExceptionInterface $exception) {
                return $exception->getMessage();
            }

            return null;
        }

        $user = self::effectiveUserId();
        $nearest = null;
        for ($current = dirname($directory); ; $current = dirname($current)) {
            if (is_dir($current)) {
                $nearest = $nearest ?? $current;
                $owner = @fileowner($current);
                $mode = @fileperms($current);
                if ($owner === false || $mode === false) {
                    return $current . ' cannot be inspected.';
                }
                if ($owner !== 0 && $owner !== $user) {
                    return $current . ' is owned by another user (' . $owner . ').';
                }
                if (($mode & 0022) !== 0 && ($mode & 01000) === 0) {
                    return $current . ' is writable by its group or by other users (mode ' . sprintf('%04o', $mode & 07777) . ').';
                }
            }
            if ($current === '/' || $current === '.' || $current === dirname($current)) {
                break;
            }
        }
        if ($nearest === null || !is_writable($nearest)) {
            return $directory . ' cannot be created: ' . ($nearest ?? 'its parent') . ' is not writable.';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function legacyWarnings(): array
    {
        $warnings = [];
        if (file_exists($this->projectRoot . '/' . self::LEGACY_CONFIG_CACHE)) {
            $warnings[] = 'WARNING: ' . self::LEGACY_CONFIG_CACHE . " of 2.x is still in the project root. 3.x never reads it,\n"
                . 'and it holds the 2.x configuration in clear: delete it.';
        }
        $entries = @scandir($this->projectRoot . '/public');
        $legacy = [];
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..' && !in_array($entry, self::PUBLIC_ENTRIES, true)) {
                $legacy[] = $entry;
            }
        }
        if ($legacy !== []) {
            sort($legacy);
            $shown = array_slice($legacy, 0, 5);
            $warnings[] = 'WARNING: public/ holds ' . count($legacy) . ' entr' . (count($legacy) === 1 ? 'y' : 'ies') . ' that 3.x does not serve ('
                . implode(', ', $shown) . (count($legacy) > count($shown) ? ', ...' : '') . "),\n"
                . 'such as the cache of 2.x. Run "bin/proxy-cdn cache:migrate-2x" to import or delete it.';
        }

        return $warnings;
    }

    /**
     * Whether $file can be appended to, or created in a writable directory
     * (JsonLogger creates the missing directories).
     */
    private static function isWritableFile(string $file): bool
    {
        if (file_exists($file)) {
            return is_file($file) && is_writable($file);
        }
        for ($current = dirname($file); ; $current = dirname($current)) {
            if (file_exists($current)) {
                return is_dir($current) && is_writable($current);
            }
            if ($current === dirname($current)) {
                return false;
            }
        }
    }

    /**
     * The effective user ID: from ext-posix when loaded, else the owner of
     * a new temporary file, as LockDirectory determines it.
     */
    private static function effectiveUserId(): int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }
        $file = @tempnam(sys_get_temp_dir(), 'proxy-cdn-uid-');
        if ($file === false) {
            return -1;
        }
        $owner = @fileowner($file);
        @unlink($file);

        return $owner === false ? -1 : $owner;
    }
}
