<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Observability\Metrics;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the Link header of EARLY_HINTS as a 103 Early Hints response before
 * a miss is fetched from the origin, so that a browser can preconnect or
 * preload while it waits (plan section 2: HTTP/2 push was removed from the
 * browsers; 103 is offered instead).
 *
 * PHP sends an interim response only where the server API supports it:
 * FrankenPHP provides headers_send(103). Under PHP-FPM and mod_php no
 * interim response can leave PHP, and this guard does nothing (check-env
 * notes it); browsers also act on 103 for navigations only, so the hints
 * matter for the documents the proxy serves (an HTML entry), not for its
 * images and scripts. The hints are sent for GET, never for HEAD, and
 * never in a response that already started. The Link value is EARLY_HINTS
 * as configured: it never names a path of the cache, a pending entry or a
 * variant that is not published.
 */
final class EarlyHints implements OriginFetchGuard
{
    /**
     * The function of the server API that sends the headers set so far as
     * an interim response of the given status (FrankenPHP).
     */
    public const SAPI_FUNCTION = 'headers_send';

    /**
     * @var string
     */
    private $links;

    /**
     * @var Metrics
     */
    private $metrics;

    /**
     * @var callable(string): bool
     */
    private $emitter;

    /**
     * @param string                        $links   the Link header value (EARLY_HINTS)
     * @param (callable(string): bool)|null $emitter sends a 103 with that Link value and tells whether it was sent;
     *                                               the server API's by default (emit())
     */
    public function __construct(string $links, Metrics $metrics, ?callable $emitter = null)
    {
        $this->links = $links;
        $this->metrics = $metrics;
        $this->emitter = $emitter ?? static function (string $links): bool {
            return self::emit($links);
        };
    }

    public function beforeFetch(Request $request, string $path): ?Response
    {
        if ($request->getRealMethod() === 'GET' && ($this->emitter)($this->links)) {
            $this->metrics->recordEarlyHints();
        }

        return null;
    }

    /**
     * Whether the server API can send a 103: it provides SAPI_FUNCTION.
     */
    public static function isSupported(): bool
    {
        return function_exists(self::SAPI_FUNCTION);
    }

    /**
     * Sends a 103 with $links as its Link header through the server API,
     * then removes the header, so that the final response does not repeat
     * it; false where the server API cannot, or once headers were sent.
     */
    public static function emit(string $links): bool
    {
        $send = self::SAPI_FUNCTION;
        if (!function_exists($send) || headers_sent()) {
            return false;
        }
        header('Link: ' . $links);
        $send(103);
        header_remove('Link');

        return true;
    }
}
