<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Support;

/**
 * Error handler installed by tests/bootstrap.php.
 *
 * Every notice, warning and deprecation becomes an \ErrorException, so that a
 * PHP 8.4 or 8.5 deprecation in the project's own code fails the build.
 * Deprecations raised by files inside vendor/ are ignored: the lowest
 * supported releases of the dependencies trigger them on the newest PHP, and
 * they are not the project's to fix. Errors silenced with the @ operator, or
 * excluded by error_reporting(), are left to PHP.
 */
final class StrictErrorHandler
{
    /**
     * @var string Absolute vendor directory with forward slashes and a trailing slash
     */
    private $vendorDirectory;

    /**
     * @param string $vendorDirectory absolute path of the Composer vendor directory
     */
    public function __construct(string $vendorDirectory)
    {
        $this->vendorDirectory = rtrim(str_replace('\\', '/', $vendorDirectory), '/') . '/';
    }

    /**
     * Handles one PHP error, with the signature expected by set_error_handler().
     *
     * @return bool true when the error is deliberately ignored, false to let PHP handle it
     *
     * @throws \ErrorException for every error that is reported and not ignored
     */
    public function __invoke(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        $isDeprecation = $severity === E_DEPRECATED || $severity === E_USER_DEPRECATED;
        if ($isDeprecation && strpos(str_replace('\\', '/', $file), $this->vendorDirectory) === 0) {
            return true;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }
}
