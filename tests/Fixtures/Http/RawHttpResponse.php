<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Http;

/**
 * A response read off the wire by RawHttpClient: status, headers exactly as
 * sent (names compared case-insensitively) and the full body received until
 * the server closed the connection, whatever Content-Length announced.
 */
final class RawHttpResponse
{
    /**
     * @var int
     */
    private $status;

    /**
     * @var array<string, list<string>> lower-case header name => values in order
     */
    private $headers;

    /**
     * @var string
     */
    private $body;

    /**
     * @param array<string, list<string>> $headers lower-case header name => values in order
     */
    public function __construct(int $status, array $headers, string $body)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Value of a header, the last one when it was sent several times, or null.
     */
    public function getHeader(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values === [] ? null : $values[count($values) - 1];
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * @return list<string> lower-case names of every header received
     */
    public function getHeaderNames(): array
    {
        return array_keys($this->headers);
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
