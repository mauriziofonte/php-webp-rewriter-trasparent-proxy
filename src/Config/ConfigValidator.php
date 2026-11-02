<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Config;

use Mfonte\ProxyCdn\Cache\QueryStringPolicy;
use Mfonte\ProxyCdn\Exception\InvalidArgumentException;
use Mfonte\ProxyCdn\Net\IpRange;
use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Security\HostAllowlist;
use Mfonte\ProxyCdn\Security\PurgeAuthenticator;

/**
 * Validates the raw configuration values, as read from the environment and
 * the .env file. ConfigLoader runs it before the proxy starts and the
 * check-env command prints its errors and warnings, so both apply the same
 * rules (plan section 4).
 *
 * Raw values are strings, or null for a key that is not set; the empty
 * string and the literal "null" have already been turned into null by
 * ConfigLoader::normalise(). Host checks such as the presence of the curl
 * extension are not configuration and stay in check-env.
 */
final class ConfigValidator
{
    /**
     * Extensions whose responses may depend on the client's session.
     */
    private const SENSITIVE_EXTENSIONS = ['html', 'htm', 'php', 'xml', 'json'];

    /**
     * The largest UPSTREAM_MAX_SIZE, 256 MiB: bodies are streamed to disk,
     * but a fetch holds a PHP worker and a temporary file for its whole
     * transfer.
     */
    public const MAX_UPSTREAM_MAX_SIZE = 268435456;

    /**
     * Inclusive ranges of the integer keys.
     */
    private const INTEGER_RANGES = [
        'CACHE_EXPIRY' => [0, PHP_INT_MAX],
        'CACHE_REVALIDATE_BELOW' => [0, 31536000],
        'STALE_WHILE_REVALIDATE' => [0, 86400],
        'STALE_IF_ERROR' => [0, 604800],
        'NEGATIVE_CACHE_TTL' => [0, 86400],
        'NETWORK_ERROR_TTL' => [0, 3600],
        'CIRCUIT_BREAKER_THRESHOLD' => [1, 1000],
        'UPSTREAM_TIMEOUT' => [1, 300],
        'UPSTREAM_CONNECT_TIMEOUT' => [1, 60],
        'UPSTREAM_MAX_SIZE' => [1, self::MAX_UPSTREAM_MAX_SIZE],
        'IMAGE_CONVERSION_TIMEOUT' => [1, 600],
    ];

    private const BOOLEAN_KEYS = ['DEBUG', 'UPSTREAM_VERIFY_TLS', 'UPSTREAM_FOLLOW_REDIRECTS', 'PRECOMPRESS', 'LEGACY_WEBP_URLS', 'IMAGE_API_FALLBACK'];

    /**
     * The shortest IMAGE_API_KEY, in bytes: the shortest key the
     * conversion API accepts.
     */
    public const MIN_IMAGE_API_KEY_BYTES = 16;

    /**
     * The shortest RESIZE_KEY, in bytes: an HMAC-SHA256 key of 256 bits.
     */
    public const MIN_RESIZE_KEY_BYTES = 32;

    /**
     * The most RESIZE_WIDTHS: each width is one more entry of every
     * resized image, and of each of its variants (risk L-14).
     */
    public const MAX_RESIZE_WIDTHS = 16;

    /**
     * The largest resize width: the largest dimension the conversion
     * library can produce (ConversionRequest::DIMENSION_MAX).
     */
    public const MAX_RESIZE_WIDTH = 16383;

    /**
     * The variant formats of IMAGE_FORMATS (CacheLocation::FORMATS).
     */
    private const IMAGE_FORMATS = ['avif', 'webp'];

    /**
     * The QUERY_STRING_ALLOWLIST value that keeps no parameter.
     */
    private const NO_PARAMETER = 'none';

