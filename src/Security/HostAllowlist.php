<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Security;

/**
 * The host names the proxy answers for, and the origin of each
 * (PROXY_HOSTS; risk L-13, opportunity O-J).
 *
 * Each host has a cache directory of its own, named after it, so a host
 * outside the list must never reach the cache: a random Host header would
 * otherwise create a directory per value (disk exhaustion), and a tenant
 * could read or purge the entries of another. The list is strict: a host
 * name matches only itself, in any letter case, with or without a trailing
 * dot and a port, and never through a wildcard. The Host header is the
 * only source; X-Forwarded-Host is never read.
 *
 * PROXY_HOSTS lists entries separated by commas: "host" uses SOURCE_ORIGIN,
 * "host=https://origin.example" its own origin. A host name is made of
 * letters, digits, "-" and ".", as Apache and nginx can map it to a
 * directory (an IPv4 address is a valid one; an IPv6 literal is not).
 *
 * Immutable.
 */
final class HostAllowlist
{
    /**
     * A normalised host name: lower-case labels of letters, digits and "-",
     * separated by single dots. The web server configurations match the
     * same pattern before they look for a cache file.
     */
    public const HOST_PATTERN = '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/';

    /**
     * @var array<string, string> origin URL, without trailing slash, of each normalised host name
     */
    private $origins;

    /**
     * @param array<string, string> $origins origin URL of each host name, as parse() returns it
     */
    public function __construct(array $origins)
    {
        $this->origins = $origins;
    }

    /**
     * The origin URL of each host of a PROXY_HOSTS value, keyed by the
     * normalised host name; null when an entry is malformed: an invalid host
     * name, an origin that is not an absolute http or https URL, or a host
     * listed twice.
     *
     * @param list<string> $entries       the trimmed, non-empty entries of PROXY_HOSTS
     * @param string       $defaultOrigin SOURCE_ORIGIN, the origin of an entry without "="
     *
     * @return array<string, string>|null
     */
    public static function parse(array $entries, string $defaultOrigin): ?array
    {
        $origins = [];
        foreach ($entries as $entry) {
            $equals = strpos($entry, '=');
            $host = self::normalise($equals === false ? $entry : (string) substr($entry, 0, $equals));
            $origin = $equals === false ? $defaultOrigin : rtrim(trim((string) substr($entry, $equals + 1)), '/');
            if ($host === null || isset($origins[$host]) || !self::isOriginUrl($origin)) {
                return null;
            }
            $origins[$host] = $origin;
        }

        return $origins;
    }

    /**
     * The host name of a Host header value in the form of the list: lower
     * case, without port and trailing dot; null when it is not a valid host
     * name.
     */
    public static function normalise(string $host): ?string
    {
        $host = strtolower(trim($host));
        if (preg_match('/\A(.*?)(?::[0-9]{1,5})?\z/', $host, $match) === 1) {
            $host = $match[1];
        }
        if (substr($host, -1) === '.') {
            $host = (string) substr($host, 0, -1);
        }

        return strlen($host) <= 253 && preg_match(self::HOST_PATTERN, $host) === 1 ? $host : null;
    }

    /**
     * Whether $url is an absolute http or https URL without query string or
     * fragment, as an origin must be.
     */
    public static function isOriginUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('#\Ahttps?://#i', $url) !== 1) {
            return false;
        }

        return parse_url($url, PHP_URL_QUERY) === null && parse_url($url, PHP_URL_FRAGMENT) === null;
    }

    /**
     * The normalised host name of $host when it is in the list; null
     * otherwise.
     *
     * @param string $host a Host header value, or Symfony's Request::getHost()
     */
    public function match(string $host): ?string
    {
        $normalised = self::normalise($host);

        return $normalised !== null && isset($this->origins[$normalised]) ? $normalised : null;
    }

    /**
     * The origin URL of a host returned by match(), without trailing slash.
     */
    public function originOf(string $host): ?string
    {
        return $this->origins[$host] ?? null;
    }

    /**
     * The host names of the list, normalised.
     *
     * @return list<string>
     */
    public function getHosts(): array
    {
        return array_map('strval', array_keys($this->origins));
    }

    /**
     * The distinct origin URLs of the list.
     *
     * @return list<string>
     */
    public function getOrigins(): array
    {
        return array_values(array_unique(array_values($this->origins)));
    }
}
