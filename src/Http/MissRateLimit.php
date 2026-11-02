<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Limits\RateLimiter;
use Mfonte\ProxyCdn\Observability\Metrics;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The miss rate limit (MISS_RATE_LIMIT, finding S-5): the origin fetches
 * one client may cause, per minute, with MISS_RATE_LIMIT_BURST at once.
 * Hits are never limited, and neither are the requests that wait for the
 * fetch of another one (RequestCoalescer): only a request that is about to
 * contact the origin takes a token. Above the limit the answer is 429 with
 * Retry-After.
 *
 * The client is its address as TRUSTED_PROXIES reads it (the
 * X-Forwarded-For of a trusted proxy, otherwise REMOTE_ADDR); an IPv6
 * client is its /64 network, the block one subscriber usually holds. Its
 * bucket is keyed by a hash of the address, never by the address itself.
 * When the buckets cannot be updated (a refused var/ratelimit, reported by
 * check-env, or an I/O error), the miss is let through, a warning is
 * logged and proxy_cdn_miss_rate_limit_unavailable_total counts it: the
 * limit protects the origin, it never decides who may read a public asset.
 */
final class MissRateLimit implements OriginFetchGuard
{
    /**
     * @var RateLimiter
     */
    private $limiter;

    /**
     * @var TrustedProxies
     */
    private $trustedProxies;

    /**
     * @var Metrics
     */
    private $metrics;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(RateLimiter $limiter, TrustedProxies $trustedProxies, Metrics $metrics, LoggerInterface $logger)
    {
        $this->limiter = $limiter;
        $this->trustedProxies = $trustedProxies;
        $this->metrics = $metrics;
        $this->logger = $logger;
    }

    public function beforeFetch(Request $request, string $path): ?Response
    {
        $client = $this->trustedProxies->getClientAddress($request);
        $wait = $this->limiter->consume(self::keyOf($client));
        if ($wait === null) {
            $this->metrics->recordRateLimitUnavailable();
            $this->logger->warning('miss rate limit unavailable: the buckets cannot be updated');

            return null;
        }
        if ($wait === 0) {
            return null;
        }
        $this->metrics->recordRateLimited();
        $this->logger->notice('miss rate limited', ['path' => $path, 'client' => $client, 'retry_after' => $wait]);

        return new Response("Too many requests for uncached assets.\nRetry in " . $wait . " seconds.\n", 429, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Retry-After' => (string) $wait,
        ]);
    }

    /**
     * The bucket key of a client address: 32 hexadecimal digits of the
     * SHA-256 of the address, or of its /64 network for IPv6; one shared
     * key when the address is unknown or invalid.
     */
    public static function keyOf(?string $address): string
    {
        $packed = $address === null ? false : @inet_pton($address);
        if (!is_string($packed)) {
            $packed = '';
        } elseif (strlen($packed) === 16) {
            $packed = substr($packed, 0, 8) . str_repeat("\0", 8);
        }

        return substr(hash('sha256', 'proxy-cdn-miss:' . $packed), 0, 32);
    }
}
