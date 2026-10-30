<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * The outcome of an origin fetch: a 200 response with its headers and body,
 * or a failure with its reason. Immutable.
 *
 * As in 2.x only a 200 is a success; every other status and every transport
 * error is a failure, classified by one of the FAILURE_* reasons.
 */
final class OriginResponse
{
    /** The origin answered 404. */
    public const FAILURE_NOT_FOUND = 'not_found';

    /** The origin answered with a 5xx status. */
    public const FAILURE_SERVER_ERROR = 'server_error';

    /** The connection or the transfer timed out. */
    public const FAILURE_TIMEOUT = 'timeout';

    /** The origin host name could not be resolved. */
    public const FAILURE_DNS = 'dns';

    /** The TLS handshake or the certificate verification failed. */
    public const FAILURE_TLS = 'tls';

    /** The connection was refused or closed before any response. */
    public const FAILURE_CONNECT = 'connect';

    /** Sending or receiving failed during the transfer. */
    public const FAILURE_NETWORK = 'network';

    /** Any other status (3xx, 4xx other than 404) or transport error. */
    public const FAILURE_OTHER = 'other';

    /**
     * @var int
     */
    private $status;

    /**
     * @var array<string, string>
     */
    private $headers;

    /**
     * @var string
     */
    private $body;

    /**
     * @var string|null
     */
    private $failureReason;

    /**
     * @var int
     */
    private $transportErrorCode;

    /**
     * @var string
     */
    private $transportError;

    /**
     * @param array<string, string> $headers
     */
    private function __construct(int $status, array $headers, string $body, ?string $failureReason, int $transportErrorCode, string $transportError)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
        $this->failureReason = $failureReason;
        $this->transportErrorCode = $transportErrorCode;
        $this->transportError = $transportError;
    }

    /**
     * A 200 response.
     *
     * @param array<string, string> $headers header values keyed by lower-case name, the last occurrence of each
     */
    public static function success(array $headers, string $body): self
    {
        return new self(200, $headers, $body, null, 0, '');
    }

    /**
     * A failed fetch.
     *
     * @param string $reason             one of the FAILURE_* constants
     * @param int    $status             the HTTP status, 0 when no response was received
     * @param int    $transportErrorCode the transport's error number, 0 for an HTTP failure
     * @param string $transportError     the transport's error message, empty for an HTTP failure
     */
    public static function failure(string $reason, int $status, int $transportErrorCode = 0, string $transportError = ''): self
    {
        return new self($status, [], '', $reason, $transportErrorCode, $transportError);
    }

    public function isSuccessful(): bool
    {
        return $this->failureReason === null;
    }

    /**
     * Whether the origin answered 404.
     */
    public function isNotFound(): bool
    {
        return $this->failureReason === self::FAILURE_NOT_FOUND;
    }

    /**
     * The HTTP status; 0 when no response was received.
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * The response headers, keyed by lower-case name; empty for a failure.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * The response body; empty for a failure.
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * One of the FAILURE_* constants; null for a success.
     */
    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getTransportErrorCode(): int
    {
        return $this->transportErrorCode;
    }

    public function getTransportError(): string
    {
        return $this->transportError;
    }
}
