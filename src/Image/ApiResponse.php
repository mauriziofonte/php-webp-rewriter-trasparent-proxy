<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image;

/**
 * What ApiClient got back from the conversion API: the status and headers
 * of the final response, its body when it was kept in memory (every answer
 * but the 200 of a conversion), or the transport error that ended the
 * transfer. Immutable.
 */
final class ApiResponse
{
    /**
     * @var int
     */
    private $status;

    /**
     * @var array<string, list<string>>
     */
    private $headers;

    /**
     * @var string
     */
    private $body;

    /**
     * @var int
     */
    private $transportErrorCode;

    /**
     * @var string
     */
    private $transportError;

    /**
     * @var bool
     */
    private $tooLarge;

    /**
     * @param int                         $status             the HTTP status; 0 when no response arrived
     * @param array<string, list<string>> $headers            the header fields by lower-case name
     * @param string                      $body               the body kept in memory; empty when it was written to a file
     * @param int                         $transportErrorCode the curl error number; 0 when the transfer completed
     * @param string                      $transportError     the curl error message; empty when the transfer completed
     * @param bool                        $tooLarge           whether the body was cut off at its size limit
     */
    public function __construct(int $status, array $headers, string $body, int $transportErrorCode, string $transportError, bool $tooLarge)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
        $this->transportErrorCode = $transportErrorCode;
        $this->transportError = $transportError;
        $this->tooLarge = $tooLarge;
    }

    /**
     * The HTTP status; 0 when no response arrived.
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * The first value of the header field $name, in any letter case; null
     * when it is absent.
     */
    public function getHeader(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values === [] ? null : $values[0];
    }

    /**
     * Every value of the header field $name, in any letter case.
     *
     * @return list<string>
     */
    public function getHeaderValues(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    /**
     * The body kept in memory; empty when it was written to a file.
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * The curl error number that ended the transfer; 0 when it completed.
     */
    public function getTransportErrorCode(): int
    {
        return $this->transportErrorCode;
    }

    /**
     * The curl error message; empty when the transfer completed.
     */
    public function getTransportError(): string
    {
        return $this->transportError;
    }

    /**
     * Whether the transfer was stopped because the body exceeded its size
     * limit, with or without a Content-Length.
     */
    public function isTooLarge(): bool
    {
        return $this->tooLarge;
    }
}
