<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * The body files that exist right now, so that a fatal error cannot leave
 * them behind: destructors do not run after one (memory exhausted while a
 * body is read into a string, for one), shutdown functions do. The first
 * file registers deleteAll() as a shutdown function; a file is forgotten
 * when it is deleted or moved away.
 *
 * A body file therefore lives at most until PHP ends the request, which
 * its owner's destructor already ensured on a normal end. A process killed
 * by a signal (SIGKILL, or SIGTERM without a handler) runs no shutdown
 * function: its files, each up to the maximum size, stay in the temporary
 * directory until something else removes them. The system's cleaner of
 * old temporary files (systemd-tmpfiles, tmpreaper) covers the system
 * temporary directory, not a configured one. Shutdown functions run
 * before the destructors of the objects still alive, so deleting is
 * idempotent and quiet: an owner whose file is gone does not unlink it
 * again, which would raise a warning that a strict error handler turns
 * into an exception thrown from a destructor.
 *
 * The registry is static, the one exception to the rule against static
 * mutable state: a shutdown function belongs to the request (the process,
 * for the CLI), not to an object, so the files of every client and
 * transport of the request must be reachable from it, and a registry
 * injected into each of them would be kept alive by its own shutdown
 * function until the end of the request anyway. Like every static
 * property, it starts empty in each request.
 *
 * @internal used by ResponseCollector and ResponseBody
 */
final class TemporaryFiles
{
    /**
     * @var array<string, true>
     */
    private static $paths = [];

    /**
     * @var bool
     */
    private static $registered = false;

    public static function track(string $path): void
    {
        self::$paths[$path] = true;
        if (!self::$registered) {
            self::$registered = true;
            register_shutdown_function([self::class, 'deleteAll']);
        }
    }

    public static function forget(string $path): void
    {
        unset(self::$paths[$path]);
    }

    /**
     * Deletes $path, when it still exists, and forgets it.
     */
    public static function delete(string $path): void
    {
        unset(self::$paths[$path]);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Deletes every file still tracked.
     *
     * @internal the shutdown function
     */
    public static function deleteAll(): void
    {
        foreach (array_keys(self::$paths) as $path) {
            self::delete((string) $path);
        }
    }
}
