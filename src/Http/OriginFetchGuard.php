<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before ProxyKernel fetches a missing entry from the origin for a
 * client: it may answer instead (the miss rate limit), or act on the side
 * (103 Early Hints). Hits, revalidations and the requests that waited for
 * another one's fetch never reach it.
 */
interface OriginFetchGuard
{
    /**
     * @param string $path the decoded request path
     *
     * @return Response|null the response to send instead of fetching; null to fetch
     */
    public function beforeFetch(Request $request, string $path): ?Response;
}
