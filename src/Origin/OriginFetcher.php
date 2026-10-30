<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * Fetches one resource from the origin: the only way ProxyKernel talks to
 * the origin. Implementations never throw for transport or HTTP failures;
 * they describe them in the OriginResponse.
 */
interface OriginFetcher
{
    public function fetch(OriginRequest $request): OriginResponse;
}