    /**
     * @param array<string, string|null> $values raw values keyed by the names of ConfigLoader::KEYS
     */
    public function validate(array $values): ConfigValidation
    {
        $errors = [];
        $warnings = [];

        $origin = $values['SOURCE_ORIGIN'] ?? null;
        if ($origin === null) {
            $errors[] = 'SOURCE_ORIGIN must be set';
        } elseif (filter_var($origin, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'SOURCE_ORIGIN must be a valid URL (got: ' . var_export($origin, true) . ')';
        } elseif (preg_match('#^https?://#i', $origin) !== 1) {
            $errors[] = 'SOURCE_ORIGIN must use http:// or https:// scheme (got: ' . var_export($origin, true) . ')';
        }

        // PROXY_HOSTS is checked only against a valid SOURCE_ORIGIN, the origin of its entries without one.
        $originIsValid = $origin !== null && $errors === [];

        $server = $values['SOURCE_ORIGIN_SERVER'] ?? null;
        if ($server !== null && HttpClientOptions::parseAddress($server) === null) {
            $errors[] = 'SOURCE_ORIGIN_SERVER must be "address", "address:port" or "[IPv6 address]:port" (got: ' . var_export($server, true) . ')';
        }

        $hosts = $values['PROXY_HOSTS'] ?? null;
        $origins = $originIsValid ? HostAllowlist::parse(self::entryList($hosts), rtrim((string) $origin, '/')) : null;
        if ($hosts === null) {
            $errors[] = 'PROXY_HOSTS must be set: the host names the proxy answers for, separated by commas '
                . '("cdn.example.com", or "cdn.example.com=https://origin.example.com" for another origin than SOURCE_ORIGIN)';
        } elseif ($originIsValid && $origins === null) {
            $errors[] = 'PROXY_HOSTS must list "host" or "host=origin URL" entries separated by commas, each host once: '
                . 'a host is letters, digits, "-" and "." (no IPv6 literal, no wildcard), an origin an http or https URL (got: '
                . var_export($hosts, true) . ')';
        } elseif ($server !== null && $origins !== null && count(array_unique($origins)) > 1) {
            $errors[] = 'SOURCE_ORIGIN_SERVER cannot be combined with PROXY_HOSTS entries of another origin: it pins the connections to every origin';
        }

        $sendfile = $values['SENDFILE'] ?? null;
        if ($sendfile !== null && !in_array($sendfile, Config::SENDFILE_MODES, true)) {
            $errors[] = 'SENDFILE must be one of: ' . implode(', ', Config::SENDFILE_MODES) . ' (got: ' . var_export($sendfile, true) . ')';
        }

        $userAgent = $values['UPSTREAM_USER_AGENT'] ?? null;
        if ($userAgent !== null && !HttpClientOptions::isUserAgent($userAgent)) {
            $errors[] = 'UPSTREAM_USER_AGENT must be 1 to 512 printable ASCII characters (got: ' . var_export($userAgent, true) . ')';
        }

        $extensions = self::extensionList($values['CACHEABLE_EXTENSIONS'] ?? null);
        if ($extensions === []) {
            $errors[] = 'CACHEABLE_EXTENSIONS must be set (comma-separated list of file extensions)';
        }
        $sensitive = array_values(array_intersect(self::SENSITIVE_EXTENSIONS, $extensions));
        if ($sensitive !== []) {
            $warnings[] = 'WARNING: CACHEABLE_EXTENSIONS includes session/auth-sensitive types: ' . implode(', ', $sensitive) . ".\n"
                . "If the origin ever serves authenticated content with these extensions,\n"
                . 'the proxy will cache and re-serve it to unrelated clients.';
        }

        $allowlist = $values['QUERY_STRING_ALLOWLIST'] ?? null;
        if ($allowlist !== null) {
            $names = self::parameterList($allowlist);
            $special = array_intersect($names, [QueryStringPolicy::ANY_PARAMETER]);
            $invalid = array_filter($names, static function (string $name): bool {
                return !QueryStringPolicy::isParameterName($name);
            });
            if ($names === [] && strtolower(trim($allowlist)) !== self::NO_PARAMETER) {
                $errors[] = 'QUERY_STRING_ALLOWLIST must list parameter names separated by commas, "*" or "none" (got: ' . var_export($allowlist, true) . ')';
            } elseif ($invalid !== []) {
                $errors[] = 'QUERY_STRING_ALLOWLIST holds an invalid parameter name (got: ' . var_export($allowlist, true) . ')';
            } elseif ($special !== [] && count($names) > 1) {
                $errors[] = 'QUERY_STRING_ALLOWLIST must be "*" alone to keep every parameter (got: ' . var_export($allowlist, true) . ')';
            }
        }
        $queryMode = $values['QUERY_STRING_MODE'] ?? null;
        if ($queryMode !== null && !in_array($queryMode, [QueryStringPolicy::MODE_IGNORE, QueryStringPolicy::MODE_REJECT], true)) {
            $errors[] = 'QUERY_STRING_MODE must be one of: ignore, reject (got: ' . var_export($queryMode, true) . ')';
        }
        if ($allowlist !== null && trim($allowlist) === QueryStringPolicy::ANY_PARAMETER) {
            $warnings[] = "NOTE: QUERY_STRING_ALLOWLIST is \"*\": every distinct query string is\n"
                . "a cache entry and an origin fetch of its own, as in 2.x, so random\n"
                . 'query strings can fill the disk and bypass the cache.';
        }

        try {
            IpRange::listFromString((string) ($values['TRUSTED_PROXIES'] ?? ''));
        } catch (InvalidArgumentException $exception) {
            $errors[] = 'TRUSTED_PROXIES must list IP addresses and CIDR blocks separated by commas: ' . $exception->getMessage();
        }

        // The value holds secrets: the error never quotes it.
        if (PurgeAuthenticator::parseKeys(self::entryList($values['PURGE_KEYS'] ?? null)) === null) {
            $errors[] = 'PURGE_KEYS must list "scope=secret" pairs separated by commas: the scope is the host name of an origin or "*", '
                . 'the secret ' . PurgeAuthenticator::MIN_SECRET_BYTES . ' or more printable ASCII characters without spaces or commas';
        }

        list($conversionErrors, $conversionWarnings) = self::validateConversion($values);
        $errors = array_merge($errors, $conversionErrors);
        $warnings = array_merge($warnings, $conversionWarnings);

        foreach (self::INTEGER_RANGES as $key => $range) {
            $raw = $values[$key] ?? null;
            if ($raw === null) {
                continue;
            }
            if (preg_match('/\A[0-9]+\z/', $raw) !== 1) {
                $errors[] = $key . ' must be a non-negative integer (got: ' . var_export($raw, true) . ')';
                continue;
            }
            $value = (int) $raw;
            if ($value < $range[0] || $value > $range[1]) {
                $errors[] = sprintf('%s must be between %d and %d (got: %d)', $key, $range[0], $range[1], $value);
            }
        }

        foreach (self::BOOLEAN_KEYS as $key) {
            $raw = $values[$key] ?? null;
            if ($raw !== null && self::booleanOf($raw) === null) {
                $errors[] = $key . ' must be one of: true, false, 1, 0 (got: ' . var_export($raw, true) . ')';
            }
        }

        $timezone = $values['TIMEZONE'] ?? null;
        if ($timezone !== null && !self::isTimezone($timezone)) {
            $errors[] = 'TIMEZONE must be a valid time zone identifier (got: ' . var_export($timezone, true) . ')';
        }

        if (self::booleanOf($values['DEBUG'] ?? 'false') === true) {
            $warnings[] = "WARNING: DEBUG is enabled. MUST be disabled in production,\n"
                . 'or PHP warnings will poison asset bodies streamed to clients.';
        }

        if (($values['SOURCE_ORIGIN_SERVER'] ?? null) !== null) {
            $warnings[] = "NOTE: SOURCE_ORIGIN_SERVER is set. Origin requests connect to that\n"
                . "address (an IP or a host name, with an optional port) instead of the\n"
                . "address of the origin host name, which they keep for the Host header\n"
                . "and TLS. This intentionally bypasses any CDN / WAF that may sit in\n"
                . 'front of the origin hostname.';
        }

        $verifyTls = $values['UPSTREAM_VERIFY_TLS'] ?? null;
        if ($verifyTls !== null && self::booleanOf($verifyTls) === false) {
            $warnings[] = "NOTE: UPSTREAM_VERIFY_TLS is off. The origin TLS certificate\n"
                . 'will NOT be validated. Acceptable on trusted networks only.';
        } elseif ($verifyTls === null && $origin !== null && preg_match('#^https://#i', $origin) === 1) {
            // Risk L-6: 2.x did not verify by default, so an upgrade can
            // break an origin whose certificate is self-signed or mismatched.
            $warnings[] = "NOTE: UPSTREAM_VERIFY_TLS is not set, so the origin TLS certificate\n"
                . "is verified (the default since 3.0; 2.x did not verify). An origin\n"
                . "with a self-signed or mismatched certificate now answers 503: fix\n"
                . "the certificate, or set UPSTREAM_VERIFY_TLS=false on trusted\n"
                . 'networks only.';
        }

        return new ConfigValidation($errors, $warnings);
    }

    /**
     * The errors and warnings of the image conversion and resize keys
     * (PRX-8). The values of IMAGE_API_KEY and RESIZE_KEY are secrets: no
     * message quotes them.
     *
     * @param array<string, string|null> $values
     *
     * @return array{list<string>, list<string>}
     */
    private static function validateConversion(array $values): array
    {
        $errors = [];
        $warnings = [];
        $converter = $values['IMAGE_CONVERTER'] ?? Config::CONVERTER_NONE;
        if (!in_array($converter, Config::CONVERTERS, true)) {
            $errors[] = 'IMAGE_CONVERTER must be one of: ' . implode(', ', Config::CONVERTERS) . ' (got: ' . var_export($converter, true) . ')';
        }

        $formats = $values['IMAGE_FORMATS'] ?? null;
        if ($formats !== null) {
            $list = self::formatList($formats);
            if ($list === [] || count($list) !== count(self::entryList($formats)) || array_diff($list, self::IMAGE_FORMATS) !== []) {
                $errors[] = 'IMAGE_FORMATS must list ' . implode(' and/or ', self::IMAGE_FORMATS) . ', separated by commas, each once (got: ' . var_export($formats, true) . ')';
            }
        }

        $url = $values['IMAGE_API_URL'] ?? null;
        if ($url !== null) {
            $parts = parse_url($url);
            if (filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($parts)
                || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            ) {
                $errors[] = 'IMAGE_API_URL must be the http:// or https:// base URL of the conversion API, without credentials, query string or fragment (got: ' . var_export($url, true) . ')';
            } elseif (strtolower($parts['scheme']) === 'http') {
                $warnings[] = "WARNING: IMAGE_API_URL uses plain http://: the API key and the images\n"
                    . 'travel in clear text. Use https:// outside a private network.';
            }
        }

        $key = $values['IMAGE_API_KEY'] ?? null;
        if ($key !== null && (strlen(trim($key)) < self::MIN_IMAGE_API_KEY_BYTES || trim($key) !== $key || preg_match('/\A[\x20-\x7E]+\z/', $key) !== 1)) {
            $errors[] = 'IMAGE_API_KEY must be ' . self::MIN_IMAGE_API_KEY_BYTES . ' or more printable ASCII characters, without leading or trailing spaces';
        }

        $caFile = $values['IMAGE_API_CA_FILE'] ?? null;
        if ($caFile !== null && (strncmp($caFile, '/', 1) !== 0 || !is_file($caFile) || !is_readable($caFile))) {
            $errors[] = 'IMAGE_API_CA_FILE must be the absolute path of a readable PEM file (got: ' . var_export($caFile, true) . ')';
        }

        if ($converter === Config::CONVERTER_REMOTE) {
            if ($url === null) {
                $errors[] = 'IMAGE_API_URL must be set for IMAGE_CONVERTER "remote"';
            }
            if ($key === null) {
                $errors[] = 'IMAGE_API_KEY must be set for IMAGE_CONVERTER "remote"';
            }
        } elseif (self::booleanOf($values['IMAGE_API_FALLBACK'] ?? 'false') === true) {
            $warnings[] = 'NOTE: IMAGE_API_FALLBACK is only used with IMAGE_CONVERTER "remote".';
        }

        $widths = $values['RESIZE_WIDTHS'] ?? null;
        if ($widths !== null) {
            $list = self::widthList($widths);
            $entries = self::entryList($widths);
            if ($list === [] || count($list) !== count($entries) || count($list) !== count(array_unique($list)) || count($list) > self::MAX_RESIZE_WIDTHS) {
                $errors[] = 'RESIZE_WIDTHS must list 1 to ' . self::MAX_RESIZE_WIDTHS . ' distinct widths in pixels, from 1 to '
                    . self::MAX_RESIZE_WIDTH . ', separated by commas (got: ' . var_export($widths, true) . ')';
            }
            if (($values['RESIZE_KEY'] ?? null) === null) {
                $errors[] = 'RESIZE_KEY must be set when RESIZE_WIDTHS is';
            }
            if ($converter === Config::CONVERTER_NONE) {
                $errors[] = 'RESIZE_WIDTHS needs a converter: set IMAGE_CONVERTER to "local" or "remote"';
            }
        }
        $resizeKey = $values['RESIZE_KEY'] ?? null;
        if ($resizeKey !== null && (strlen($resizeKey) < self::MIN_RESIZE_KEY_BYTES || preg_match('/\A[\x21-\x7E]+\z/', $resizeKey) !== 1)) {
            $errors[] = 'RESIZE_KEY must be ' . self::MIN_RESIZE_KEY_BYTES . ' or more printable ASCII characters without spaces';
        }

        return [$errors, $warnings];
    }

    /**
     * The lower-case, trimmed, non-empty formats of an IMAGE_FORMATS
     * value, duplicates removed, in the order of preference of the web
     * server (AVIF, then WEBP); unknown names are kept, for the validator
     * to refuse.
     *
     * @return list<string>
     */
    public static function formatList(string $raw): array
    {
        $formats = array_values(array_unique(array_map('strtolower', self::entryList($raw))));
        usort($formats, static function (string $a, string $b): int {
            $rank = array_flip(self::IMAGE_FORMATS);

            return ($rank[$a] ?? 99) <=> ($rank[$b] ?? 99);
        });

        return $formats;
    }

    /**
     * The widths of a RESIZE_WIDTHS value that are integers from 1 to
     * MAX_RESIZE_WIDTH written in decimal digits, in ascending order,
     * duplicates removed; [] for null.
     *
     * @return list<int>
     */
    public static function widthList(?string $raw): array
    {
        $widths = [];
        foreach (self::entryList($raw) as $entry) {
            if (preg_match('/\A[1-9][0-9]{0,4}\z/', $entry) === 1 && (int) $entry <= self::MAX_RESIZE_WIDTH) {
                $widths[] = (int) $entry;
            }
        }
        $widths = array_values(array_unique($widths));
        sort($widths);

        return $widths;
    }

    /**
     * The trimmed, non-empty entries of a comma-separated value; [] for
     * null.
     *
     * @return list<string>
     */
    public static function entryList(?string $raw): array
    {
        $entries = [];
        foreach (explode(',', (string) $raw) as $entry) {
            $entry = trim($entry);
            if ($entry !== '') {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * The entries of a PROXY_HOSTS value in the form the configuration
     * keeps, "host=origin" with the host normalised; [] for null. The value
     * must be valid.
     *
     * @param string $defaultOrigin SOURCE_ORIGIN without trailing slash
     *
     * @return list<string>
     */
    public static function hostEntries(?string $raw, string $defaultOrigin): array
    {
        $entries = [];
        foreach (HostAllowlist::parse(self::entryList($raw), $defaultOrigin) ?? [] as $host => $origin) {
            $entries[] = $host . '=' . $origin;
        }

        return $entries;
    }

    /**
     * The canonical form of each range of a TRUSTED_PROXIES value
     * ("192.0.2.0/24"); [] for null. The value must be valid.
     *
     * @throws InvalidArgumentException when an entry is not an address or a CIDR block
     *
     * @return list<string>
     */
    public static function rangeList(?string $raw): array
    {
        return array_map('strval', IpRange::listFromString((string) $raw));
    }

    /**
     * The trimmed, non-empty names of a QUERY_STRING_ALLOWLIST value, in
     * their letter case; the literal "none" (any case) is the empty list.
     *
     * @return list<string>
     */
    public static function parameterList(string $raw): array
    {
        if (strtolower(trim($raw)) === self::NO_PARAMETER) {
            return [];
        }
        $names = [];
        foreach (explode(',', $raw) as $token) {
            $token = trim($token);
            if ($token !== '') {
                $names[] = $token;
            }
        }

        return $names;
    }

    /**
     * Lower-case, trimmed, non-empty tokens of a comma-separated list. The
     * token "0" is dropped, as 2.x did.
     *
     * @return list<string>
     */
    public static function extensionList(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $extensions = [];
        foreach (explode(',', $raw) as $token) {
            $token = trim(strtolower($token));
            if ($token !== '' && $token !== '0') {
                $extensions[] = $token;
            }
        }

        return $extensions;
    }

    /**
     * Whether date_default_timezone_set() accepts $timezone: a time zone
     * identifier, in any letter case.
     */
    private static function isTimezone(string $timezone): bool
    {
        $identifiers = array_map('strtolower', \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC));

        return in_array(strtolower($timezone), $identifiers, true);
    }

    /**
     * true for "true" and "1", false for "false" and "0" (case-insensitive),
     * null for anything else.
     */
    public static function booleanOf(string $raw): ?bool
    {
        $raw = strtolower($raw);
        if ($raw === 'true' || $raw === '1') {
            return true;
        }
        if ($raw === 'false' || $raw === '0') {
            return false;
        }

        return null;
    }
}
