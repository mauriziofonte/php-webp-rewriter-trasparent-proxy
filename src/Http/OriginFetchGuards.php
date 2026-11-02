<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Limits\RateLimiter;
use Mfonte\ProxyCdn\Limits\SlotRateLimitStore;
use Mfonte\ProxyCdn\Observability\Metrics;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Several OriginFetchGuard in order: the first response wins, and the
 * guards after it do not run.
 */
final class OriginFetchGuards implements OriginFetchGuard
{
    /**
     * @var list<OriginFetchGuard>
     */
    private $guards;

    /**
     * @param list<OriginFetchGuard> $guards
     */
    public function __construct(array $guards)
    {
        $this->guards = $guards;
    }

    /**
     * The guards of the configuration: the miss rate limit when
     * MISS_RATE_LIMIT is above 0 (its buckets in var/ratelimit), then the
     * Early Hints when EARLY_HINTS is set; null when there is none, and
     * ProxyKernel fetches as without guard.
     *
     * @param string $stateDirectory the state directory of the proxy (var/ of the project)
     */
    public static function fromConfig(Config $config, string $stateDirectory, Metrics $metrics, LoggerInterface $logger): ?OriginFetchGuard
    {
        $operations = $config->getOperations();
        $guards = [];
        if ($operations->getMissRateLimit() > 0) {
            $guards[] = new MissRateLimit(
                new RateLimiter(
                    new SlotRateLimitStore(rtrim($stateDirectory, '/') . '/ratelimit'),
                    $operations->getMissRateLimit(),
                    $operations->getMissRateLimitBurst()
                ),
                new TrustedProxies($config->getTrustedProxies()),
                $metrics,
                $logger
            );
        }
        $hints = $operations->getEarlyHints();
        if ($hints !== null) {
            $guards[] = new EarlyHints($hints, $metrics);
        }
        if ($guards === []) {
            return null;
        }

        return count($guards) === 1 ? $guards[0] : new self($guards);
    }

    public function beforeFetch(Request $request, string $path): ?Response
    {
        foreach ($this->guards as $guard) {
            $response = $guard->beforeFetch($request, $path);
            if ($response !== null) {
                return $response;
            }
        }

        return null;
    }
}
