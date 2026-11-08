<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Observability;

use Mfonte\ProxyCdn\Origin\OriginFetcher;
use Mfonte\ProxyCdn\Origin\OriginRequest;
use Mfonte\ProxyCdn\Origin\OriginResponse;

/**
 * An OriginFetcher that counts the outcome of every fetch of the one it
 * wraps in Metrics (proxy_cdn_origin_fetches_total), and changes nothing
 * else.
 */
final class MeteredOriginFetcher implements OriginFetcher
{
    /**
     * @var OriginFetcher
     */
    private $origin;

    /**
     * @var Metrics
     */
    private $metrics;

    public function __construct(OriginFetcher $origin, Metrics $metrics)
    {
        $this->origin = $origin;
        $this->metrics = $metrics;
    }

    public function fetch(OriginRequest $request): OriginResponse
    {
        $response = $this->origin->fetch($request);
        if ($response->isSuccessful()) {
            $outcome = 'success';
        } elseif ($response->isNotModified()) {
            $outcome = 'not_modified';
        } else {
            $outcome = $response->getFailureReason() ?? OriginResponse::FAILURE_OTHER;
        }
        $this->metrics->recordOriginFetch($outcome);

        return $response;
    }
}
