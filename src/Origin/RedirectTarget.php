<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

use Mfonte\ProxyCdn\Exception\InvalidArgumentException;

/**
 * The redirect policy of every transport: the Location of a redirect is
 * resolved against the URL that answered it (RFC 3986, section 5.2), and
 * the header fields bound to an origin are not sent to another one.
 *
 * @internal used by the transports of this namespace
 */
final class RedirectTarget
{
    /**
     * Request fields sent to the origin of the request only: they are
     * dropped when a redirect leads to another scheme, host or port, as
     * libcurl 7.83 and later does for Authorization and Cookie (finding 2
     * of the PRX-4 review).
     */
    public const ORIGIN_BOUND_HEADERS = ['authorization', 'cookie', 'proxy-authorization', 'host'];

    /**
     * The request of the redirect $request received, to $location: the same
     * method and fields, without ORIGIN_BOUND_HEADERS when the target is on
     * another scheme, host or port. Once dropped, they are not sent again,
     * even when a later redirect comes back.
     *
     * @param string $location the Location field value
     *
     * @return HttpRequest|null null when $location is not an http or https URL
     */
    public static function follow(HttpRequest $request, string $location): ?HttpRequest
    {
        $target = self::resolve($request->getUrl(), $location);
        if ($target === null) {
            return null;
        }
        try {
            $next = $request->withUrl($target);
        } catch (InvalidArgumentException $exception) {
            return null;
        }
        if (self::origin($target) !== self::origin($request->getUrl())) {
            foreach (self::ORIGIN_BOUND_HEADERS as $name) {
                $next = $next->withoutHeader($name);
            }
        }

        return $next;
    }

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
        if ($location === '' || preg_match(HttpRequest::URL_FORBIDDEN, $location) === 1) {
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
     * The scheme, host and port of an http or https URL, in one string.
     */
    private static function origin(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = parse_url($url, PHP_URL_PORT);

        return $scheme . '://' . strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]')) . ':'
            . (is_int($port) ? $port : ($scheme === 'https' ? 443 : 80));
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
