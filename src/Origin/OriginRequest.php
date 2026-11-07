<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * A GET request for the origin: the absolute URL and the headers the proxy
 * adds for this request. Immutable.
 */
final class OriginRequest
{
    /**
     * @var string
     */
    private $url;

    /**
     * @var array<string, string>
     */
    private $headers;

    /**
     * @param string                $url     absolute http or https URL
     * @param array<string, string> $headers header values keyed by header name
     */
    public function __construct(string $url, array $headers = [])
    {
        $this->url = $url;
        $this->headers = $headers;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
