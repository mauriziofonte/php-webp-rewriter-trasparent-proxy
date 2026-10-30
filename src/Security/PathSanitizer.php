<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Security;

/**
 * Decides whether a request path may be mapped to a file below the cache
 * directory. Port of the 2.x check only: a path that contains "/.." is
 * refused. The path is the raw, still percent-encoded path of the request
 * and always starts with "/", so this check rules out every ".." segment.
 * PRX-5 hardens it for decoded paths (finding P-8).
 */
final class PathSanitizer
{
    /**
     * $path when it is allowed, null when it is refused.
     *
     * @param string $path the request path, starting with "/"
     *
     * @psalm-taint-escape file
     */
    public function sanitize(string $path): ?string
    {
        return strpos($path, '/..') === false ? $path : null;
    }
}
