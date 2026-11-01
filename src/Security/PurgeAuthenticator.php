<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Security;

use Mfonte\ProxyCdn\Config\Config;
use Symfony\Component\HttpFoundation\Request;

/**
 * Decides whether a PURGE request carries a purge credential for the
 * origin it targets (risk L-7: 2.x purged for anyone who added
 * "?purge=1" to a URL).
 *
 * PURGE_KEYS lists "scope=secret" pairs, separated by commas. The scope is
 * the host name of an origin (the host of SOURCE_ORIGIN), or "*" for every
 * origin; a key opens the origins of its scope only, so that the tenants
 * of a multi-origin proxy cannot purge each other. A request proves one
 * secret of the scope in one of two ways:
 *
 * - "Authorization: Bearer <secret>", for clients on a trusted channel;
 * - an HMAC that never sends the secret: "X-Purge-Timestamp: <unix
 *   seconds>" and "X-Purge-Signature: <hex HMAC-SHA256>" of the string
 *   "PURGE\n<host>\n<request target>\n<timestamp>" under the secret, where
 *   <host> is the Host header in lower case and <request target> the path
 *   and query string as sent. The timestamp must be within
 *   MAX_CLOCK_SKEW seconds of the proxy clock; within that window a
 *   signature can be replayed, which only repeats the same purge.
 *
 * Secrets are compared with hash_equals(). Immutable.
 */
final class PurgeAuthenticator
{
    public const SCHEME_BEARER = 'bearer';

    public const SCHEME_HMAC = 'hmac';

    /**
     * The scope that covers every origin.
     */
    public const ANY_ORIGIN = '*';

    /**
     * Seconds the signed timestamp may differ from the proxy clock.
     */
    public const MAX_CLOCK_SKEW = 300;

    /**
     * The shortest secret, in bytes.
     */
    public const MIN_SECRET_BYTES = 16;

    public const TIMESTAMP_HEADER = 'X-Purge-Timestamp';

    public const SIGNATURE_HEADER = 'X-Purge-Signature';

    /**
     * @var list<array{string, string}> scope and secret of each key
     */
    private $keys;

    /**
     * @param list<array{string, string}> $keys scope (a lower-case host name or "*") and secret of each key
     */
    public function __construct(array $keys)
    {
        $this->keys = $keys;
    }

    public static function fromConfig(Config $config): self
    {
        // ConfigValidator refuses malformed keys; none is left for a key that slipped through.
        return new self(self::parseKeys($config->getPurgeKeys()) ?? []);
    }

    /**
     * The scope and secret of each "scope=secret" entry, the scope in lower
     * case; null when an entry is malformed: no "=", a scope that is
     * neither "*" nor a host name, or a secret shorter than
     * MIN_SECRET_BYTES or with whitespace, a comma or a non-ASCII byte.
     *
     * @param list<string> $entries
     *
     * @return list<array{string, string}>|null
     */
    public static function parseKeys(array $entries): ?array
    {
        $keys = [];
        foreach ($entries as $entry) {
            $equals = strpos($entry, '=');
            if ($equals === false) {
                return null;
            }
            $scope = strtolower(trim(substr($entry, 0, $equals)));
            $secret = trim(substr($entry, $equals + 1));
            if (!self::isScope($scope) || strlen($secret) < self::MIN_SECRET_BYTES || preg_match('/\A[\x21-\x2B\x2D-\x7E]+\z/', $secret) !== 1) {
                return null;
            }
            $keys[] = [$scope, $secret];
        }

        return $keys;
    }

    /**
     * Whether no key is configured, so that every purge is refused.
     */
    public function isDisabled(): bool
    {
        return $this->keys === [];
    }

    /**
     * The scheme (SCHEME_BEARER or SCHEME_HMAC) by which $request proves a
     * key whose scope covers $originHost; null when it proves none.
     *
     * @param string $originHost the host name of the origin the request targets
     * @param int    $now        the current Unix time
     */
    public function authenticate(Request $request, string $originHost, int $now): ?string
    {
        $secrets = $this->secretsFor(strtolower($originHost));
        if ($secrets === []) {
            return null;
        }

        $authorization = (string) $request->headers->get('Authorization', '');
        if (preg_match('/\ABearer +(\S+)\z/i', trim($authorization), $match) === 1) {
            foreach ($secrets as $secret) {
                if (hash_equals($secret, $match[1])) {
                    return self::SCHEME_BEARER;
                }
            }

            return null;
        }

        $timestamp = trim((string) $request->headers->get(self::TIMESTAMP_HEADER, ''));
        $signature = strtolower(trim((string) $request->headers->get(self::SIGNATURE_HEADER, '')));
        if (preg_match('/\A[0-9]{1,12}\z/', $timestamp) !== 1 || preg_match('/\A[0-9a-f]{64}\z/', $signature) !== 1) {
            return null;
        }
        if (abs($now - (int) $timestamp) > self::MAX_CLOCK_SKEW) {
            return null;
        }
        $message = self::signedMessage(strtolower($request->getHttpHost()), $request->getRequestUri(), $timestamp);
        foreach ($secrets as $secret) {
            if (hash_equals(hash_hmac('sha256', $message, $secret), $signature)) {
                return self::SCHEME_HMAC;
            }
        }

        return null;
    }

    /**
     * The string a client signs: "PURGE\n<host>\n<request target>\n<timestamp>".
     *
     * @param string $host          the Host header, in lower case
     * @param string $requestTarget the path and query string, as sent
     * @param string $timestamp     the X-Purge-Timestamp value
     */
    public static function signedMessage(string $host, string $requestTarget, string $timestamp): string
    {
        return "PURGE\n" . $host . "\n" . $requestTarget . "\n" . $timestamp;
    }

    /**
     * Whether $scope is "*" or a host name (letters, digits, "-" and ".").
     */
    private static function isScope(string $scope): bool
    {
        return $scope === self::ANY_ORIGIN
            || preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/', $scope) === 1;
    }

    /**
     * @return list<string>
     */
    private function secretsFor(string $originHost): array
    {
        $secrets = [];
        foreach ($this->keys as $key) {
            if ($key[0] === self::ANY_ORIGIN || $key[0] === $originHost) {
                $secrets[] = $key[1];
            }
        }

        return $secrets;
    }
}
