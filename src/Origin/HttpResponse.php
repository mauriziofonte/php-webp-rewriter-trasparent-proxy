<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * The outcome of an HttpClient request: either a final HTTP response, with
 * its status, header fields and (for a 2xx answer to a GET) its body in a
 * temporary file, or a transport failure.
 *
 * getOutcome() classifies every result into one of the OUTCOME_* values, so
 * that redirects, 403, 404, 410, other client errors, server errors and the
 * kinds of transport failure stay distinct (finding P-13). Only
 * OUTCOME_SUCCESS carries a body; for the others the body was discarded.
 */
final class HttpResponse
{
    /** A 2xx response; a GET has its body in getBody(). */
    public const OUTCOME_SUCCESS = 'success';

    /** 304 Not Modified, the answer to a conditional request whose copy is current. */
    public const OUTCOME_NOT_MODIFIED = 'not_modified';

    /** A 3xx response that was not followed, or one redirect too many. */
    public const OUTCOME_REDIRECT = 'redirect';

    /** 403 Forbidden. */
    public const OUTCOME_FORBIDDEN = 'forbidden';

    /** 404 Not Found. */
    public const OUTCOME_NOT_FOUND = 'not_found';

    /** 410 Gone. */
    public const OUTCOME_GONE = 'gone';

    /** Any other 4xx response. */
    public const OUTCOME_CLIENT_ERROR = 'client_error';

    /** A 5xx response. */
    public const OUTCOME_SERVER_ERROR = 'server_error';

    /** The body exceeds the maximum size: the transfer was aborted. */
    public const OUTCOME_TOO_LARGE = 'too_large';

    /** The connection, the whole request or a stalled transfer timed out. */
    public const OUTCOME_TIMEOUT = 'timeout';

    /** The host name could not be resolved. */
    public const OUTCOME_DNS = 'dns';

    /** The connection was refused, or closed before any response. */
    public const OUTCOME_CONNECT = 'connect';

    /** The TLS handshake or the certificate verification failed. */
    public const OUTCOME_TLS = 'tls';

    /** Sending or receiving failed during the transfer, or the body is truncated. */
    public const OUTCOME_NETWORK = 'network';

    /** Any other failure: protocol error, unsupported redirect target, status outside 200-599. */
    public const OUTCOME_OTHER = 'other';

    /**
     * Outcomes that describe a received HTTP response rather than a failed
     * transfer.
     */
    private const HTTP_OUTCOMES = [
        self::OUTCOME_SUCCESS,
        self::OUTCOME_NOT_MODIFIED,
        self::OUTCOME_REDIRECT,
        self::OUTCOME_FORBIDDEN,
        self::OUTCOME_NOT_FOUND,
        self::OUTCOME_GONE,
        self::OUTCOME_CLIENT_ERROR,
        self::OUTCOME_SERVER_ERROR,
    ];

    /**
     * @var string
     */
    private $outcome;

    /**
     * @var int
     */
    private $status;

    /**
     * @var array<string, string>
     */
    private $headers;

    /**
     * @var ResponseBody|null
     */
    private $body;

    /**
     * @var string
     */
    private $protocolVersion;

    /**
     * @var string
     */
    private $effectiveUrl;

    /**
     * @var float
     */
    private $duration;

    /**
     * @var int
     */
    private $errorCode;

    /**
     * @var string
     */
    private $error;

    /**
     * @param string                $outcome         one of the OUTCOME_* constants
     * @param int                   $status          the final HTTP status; 0 when no response was received
     * @param array<string, string> $headers         fields of the final response, keyed by lower-case name
     * @param ResponseBody|null     $body            the body, for OUTCOME_SUCCESS on a GET
     * @param string                $protocolVersion HTTP version of the final response ("1.1", "2"); empty without response
     * @param string                $effectiveUrl    URL of the final response, after redirects
     * @param float                 $duration        seconds the request took
     * @param int                   $errorCode       the transport's error number; 0 without transport error
     * @param string                $error           the transport's error message; empty without transport error
     */
    public function __construct(
        string $outcome,
        int $status,
        array $headers,
        ?ResponseBody $body,
        string $protocolVersion,
        string $effectiveUrl,
        float $duration,
        int $errorCode = 0,
        string $error = ''
    ) {
        $this->outcome = $outcome;
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
        $this->protocolVersion = $protocolVersion;
        $this->effectiveUrl = $effectiveUrl;
        $this->duration = $duration;
        $this->errorCode = $errorCode;
        $this->error = $error;
    }

    /**
     * The outcome a received status maps to.
     */
    public static function outcomeOfStatus(int $status): string
    {
        if ($status >= 200 && $status < 300) {
            return self::OUTCOME_SUCCESS;
        }
        if ($status === 304) {
            return self::OUTCOME_NOT_MODIFIED;
        }
        if ($status >= 300 && $status < 400) {
            return self::OUTCOME_REDIRECT;
        }
        if ($status === 403) {
            return self::OUTCOME_FORBIDDEN;
        }
        if ($status === 404) {
            return self::OUTCOME_NOT_FOUND;
        }
        if ($status === 410) {
            return self::OUTCOME_GONE;
        }
        if ($status >= 400 && $status < 500) {
            return self::OUTCOME_CLIENT_ERROR;
        }
        if ($status >= 500 && $status < 600) {
            return self::OUTCOME_SERVER_ERROR;
        }

        return self::OUTCOME_OTHER;
    }

    /**
     * One of the OUTCOME_* constants.
     */
    public function getOutcome(): string
    {
        return $this->outcome;
    }

    /**
     * Whether the origin answered 2xx and the whole body was received.
     */
    public function isSuccessful(): bool
    {
        return $this->outcome === self::OUTCOME_SUCCESS;
    }

    /**
     * Whether the outcome is a transfer failure (no complete HTTP response:
     * timeout, DNS, connect, TLS, network, too large, other) rather than an
     * HTTP status.
     */
    public function isTransportFailure(): bool
    {
        return !in_array($this->outcome, self::HTTP_OUTCOMES, true);
    }

    /**
     * The final HTTP status; 0 when no response was received. A too large
     * body keeps the status of its response.
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * The fields of the final response, keyed by lower-case name; repeated
     * fields are joined with ", ". When the transport stored a body, they
     * describe the stored bytes: Content-Length is their number and a
     * Content-Encoding the transport decoded is removed.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * One header field of the final response, or null when it is absent.
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * The body of a successful GET; null for every other outcome and for HEAD.
     */
    public function getBody(): ?ResponseBody
    {
        return $this->body;
    }

    /**
     * The Location of a redirect, as sent by the origin; null otherwise.
     */
    public function getRedirectLocation(): ?string
    {
        return $this->outcome === self::OUTCOME_REDIRECT ? $this->getHeader('location') : null;
    }

    /**
     * The HTTP version of the final response, "1.0", "1.1" or "2"; empty when
     * no response was received.
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * The URL of the final response, after redirects.
     */
    public function getEffectiveUrl(): string
    {
        return $this->effectiveUrl;
    }

    /**
     * Seconds the request took, redirects included.
     */
    public function getDuration(): float
    {
        return $this->duration;
    }

    /**
     * The transport's error number (a libcurl CURLE_* code, or an errno of
     * the stream transport); 0 when the transfer did not fail.
     */
    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    /**
     * The transport's error message; empty when the transfer did not fail.
     */
    public function getError(): string
    {
        return $this->error;
    }
}
