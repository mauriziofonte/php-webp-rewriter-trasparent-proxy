<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Observability;

/**
 * The answer of GET /healthz (opportunity O-E), for a load balancer or an
 * orchestrator: whether this instance can serve, without revealing
 * anything about the host.
 *
 * It checks that the cache directory and the state directory are writable
 * by the PHP user, or can be created (the nearest directory above them
 * that exists is writable), as on a fresh installation: without them
 * every miss fails. It never
 * contacts the origin, whose outage the circuit breaker already handles
 * (taking every instance out of a load balancer because the origin is
 * down would also stop the hits). For the same reason the states of the
 * image conversions do not count: an image waiting for its variants, a
 * failed conversion or an open circuit breaker of the conversion API
 * leave the original served, and Metrics reports them; the pending tier
 * and the work directory of the conversions live in the cache directory
 * and the conversion state in the state directory, which are checked. The report names the checks by fixed
 * words only: no path, no version, no configuration value.
 */
final class HealthCheck
{
    public const STATUS_OK = 'ok';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const CHECK_CACHE = 'cache';

    public const CHECK_STATE = 'state';

    /**
     * @var array<string, string> check name => directory
     */
    private $directories;

    /**
     * @param string $cacheDirectory the cache root (public/_cache)
     * @param string $stateDirectory the state directory (var/)
     */
    public function __construct(string $cacheDirectory, string $stateDirectory)
    {
        $this->directories = [self::CHECK_CACHE => $cacheDirectory, self::CHECK_STATE => $stateDirectory];
    }

    /**
     * The status and the outcome of each check: "ok", or "unwritable".
     *
     * @return array{status: string, checks: array<string, string>}
     */
    public function check(): array
    {
        $checks = [];
        $healthy = true;
        foreach ($this->directories as $name => $directory) {
            $ok = self::isWritable($directory);
            $checks[$name] = $ok ? 'ok' : 'unwritable';
            $healthy = $healthy && $ok;
        }

        return ['status' => $healthy ? self::STATUS_OK : self::STATUS_UNAVAILABLE, 'checks' => $checks];
    }

    /**
     * Whether $directory is a writable directory, or is missing below a
     * writable one.
     */
    private static function isWritable(string $directory): bool
    {
        clearstatcache();
        while (!file_exists($directory) && !is_link($directory)) {
            $parent = dirname($directory);
            if ($parent === $directory) {
                return false;
            }
            $directory = $parent;
        }

        return is_dir($directory) && is_writable($directory);
    }
}
