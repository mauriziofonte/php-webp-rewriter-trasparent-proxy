<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before ProxyKernel fetches a missing entry from the origin for a
 * client, on the miss path and for a signed resize URL: it may answer
 * instead (the miss rate limit), or act on the side (103 Early Hints).
 * Hits, revalidations and the requests that waited for another one's
 * fetch and share its answer never reach it; a request of a coalescing
 * window that is not coalesced (an answer that cannot be stored, a resize
 * the converter could not make) fetches, and does.
 */
interface OriginFetchGuard
{
    /**
     * @param string $path the decoded request path, the signature of a resize URL masked
     *
     * @return Response|null the response to send instead of fetching; null to fetch
     */
    public function beforeFetch(Request $request, string $path): ?Response;
}
