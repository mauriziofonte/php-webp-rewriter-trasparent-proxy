<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Observability;

use Symfony\Component\HttpFoundation\Request;

/**
 * Decides whether a request for /metrics carries one of METRICS_KEYS, as
 * "Authorization: Bearer <key>", the way the conversion API protects its
 * own /metrics with its METRICS_KEYS: the keys are kept apart from the
 * purge keys, so that a Prometheus scraper holds no key that can empty
 * the cache.
 *
 * A key is 16 or more printable ASCII characters without spaces or commas,
 * or its SHA-256 digest written "sha256:<64 hex digits>" (DIGEST_PREFIX),
 * so that the configuration need not hold the key itself. Keys and digests
 * are compared with hash_equals(). Without keys every request is refused.
 * Immutable.
 */
final class MetricsAuthenticator
{
    /**
     * The prefix of a key given as its SHA-256 digest.
     */
    public const DIGEST_PREFIX = 'sha256:';

    /**
     * The shortest key, in bytes.
     */
    public const MIN_KEY_BYTES = 16;

    /**
     * @var list<string> the SHA-256 digest of each key, in lower-case hex
     */
    private $digests;

    /**
     * @param list<string> $keys valid METRICS_KEYS entries (see parseKeys())
     */
    public function __construct(array $keys)
    {
        $this->digests = self::parseKeys($keys) ?? [];
    }

    /**
     * The SHA-256 digest of each entry; null when an entry is neither a key
     * of MIN_KEY_BYTES or more printable ASCII characters without spaces
     * or commas, nor "sha256:" followed by 64 hexadecimal digits.
     *
     * @param list<string> $entries
     *
     * @return list<string>|null
     */
    public static function parseKeys(array $entries): ?array
    {
        $digests = [];
        foreach ($entries as $entry) {
            if (strncasecmp($entry, self::DIGEST_PREFIX, strlen(self::DIGEST_PREFIX)) === 0) {
                $digest = strtolower((string) substr($entry, strlen(self::DIGEST_PREFIX)));
                if (preg_match('/\A[0-9a-f]{64}\z/', $digest) !== 1) {
                    return null;
                }
                $digests[] = $digest;
                continue;
            }
            if (strlen($entry) < self::MIN_KEY_BYTES || preg_match('/\A[\x21-\x2B\x2D-\x7E]+\z/', $entry) !== 1) {
                return null;
            }
            $digests[] = hash('sha256', $entry);
        }

        return $digests;
    }

    /**
     * Whether no key is configured, so that /metrics is not served.
     */
    public function isDisabled(): bool
    {
        return $this->digests === [];
    }

    /**
     * Whether $request carries one of the keys as a Bearer token.
     */
    public function authenticate(Request $request): bool
    {
        $authorization = (string) $request->headers->get('Authorization', '');
        if (preg_match('/\ABearer +(\S+)\z/i', trim($authorization), $match) !== 1) {
            return false;
        }
        $digest = hash('sha256', $match[1]);
        $accepted = false;
        foreach ($this->digests as $known) {
            // Every digest is compared, so that the time does not tell which one matched.
            $accepted = hash_equals($known, $digest) || $accepted;
        }

        return $accepted;
    }
}
