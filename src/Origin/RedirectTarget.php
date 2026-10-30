<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * Resolves the Location of a redirect against the URL that answered it
 * (RFC 3986, section 5.2), for the transports that follow redirects
 * themselves.
 *
 * @internal used by StreamTransport and MockTransport
 */
final class RedirectTarget
{
    /**
     * The absolute URL $location points to, or null when it is not an http
     * or https URL: redirects to any other scheme are never followed.
     *
     * @param string $base     the absolute http or https URL that answered the redirect
     * @param string $location the Location field value
     */
    public static function resolve(string $base, string $location): ?string
    {
        $location = trim($location);
        if ($location === '' || strpbrk($location, "\r\n\0 ") !== false) {
            return null;
        }

        $fragment = strpos($location, '#');
        if ($fragment !== false) {
            $location = (string) substr($location, 0, $fragment);
        }

        if (preg_match('#\A([A-Za-z][A-Za-z0-9+.-]*):#', $location, $matches) === 1) {
            $scheme = strtolower($matches[1]);
            if ($scheme !== 'http' && $scheme !== 'https') {
                return null;
            }
            $url = $location;
        } else {
            $parts = parse_url($base);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
                return null;
            }
            $authority = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            if (strncmp($location, '//', 2) === 0) {
                $url = $parts['scheme'] . ':' . $location;
            } elseif ($location[0] === '/') {
                $url = $authority . self::removeDotSegments($location);
            } elseif ($location[0] === '?') {
                $url = $authority . ($parts['path'] ?? '/') . $location;
            } else {
                $basePath = $parts['path'] ?? '/';
                $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
                $url = $authority . self::removeDotSegments(($directory === '' ? '/' : $directory) . $location);
            }
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $url : null;
    }

    /**
     * The path of a reference with its "." and ".." segments resolved; the
     * query string, if any, is kept as it is.
     */
    private static function removeDotSegments(string $reference): string
    {
        $query = '';
        $mark = strpos($reference, '?');
        if ($mark !== false) {
            $query = (string) substr($reference, $mark);
            $reference = (string) substr($reference, 0, $mark);
        }
        if (strpos($reference, '.') === false) {
            return $reference . $query;
        }

        $output = [];
        $segments = explode('/', $reference);
        $last = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            if ($segment === '..') {
                if (count($output) > 1) {
                    array_pop($output);
                }
                if ($index === $last) {
                    $output[] = '';
                }
            } elseif ($segment === '.') {
                if ($index === $last) {
                    $output[] = '';
                }
            } else {
                $output[] = $segment;
            }
        }

        $path = implode('/', $output);

        return ($path === '' || $path[0] !== '/' ? '/' . $path : $path) . $query;
    }
}
