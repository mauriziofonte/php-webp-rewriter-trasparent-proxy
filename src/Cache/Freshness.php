<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cache;

/**
 * What the headers of an origin response say about caching it (RFC 9111),
 * as a shared cache reads them (finding P-15: 2.x ignored every one).
 *
 * - "no-store" and "private" forbid storing the response at all;
 * - the lifetime is "s-maxage", else "max-age", else "Expires" minus
 *   "Date", minus the "Age" the response already has; "no-cache" (without
 *   field names) makes it 0: stored, but revalidated before each use;
 *   without any of them the lifetime is unknown (null), and the entry is
 *   kept until it is purged, as in 2.x;
 * - "stale-while-revalidate" and "stale-if-error" extend the use of an
 *   expired entry; "must-revalidate", "proxy-revalidate", "s-maxage"
 *   (which implies proxy-revalidate, RFC 9111 5.2.2.10) and "no-cache"
 *   without field names (no use without a successful validation, 5.2.2.4)
 *   forbid it.
 *
 * Immutable.
 */
final class Freshness
{
    /**
     * @var bool
     */
    private $storable;

    /**
     * @var bool
     */
    private $noStore = false;

    /**
     * @var int|null
     */
    private $lifetime;

    /**
     * @var int|null
     */
    private $staleWhileRevalidate;

    /**
     * @var int|null
     */
    private $staleIfError;

    /**
     * @var bool
     */
    private $mustRevalidate;

    private function __construct(bool $storable, ?int $lifetime, ?int $staleWhileRevalidate, ?int $staleIfError, bool $mustRevalidate)
    {
        $this->storable = $storable;
        $this->lifetime = $lifetime;
        $this->staleWhileRevalidate = $staleWhileRevalidate;
        $this->staleIfError = $staleIfError;
        $this->mustRevalidate = $mustRevalidate;
    }

    /**
     * @param array<string, string> $headers the response headers, keyed by lower-case name
     * @param int                   $now     Unix time of the response
     */
    public static function fromHeaders(array $headers, int $now): self
    {
        $directives = self::directives($headers['cache-control'] ?? '');
        $noStore = isset($directives['no-store']);
        $storable = !$noStore && !isset($directives['private']);
        $noCache = isset($directives['no-cache']) && $directives['no-cache'] === '';
        $mustRevalidate = $noCache
            || isset($directives['must-revalidate'])
            || isset($directives['proxy-revalidate'])
            || self::seconds($directives, 's-maxage') !== null;

        $lifetime = self::seconds($directives, 's-maxage') ?? self::seconds($directives, 'max-age');
        if ($lifetime === null && isset($headers['expires'])) {
            $expires = strtotime($headers['expires']);
            $date = isset($headers['date']) ? strtotime($headers['date']) : false;
            // An invalid Expires, "0" included, means already expired.
            $lifetime = $expires === false ? 0 : max(0, $expires - ($date === false ? $now : $date));
        }
        if ($noCache) {
            $lifetime = 0;
        }
        if ($lifetime !== null && isset($headers['age']) && preg_match('/\A\s*([0-9]{1,10})\s*\z/', $headers['age'], $match) === 1) {
            $lifetime = max(0, $lifetime - (int) $match[1]);
        }

        $freshness = new self(
            $storable,
            $lifetime,
            self::seconds($directives, 'stale-while-revalidate'),
            self::seconds($directives, 'stale-if-error'),
            $mustRevalidate
        );
        $freshness->noStore = $noStore;

        return $freshness;
    }

    /**
     * Whether a shared cache may store the response: neither "no-store"
     * nor "private".
     */
    public function isStorable(): bool
    {
        return $this->storable;
    }

    /**
     * Whether the origin said "no-store" (rather than "private" only).
     */
    public function isNoStore(): bool
    {
        return $this->noStore;
    }

    /**
     * Seconds the response stays fresh; null when the origin does not say.
     */
    public function getLifetime(): ?int
    {
        return $this->lifetime;
    }

    /**
     * The "stale-while-revalidate" of the origin, in seconds; null when absent.
     */
    public function getStaleWhileRevalidate(): ?int
    {
        return $this->staleWhileRevalidate;
    }

    /**
     * The "stale-if-error" of the origin, in seconds; null when absent.
     */
    public function getStaleIfError(): ?int
    {
        return $this->staleIfError;
    }

    /**
     * Whether an expired entry must never be served without a successful
     * revalidation: must-revalidate, proxy-revalidate, s-maxage, no-cache.
     */
    public function mustRevalidate(): bool
    {
        return $this->mustRevalidate;
    }

    /**
     * The directives of a Cache-Control value, by lower-case name, with
     * their unquoted argument ("" for none).
     *
     * @return array<string, string>
     */
    private static function directives(string $cacheControl): array
    {
        $directives = [];
        preg_match_all('/(?:^|,)\s*([!#$%&\'*+.^_`|~0-9A-Za-z-]+)\s*(?:=\s*("(?:[^"\\\\]|\\\\.)*"|[^,]*))?/', $cacheControl, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            $value = trim($match[2] ?? '');
            if (strlen($value) >= 2 && $value[0] === '"') {
                $value = stripcslashes(substr($value, 1, -1));
            }
            // The first occurrence wins, as RFC 9111 recommends for duplicates.
            if (!isset($directives[$name])) {
                $directives[$name] = $value;
            }
        }

        return $directives;
    }

    /**
     * The delta-seconds argument of a directive; null when absent or
     * invalid. Values beyond 2^31 count as 2^31 (RFC 9111, 1.2.2).
     *
     * @param array<string, string> $directives
     */
    private static function seconds(array $directives, string $name): ?int
    {
        if (!isset($directives[$name]) || preg_match('/\A[0-9]+\z/', $directives[$name]) !== 1) {
            return null;
        }

        return strlen($directives[$name]) > 10 ? 2147483648 : min(2147483648, (int) $directives[$name]);
    }
}
