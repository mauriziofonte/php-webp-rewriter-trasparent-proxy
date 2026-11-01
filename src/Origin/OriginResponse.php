<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * The outcome of an origin fetch: a 200 response with its headers and body,
 * a 304 to a conditional request, or a failure with its reason. Immutable,
 * but for the temporary file of a streamed body, which the receiver moves
 * or deletes.
 *
 * As in 2.x only a 200 is a success; every other status and every transport
 * error is a failure, classified by one of the FAILURE_* reasons. Redirects,
 * 403, 410 and other 4xx statuses have reasons of their own (finding P-13),
 * which ProxyKernel still treats as 2.x did until PRX-5.
 */
final class OriginResponse
{
    /** The origin answered with a redirect (3xx other than 304) that was not followed. */
    public const FAILURE_REDIRECT = 'redirect';

    /** The origin answered 403. */
    public const FAILURE_FORBIDDEN = 'forbidden';

    /** The origin answered 404. */
    public const FAILURE_NOT_FOUND = 'not_found';

    /** The origin answered 410. */
    public const FAILURE_GONE = 'gone';

    /** The origin answered with a 4xx status other than 403, 404 and 410. */
    public const FAILURE_CLIENT_ERROR = 'client_error';

    /** The origin answered with a 5xx status. */
    public const FAILURE_SERVER_ERROR = 'server_error';

    /** The body exceeds the maximum size (UPSTREAM_MAX_SIZE). */
    public const FAILURE_TOO_LARGE = 'too_large';

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

    /** Any other status (2xx other than 200, 304, outside 200-599) or failure. */
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
     * @var string|null
     */
    private $location;

    /**
     * @var ResponseBody|null
     */
    private $bodyFile;

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
     * A redirect the client did not follow (UPSTREAM_FOLLOW_REDIRECTS is
     * off, a redirect led to a non-public address, or too many followed).
     *
     * @param int         $status   the 3xx status
     * @param string|null $location the absolute http or https URL of the Location, null when there is none
     */
    public static function redirect(int $status, ?string $location): self
    {
        $response = new self($status, [], '', self::FAILURE_REDIRECT, 0, '');
        $response->location = $location;

        return $response;
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
     * A 200 response whose body is a temporary file, never read into memory
     * (finding P-17).
     *
     * @param array<string, string> $headers header values keyed by lower-case name, the last occurrence of each
     */
    public static function streamed(array $headers, ResponseBody $body): self
    {
        $response = new self(200, $headers, '', null, 0, '');
        $response->bodyFile = $body;

        return $response;
    }

    /**
     * The 304 answer to a conditional request: the cached copy is current.
     *
     * @param array<string, string> $headers header values keyed by lower-case name, the last occurrence of each
     */
    public static function notModified(array $headers): self
    {
        return new self(304, $headers, '', null, 0, '');
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

    /**
     * Whether the origin answered 200.
     */
    public function isSuccessful(): bool
    {
        return $this->failureReason === null && $this->status === 200;
    }

    /**
     * Whether the origin answered 304 to a conditional request.
     */
    public function isNotModified(): bool
    {
        return $this->failureReason === null && $this->status === 304;
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
     * A response header by lower-case name; null when it was not sent.
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * The response body; empty for a failure. A streamed body is read from
     * its file: prefer getBodyFile().
     */
    public function getBody(): string
    {
        if ($this->bodyFile !== null) {
            return (string) $this->bodyFile->getContents();
        }

        return $this->body;
    }

    /**
     * The temporary file of a streamed body; null for any other response.
     */
    public function getBodyFile(): ?ResponseBody
    {
        return $this->bodyFile;
    }

    /**
     * Deletes the temporary file of a streamed body, if any.
     */
    public function release(): void
    {
        if ($this->bodyFile !== null) {
            $this->bodyFile->delete();
        }
    }

    /**
     * One of the FAILURE_* constants; null for a success.
     */
    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    /**
     * The absolute URL a redirect points to; null for anything else, or a
     * redirect without a usable Location.
     */
    public function getLocation(): ?string
    {
        return $this->location;
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
