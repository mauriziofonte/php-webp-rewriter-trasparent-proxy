<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * An HTTP client for origin fetches: CurlTransport, StreamTransport when
 * ext-curl is missing, MockTransport in tests (HttpClientFactory picks one).
 *
 * Every implementation honours the same contract, which the transport
 * contract tests check for each of them:
 *
 * - the body of a 2xx answer to a GET is streamed to a temporary file, never
 *   held in memory, and decoded when it was compressed for the transfer;
 * - a body larger than the maximum size is refused as soon as its
 *   Content-Length announces it, or aborted when the decoded bytes reach the
 *   limit, and no file is left behind;
 * - the body of any other answer is discarded;
 * - interim 1xx responses are skipped, redirects are followed only when the
 *   options say so, and only to http or https URLs;
 * - transport and HTTP failures never throw: they are outcomes of the
 *   returned HttpResponse.
 */
interface HttpClient
{
    /**
     * Sends $request and waits for the final response or the failure.
     */
    public function send(HttpRequest $request): HttpResponse;
}
