<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * The state of one CurlTransport request across the redirects it
 * follows, each of them a transfer on the same easy handle.
 *
 * @internal used by CurlTransport and MultiClient
 */
final class CurlTransfer
{
    /**
     * @var CurlHandleOrResource
     */
    public $handle;

    /**
     * The request of the current transfer.
     *
     * @var HttpRequest
     */
    public $request;

    /**
     * @var ResponseCollector
     */
    public $collector;

    /**
     * The URL of the first request, whose host and port the connect-to pin
     * applies to.
     *
     * @var string
     */
    public $originUrl;

    /**
     * microtime() of the first transfer.
     *
     * @var float
     */
    public $startedAt;

    /**
     * Redirects followed so far.
     *
     * @var int
     */
    public $redirects = 0;

    /**
     * @param CurlHandleOrResource $handle
     */
    public function __construct($handle, HttpRequest $request, ResponseCollector $collector)
    {
        $this->handle = $handle;
        $this->request = $request;
        $this->collector = $collector;
        $this->originUrl = $request->getUrl();
        $this->startedAt = microtime(true);
    }
}
