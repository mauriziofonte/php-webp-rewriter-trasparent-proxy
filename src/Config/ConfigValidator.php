<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Config;

use Mfonte\ProxyCdn\Origin\HttpClientOptions;

/**
 * Validates the raw configuration values, as read from the environment and
 * the .env file. ConfigLoader runs it before the proxy starts and the
 * check-env command prints its errors and warnings, so both apply the same
 * rules (plan section 4).
 *
 * Raw values are strings, or null for a key that is not set; the empty
 * string and the literal "null" have already been turned into null by
 * ConfigLoader::normalise(). Host checks such as the presence of the cwebp
 * binary are not configuration and stay in check-env.
 */
final class ConfigValidator
{
    /**
     * Extensions whose responses may depend on the client's session.
     */
    private const SENSITIVE_EXTENSIONS = ['html', 'htm', 'php', 'xml', 'json'];

    /**
     * The largest UPSTREAM_MAX_SIZE, 256 MiB: until bodies are streamed to
     * the cache (PRX-7), the kernel holds an origin body in memory as a
     * string (finding 4 of the PRX-4 review).
     */
    public const MAX_UPSTREAM_MAX_SIZE = 268435456;

    /**
     * Inclusive ranges of the integer keys.
     */
    private const INTEGER_RANGES = [
        'CACHE_EXPIRY' => [0, PHP_INT_MAX],
        'NEGATIVE_CACHE_TTL' => [0, 86400],
        'NETWORK_ERROR_TTL' => [0, 3600],
        'UPSTREAM_TIMEOUT' => [1, 300],
        'UPSTREAM_CONNECT_TIMEOUT' => [1, 60],
        'UPSTREAM_MAX_SIZE' => [1, self::MAX_UPSTREAM_MAX_SIZE],
    ];

    private const BOOLEAN_KEYS = ['DEBUG', 'UPSTREAM_VERIFY_TLS', 'UPSTREAM_FOLLOW_REDIRECTS'];

    private const CONVERSION_MODES = [Config::CONVERSION_NATIVE, Config::CONVERSION_API, Config::CONVERSION_NONE];

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

        $server = $values['SOURCE_ORIGIN_SERVER'] ?? null;
        if ($server !== null && HttpClientOptions::parseAddress($server) === null) {
            $errors[] = 'SOURCE_ORIGIN_SERVER must be "address", "address:port" or "[IPv6 address]:port" (got: ' . var_export($server, true) . ')';
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

        $mode = $values['IMAGE_CONVERSION_MODE'] ?? Config::CONVERSION_NONE;
        if (!in_array($mode, self::CONVERSION_MODES, true)) {
            $errors[] = 'IMAGE_CONVERSION_MODE must be one of: native, api, none (got: ' . var_export($mode, true) . ')';
        }
        if ($mode === Config::CONVERSION_API) {
            if (($values['WEBP_API_SERVER'] ?? null) === null) {
                $errors[] = 'WEBP_API_SERVER must be set for API mode';
            }
            if (($values['WEBP_API_KEY'] ?? null) === null) {
                $errors[] = 'WEBP_API_KEY must be set for API mode';
            }
        }

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
            $warnings[] = "NOTE: SOURCE_ORIGIN_SERVER is set. cURL will bypass DNS and\n"
                . "talk directly to the pinned IP:port. This intentionally bypasses any\n"
                . 'CDN / WAF that may sit in front of the origin hostname.';
        }

        if (self::booleanOf($values['UPSTREAM_VERIFY_TLS'] ?? 'false') !== true) {
            $warnings[] = "NOTE: UPSTREAM_VERIFY_TLS is off. The origin TLS certificate\n"
                . 'will NOT be validated. Acceptable on trusted networks only.';
        }

        return new ConfigValidation($errors, $warnings);
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
