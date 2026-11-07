<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cache;

/**
 * The query string of a request once QueryStringPolicy has kept the
 * parameters that matter: the form forwarded to the origin and the
 * canonical form that keys the cache. Immutable.
 *
 * The forwarded form keeps the bytes and the order the client sent
 * (finding P-9: 2.x forwarded its canonical form, which turned "a.b=1"
 * into "a_b=1" and "v" into "v="); the canonical form only sorts the
 * parameters, so that equivalent query strings share an entry.
 */
final class QueryString
{
    /**
     * @var string
     */
    private $forwarded;

    /**
     * @var string
     */
    private $canonical;

    /**
     * @var list<string>
     */
    private $ignored;

    /**
     * @param string       $forwarded the kept parameters as the client sent them, without "?"
     * @param string       $canonical the kept parameters in canonical order, without "?"
     * @param list<string> $ignored   decoded names of the parameters left out of both
     */
    public function __construct(string $forwarded, string $canonical, array $ignored)
    {
        $this->forwarded = $forwarded;
        $this->canonical = $canonical;
        $this->ignored = $ignored;
    }

    /**
     * The query string sent to the origin, without "?"; empty for none.
     */
    public function getForwarded(): string
    {
        return $this->forwarded;
    }

    /**
     * The query string that keys the cache entry, without "?"; empty when
     * the entry is keyed on the path alone.
     */
    public function getCanonical(): string
    {
        return $this->canonical;
    }

    /**
     * The decoded names of the parameters the policy left out, in the order
     * of the request.
     *
     * @return list<string>
     */
    public function getIgnored(): array
    {
        return $this->ignored;
    }
}
