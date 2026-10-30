<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * An HttpClient for tests: it answers from the responses registered for
 * each URL, without any network, and records every request it receives.
 *
 * The answers go through ResponseCollector like those of the real
 * transports, so the HttpClient contract holds: the body is written to a
 * temporary file, a body over the maximum size is refused, HEAD has no
 * body, redirects are followed when the options say so. A conditional
 * request whose If-None-Match or If-Modified-Since matches the registered
 * ETag or Last-Modified is answered 304. A URL without registration
 * answers 404.
 */
final class MockTransport implements HttpClient
{
    /**
     * @var HttpClientOptions
     */
    private $options;

    /**
     * @var array<string, array{status: int, headers: array<string, string>, body: string}>
     */
    private $responses = [];

    /**
     * @var array<string, array{outcome: string, code: int, error: string}>
     */
    private $failures = [];

    /**
     * @var list<HttpRequest>
     */
    private $requests = [];

    public function __construct(?HttpClientOptions $options = null)
    {
        $this->options = $options ?? new HttpClientOptions();
    }

    /**
     * Makes every later request for $url answer $status with $headers and
     * $body.
     *
     * @param array<string, string> $headers response fields, keyed by name in any case
     */
    public function respond(string $url, int $status, array $headers = [], string $body = ''): void
    {
        $normalised = [];
        foreach ($headers as $name => $value) {
            $normalised[strtolower((string) $name)] = $value;
        }
        $this->responses[$url] = ['status' => $status, 'headers' => $normalised, 'body' => $body];
        unset($this->failures[$url]);
    }

    /**
     * Makes every later request for $url fail with a transport failure.
     *
     * @param string $outcome   one of the HttpResponse::OUTCOME_* constants of a transport failure
     * @param int    $errorCode the transport error number to report
     * @param string $error     the transport error message to report
     */
    public function fail(string $url, string $outcome, int $errorCode = 0, string $error = ''): void
    {
        $this->failures[$url] = ['outcome' => $outcome, 'code' => $errorCode, 'error' => $error];
        unset($this->responses[$url]);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $collector = new ResponseCollector($request, $this->options, true);
        $url = $request->getUrl();

        for ($redirects = 0; ; ++$redirects) {
            if (isset($this->failures[$url])) {
                $failure = $this->failures[$url];

                return $collector->finish($failure['outcome'], $failure['code'], $failure['error'], $url, 0.0);
            }

            $response = $this->responses[$url] ?? ['status' => 404, 'headers' => [], 'body' => ''];
            if ($response['status'] === 200 && self::isNotModified($request, $response['headers'])) {
                $response = ['status' => 304, 'headers' => array_intersect_key($response['headers'], ['etag' => true, 'last-modified' => true]), 'body' => ''];
            }
            if (!$this->feed($collector, $request, $response)) {
                return $collector->finish(HttpResponse::OUTCOME_OTHER, 0, 'Aborted', $url, 0.0);
            }
            if (!$collector->isFollowingRedirect()) {
                return $collector->finish(null, 0, '', $url, 0.0);
            }

            $target = RedirectTarget::resolve($url, (string) $collector->getHeader('location'));
            if ($target === null) {
                return $collector->finish(HttpResponse::OUTCOME_OTHER, 0, 'Redirect to an unsupported location', $url, 0.0);
            }
            if ($redirects >= $this->options->getMaxRedirects()) {
                return $collector->finish(HttpResponse::OUTCOME_REDIRECT, 0, 'Too many redirects', $url, 0.0);
            }
            $url = $target;
        }
    }

    /**
     * Every request received so far, oldest first.
     *
     * @return list<HttpRequest>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    /**
     * @param array{status: int, headers: array<string, string>, body: string} $response
     */
    private function feed(ResponseCollector $collector, HttpRequest $request, array $response): bool
    {
        if (!$collector->headerLine('HTTP/1.1 ' . $response['status'] . ' Mock')) {
            return false;
        }
        foreach ($response['headers'] as $name => $value) {
            $collector->headerLine($name . ': ' . $value);
        }
        if (!isset($response['headers']['content-length'])) {
            $collector->headerLine('content-length: ' . strlen($response['body']));
        }
        if (!$collector->headerLine('')) {
            return false;
        }
        if ($request->getMethod() === HttpRequest::METHOD_HEAD || !$collector->isFinal()) {
            return true;
        }

        $length = strlen($response['body']);
        for ($offset = 0; $offset < $length; $offset += 65536) {
            if (!$collector->bodyChunk(substr($response['body'], $offset, 65536))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a conditional request matches the validators of a registered
     * 200 response.
     *
     * @param array<string, string> $headers
     */
    private static function isNotModified(HttpRequest $request, array $headers): bool
    {
        $ifNoneMatch = $request->getHeader('if-none-match');
        if ($ifNoneMatch !== null && isset($headers['etag'])) {
            $etag = preg_replace('#\AW/#', '', $headers['etag']);
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '*' || preg_replace('#\AW/#', '', $candidate) === $etag) {
                    return true;
                }
            }

            return false;
        }

        $ifModifiedSince = $request->getHeader('if-modified-since');
        if ($ifModifiedSince !== null && isset($headers['last-modified'])) {
            $since = strtotime($ifModifiedSince);
            $modified = strtotime($headers['last-modified']);

            return $since !== false && $modified !== false && $modified <= $since;
        }

        return false;
    }
}
