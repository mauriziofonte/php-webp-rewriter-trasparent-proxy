<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Origin;

use Mfonte\ProxyCdn\Origin\OriginFetcher;
use Mfonte\ProxyCdn\Origin\OriginRequest;
use Mfonte\ProxyCdn\Origin\OriginResponse;

/**
 * An in-memory origin: answers the responses registered for each URL, 404
 * for any other URL, and records every request.
 */
final class FakeOriginFetcher implements OriginFetcher
{
    /**
     * @var array<string, OriginResponse>
     */
    private $responses = [];

    /**
     * @var list<OriginRequest>
     */
    private $requests = [];

    /**
     * Makes $url answer 200 with $body and $headers.
     *
     * @param array<string, string> $headers keyed by lower-case name
     */
    public function serve(string $url, string $body, array $headers = []): void
    {
        $this->responses[$url] = OriginResponse::success($headers, $body);
    }

    /**
     * Makes $url answer $response.
     */
    public function respond(string $url, OriginResponse $response): void
    {
        $this->responses[$url] = $response;
    }

    public function fetch(OriginRequest $request): OriginResponse
    {
        $this->requests[] = $request;

        return $this->responses[$request->getUrl()] ?? OriginResponse::failure(OriginResponse::FAILURE_NOT_FOUND, 404);
    }

    /**
     * @return list<OriginRequest>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    /**
     * The URLs requested so far, oldest first.
     *
     * @return list<string>
     */
    public function getRequestedUrls(): array
    {
        $urls = [];
        foreach ($this->requests as $request) {
            $urls[] = $request->getUrl();
        }

        return $urls;
    }
}
