<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cache;

use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Exception\InvalidArgumentException;

/**
 * Decides which query parameters key the cache and reach the origin
 * (finding P-5: in 2.x every distinct query string created its own cache
 * entry and origin fetch, so "?random=<n>" filled the disk and bypassed
 * the cache).
 *
 * Only the parameters of the allowlist (QUERY_STRING_ALLOWLIST, compared
 * on their decoded name, letter case included) are kept; "*" keeps every
 * parameter, as 2.x did. The others are, by QUERY_STRING_MODE:
 *
 * - "ignore" (MODE_IGNORE, the default): left out of the cache key and of
 *   the origin request, so "?utm_source=x" is the entry of the path; an
 *   ignored parameter never reaches the origin, so it cannot change the
 *   body cached for the others (cache key poisoning);
 * - "reject" (MODE_REJECT): the request is refused.
 *
 * The control parameters of 2.x ("purge", "purge_all") are always left
 * out. A kept parameter is forwarded with the bytes the client sent, in
 * its order; the cache key sorts the kept parameters by name, keeping the
 * order of repeated names, and writes percent-escapes in upper case. No
 * other normalisation is made, so two query strings share an entry only
 * when every origin reads them alike: "a+b" and "a%20b" do not.
 *
 * Immutable.
 */
final class QueryStringPolicy
{
    public const MODE_IGNORE = 'ignore';

    public const MODE_REJECT = 'reject';

    /**
     * The allowlist entry that keeps every parameter.
     */
    public const ANY_PARAMETER = '*';

    /**
     * The default allowlist: the usual cache-busting parameters.
     */
    public const DEFAULT_ALLOWLIST = ['v', 'ver', 'version'];

    /**
     * Parameters that controlled the 2.x proxy and never key an entry.
     */
    public const CONTROL_PARAMETERS = ['purge', 'purge_all'];

    /**
     * @var list<string>
     */
    private $allowlist;

    /**
     * @var string
     */
    private $mode;

    /**
     * @param list<string> $allowlist decoded parameter names; ["*"] keeps every parameter
     * @param string       $mode      MODE_IGNORE or MODE_REJECT
     *
     * @throws InvalidArgumentException when the mode is unknown
     */
    public function __construct(array $allowlist = self::DEFAULT_ALLOWLIST, string $mode = self::MODE_IGNORE)
    {
        if ($mode !== self::MODE_IGNORE && $mode !== self::MODE_REJECT) {
            throw new InvalidArgumentException('The query string mode must be "ignore" or "reject"');
        }
        $this->allowlist = $allowlist;
        $this->mode = $mode;
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->getQueryStringAllowlist(), $config->getQueryStringMode());
    }

    /**
     * Whether every parameter is kept ("*").
     */
    public function keepsEveryParameter(): bool
    {
        return in_array(self::ANY_PARAMETER, $this->allowlist, true);
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * The query string of a request with the policy applied; null when it
     * holds a parameter outside the allowlist and the mode is MODE_REJECT.
     *
     * @param string $rawQuery the query string as the client sent it, without "?"
     */
    public function apply(string $rawQuery): ?QueryString
    {
        $kept = [];
        $ignored = [];
        foreach (explode('&', $rawQuery) as $position => $pair) {
            if ($pair === '') {
                continue;
            }
            $name = self::nameOf($pair);
            if (in_array($name, self::CONTROL_PARAMETERS, true)) {
                continue;
            }
            if (!$this->keepsEveryParameter() && !in_array($name, $this->allowlist, true)) {
                if ($this->mode === self::MODE_REJECT) {
                    return null;
                }
                $ignored[] = $name;
                continue;
            }
            $kept[] = [self::normaliseEscapes($pair), $position, $pair];
        }

        $forwarded = [];
        foreach ($kept as $entry) {
            $forwarded[] = $entry[2];
        }
        // usort() is not stable before PHP 8.0: the position breaks ties.
        usort($kept, static function (array $left, array $right): int {
            $byName = strcmp(self::rawNameOf($left[0]), self::rawNameOf($right[0]));

            return $byName !== 0 ? $byName : $left[1] <=> $right[1];
        });
        $canonical = [];
        foreach ($kept as $entry) {
            $canonical[] = $entry[0];
        }

        return new QueryString(implode('&', $forwarded), implode('&', $canonical), $ignored);
    }

    /**
     * Whether $name, decoded, is a parameter name the allowlist can hold:
     * not empty, printable ASCII without whitespace, "&", "=", "#" or ",".
     */
    public static function isParameterName(string $name): bool
    {
        return preg_match('/\A[\x21-\x7E]+\z/', $name) === 1 && strpbrk($name, '&=#,') === false;
    }

    /**
     * The decoded name of a "name=value" or bare "name" pair.
     */
    private static function nameOf(string $pair): string
    {
        return rawurldecode(self::rawNameOf($pair));
    }

    private static function rawNameOf(string $pair): string
    {
        $equals = strpos($pair, '=');

        return $equals === false ? $pair : (string) substr($pair, 0, $equals);
    }

    /**
     * $pair with the hexadecimal digits of its escapes in upper case.
     */
    private static function normaliseEscapes(string $pair): string
    {
        return (string) preg_replace_callback('/%[0-9a-fA-F]{2}/', static function (array $match): string {
            return strtoupper($match[0]);
        }, $pair);
    }
}
