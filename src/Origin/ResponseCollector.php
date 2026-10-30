<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * Assembles the HttpResponse of one transfer from the raw header lines and
 * the decoded body chunks a transport receives, so that every transport
 * applies the same rules (see HttpClient):
 *
 * - a status line starts a response; interim 1xx responses, a CONNECT
 *   answer of a proxy and the redirects the transport follows are skipped;
 * - when the header block of the final response ends, a 2xx answer to a
 *   GET opens a temporary file for the body, unless its Content-Length
 *   already exceeds the maximum size; any other body is discarded, and
 *   aborted after DISCARD_LIMIT bytes;
 * - body chunks arrive as received, transfer coding removed; a gzip body is
 *   inflated here when requestsGzip() asked for it, any other content
 *   coding is then refused, and a gzip stream that does not end with the
 *   body is a truncated body;
 * - decoded bytes are written to the file until the maximum size is reached.
 *
 * headerLine() and bodyChunk() return false when the transfer must be
 * aborted; finish() then reports why, whatever error the transport saw.
 *
 * @internal used by the transports of this namespace
 */
final class ResponseCollector
{
    /**
     * Bytes of a discarded body that are read, so that an HTTP/1.1
     * connection can be reused, before the transfer is aborted instead.
     */
    public const DISCARD_LIMIT = 65536;

    /**
     * Bytes the header block of one response may have.
     */
    public const MAX_HEADER_BYTES = 102400;

    private const ABORT_NONE = 0;

    private const ABORT_TOO_LARGE = 1;

    private const ABORT_DISCARDED = 2;

    private const ABORT_FILE = 3;

    private const ABORT_PROTOCOL = 4;

    /**
     * Compressed bytes inflated at once: a compression bomb then expands to
     * about a thousand times this size in memory before the size check.
     */
    private const INFLATE_SLICE = 1024;

    /**
     * @var bool
     */
    private $isHead;

    /**
     * @var bool
     */
    private $followRedirects;

    /**
     * @var int
     */
    private $maxBodySize;

    /**
     * @var string
     */
    private $temporaryDirectory;

    /**
     * @var bool
     */
    private $requestsGzip;

    /**
     * The gzip decoder of the body being stored, or null for an identity body.
     *
     * @var InflateContextOrResource|null
     */
    private $inflate;

    /**
     * @var int
     */
    private $encodedBytes = 0;

    /**
     * @var int
     */
    private $status = 0;

    /**
     * @var string
     */
    private $protocolVersion = '';

    /**
     * @var array<string, string>
     */
    private $headers = [];

    /**
     * @var string|null
     */
    private $lastField;

    /**
     * @var int
     */
    private $headerBytes = 0;

    /**
     * @var bool
     */
    private $final = false;

    /**
     * @var resource|null
     */
    private $file;

    /**
     * @var string|null
     */
    private $path;

    /**
     * @var int
     */
    private $bytes = 0;

    /**
     * @var int
     */
    private $discarded = 0;

    /**
     * @var int
     */
    private $abort = self::ABORT_NONE;

    /**
     * @var string
     */
    private $abortMessage = '';

    public function __construct(HttpRequest $request, HttpClientOptions $options)
    {
        $this->isHead = $request->getMethod() === HttpRequest::METHOD_HEAD;
        $this->followRedirects = $options->shouldFollowRedirects();
        $this->maxBodySize = $options->getMaxBodySize();
        $this->temporaryDirectory = $options->getTemporaryDirectory();
        $this->requestsGzip = $options->isCompressionEnabled() && $request->getHeader('accept-encoding') === null
            && function_exists('inflate_init');
    }

    /**
     * Whether the transport must send "Accept-Encoding: gzip": compression
     * is enabled, ext-zlib is loaded and the request has no Accept-Encoding
     * of its own. A gzip body is then inflated before it is stored; with an
     * Accept-Encoding of the request, the body is stored as received and
     * keeps its Content-Encoding.
     */
    public function requestsGzip(): bool
    {
        return $this->requestsGzip;
    }

    /**
     * Feeds one line of a header block, with or without its line ending: a
     * status line starts a response, an empty line ends its header block.
     *
     * @return bool false when the transfer must be aborted
     */
    public function headerLine(string $line): bool
    {
        if ($this->abort !== self::ABORT_NONE) {
            return false;
        }
        $this->headerBytes += strlen($line);
        if ($this->headerBytes > self::MAX_HEADER_BYTES) {
            return $this->abort(self::ABORT_PROTOCOL, 'The response header block exceeds ' . self::MAX_HEADER_BYTES . ' bytes');
        }

        $line = rtrim($line, "\r\n");
        if (strncmp($line, 'HTTP/', 5) === 0) {
            return $this->startResponse($line);
        }
        if ($this->status === 0 || $this->final) {
            // Nothing before the first status line, nor the trailer fields
            // of a chunked body, belongs to the header block.
            return true;
        }
        if ($line === '') {
            return $this->endHeaderBlock();
        }
        if ($line[0] === ' ' || $line[0] === "\t") {
            if ($this->lastField !== null) {
                $this->headers[$this->lastField] .= ' ' . trim($line, " \t");
            }

            return true;
        }

        $colon = strpos($line, ':');
        if ($colon === false || $colon === 0) {
            return true;
        }
        $name = strtolower(rtrim(substr($line, 0, $colon), " \t"));
        $value = trim(substr($line, $colon + 1), " \t");
        $this->headers[$name] = isset($this->headers[$name]) ? $this->headers[$name] . ', ' . $value : $value;
        $this->lastField = $name;

        return true;
    }

    /**
     * Feeds a chunk of the body as received, its transfer coding removed.
     *
     * @return bool false when the transfer must be aborted
     */
    public function bodyChunk(string $data): bool
    {
        if ($this->abort !== self::ABORT_NONE) {
            return false;
        }
        if (!$this->final) {
            // The body of a redirect the transport follows: a few bytes
            // are read, so that the connection can be reused, then the
            // transfer ends; the redirect is still followed.
            $this->discarded += strlen($data);

            return $this->discarded <= self::DISCARD_LIMIT;
        }

        if ($this->file === null) {
            $this->discarded += strlen($data);

            return $this->discarded <= self::DISCARD_LIMIT || $this->abort(self::ABORT_DISCARDED, '');
        }
        if ($this->inflate === null) {
            return $this->store($data);
        }

        $length = strlen($data);
        $this->encodedBytes += $length;
        for ($offset = 0; $offset < $length; $offset += self::INFLATE_SLICE) {
            $decoded = @inflate_add($this->inflate, (string) substr($data, $offset, self::INFLATE_SLICE), ZLIB_SYNC_FLUSH);
            if (!is_string($decoded)) {
                return $this->abort(self::ABORT_PROTOCOL, 'The compressed body is corrupt');
            }
            if ($decoded !== '' && !$this->store($decoded)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the header block of the final response has been received.
     */
    public function isFinal(): bool
    {
        return $this->final;
    }

    /**
     * Whether the body of the final response is being stored: false for
     * HEAD, for a status other than 2xx and after an abort.
     */
    public function wantsBody(): bool
    {
        return $this->file !== null && $this->abort === self::ABORT_NONE;
    }

    /**
     * Whether the last complete header block is a redirect the transport
     * must follow: with redirects enabled, a 3xx other than 304 that has a
     * Location, unless the transfer was aborted.
     */
    public function isFollowingRedirect(): bool
    {
        return !$this->final && $this->abort === self::ABORT_NONE && $this->followRedirects
            && $this->status >= 300 && $this->status < 400 && $this->status !== 304 && isset($this->headers['location']);
    }

    /**
     * The status of the current response; 0 before any status line.
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * A field of the current response, or null.
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * Number of body bytes stored so far.
     */
    public function getBodySize(): int
    {
        return $this->bytes;
    }

    /**
     * Ends the transfer and builds its response. The temporary file is
     * closed, and deleted unless the response owns it.
     *
     * @param string|null $failure      the HttpResponse::OUTCOME_* of a transport failure; null when the transfer
     *                                  completed. OUTCOME_REDIRECT means one redirect too many
     * @param int         $errorCode    the transport's error number, 0 without failure
     * @param string      $error        the transport's error message, empty without failure
     * @param string      $effectiveUrl the URL of the last request sent
     * @param float       $duration     seconds the request took
     */
    public function finish(?string $failure, int $errorCode, string $error, string $effectiveUrl, float $duration): HttpResponse
    {
        if ($this->file !== null) {
            @fclose($this->file);
            $this->file = null;
        }

        if ($this->abort === self::ABORT_DISCARDED) {
            // The body was not needed: the status is the answer.
            $failure = null;
        } elseif ($this->abort !== self::ABORT_NONE) {
            $this->deleteFile();

            return $this->response(
                $this->abort === self::ABORT_TOO_LARGE ? HttpResponse::OUTCOME_TOO_LARGE : HttpResponse::OUTCOME_OTHER,
                null,
                $effectiveUrl,
                $duration,
                $errorCode,
                $this->abortMessage
            );
        }

        if ($failure === null && $this->inflate !== null && $this->encodedBytes > 0 && inflate_get_status($this->inflate) !== ZLIB_STREAM_END) {
            $failure = HttpResponse::OUTCOME_NETWORK;
            $error = 'The compressed body is truncated';
        }

        if ($failure !== null) {
            if ($this->final && $this->path === null) {
                // The final status is known and its body was not wanted:
                // a failure while discarding the body does not change it.
                $failure = null;
            } else {
                $this->deleteFile();
                if ($failure === HttpResponse::OUTCOME_REDIRECT && $this->status >= 300 && $this->status < 400) {
                    return $this->response(HttpResponse::OUTCOME_REDIRECT, null, $effectiveUrl, $duration, $errorCode, $error);
                }
                if (!$this->final) {
                    $this->status = 0;
                    $this->headers = [];
                    $this->protocolVersion = '';
                }

                return $this->response($failure, null, $effectiveUrl, $duration, $errorCode, $error);
            }
        }

        if (!$this->final) {
            $this->deleteFile();
            $this->headers = [];
            $this->protocolVersion = '';
            $status = $this->status;
            $this->status = 0;

            return $this->response(
                HttpResponse::OUTCOME_OTHER,
                null,
                $effectiveUrl,
                $duration,
                $errorCode,
                $status === 0 ? 'No HTTP response was received' : 'The transfer ended before the final response'
            );
        }

        $body = null;
        if ($this->path !== null) {
            $this->headers['content-length'] = (string) $this->bytes;
            unset($this->headers['transfer-encoding']);
            if ($this->inflate !== null) {
                unset($this->headers['content-encoding']);
            }
            $body = new ResponseBody($this->path, $this->bytes);
            $this->path = null;
        }

        return $this->response(HttpResponse::outcomeOfStatus($this->status), $body, $effectiveUrl, $duration, 0, '');
    }

    public function __destruct()
    {
        if ($this->file !== null) {
            @fclose($this->file);
            $this->file = null;
        }
        $this->deleteFile();
    }

    private function startResponse(string $statusLine): bool
    {
        if ($this->file !== null) {
            // A new response after a final one (a proxy's answer to
            // CONNECT, for one): only the last response counts.
            @fclose($this->file);
            $this->file = null;
            $this->deleteFile();
        }
        $this->bytes = 0;
        $this->discarded = 0;
        $this->encodedBytes = 0;
        $this->inflate = null;
        $this->final = false;
        $this->headers = [];
        $this->lastField = null;
        $this->headerBytes = strlen($statusLine);

        if (preg_match('#\AHTTP/(\d(?:\.\d)?) +(\d{3})(?: |\z)#', $statusLine, $matches) !== 1) {
            $this->status = 0;

            return $this->abort(self::ABORT_PROTOCOL, 'Malformed status line: ' . substr($statusLine, 0, 64));
        }
        $this->protocolVersion = $matches[1];
        $this->status = (int) $matches[2];

        return true;
    }

    private function endHeaderBlock(): bool
    {
        if ($this->status < 200 || $this->isFollowingRedirect()) {
            // Interim response, or a redirect the transport follows: the
            // next status line starts the next response.
            if ($this->status < 200) {
                $this->status = 0;
            }

            return true;
        }

        $this->final = true;
        if ($this->isHead || $this->status >= 300) {
            return true;
        }

        $length = $this->headers['content-length'] ?? null;
        $encoding = strtolower(trim($this->headers['content-encoding'] ?? ''));
        if ($encoding === '') {
            $encoding = 'identity';
        }
        if ($encoding !== 'identity' && $this->requestsGzip) {
            if ($encoding !== 'gzip' && $encoding !== 'x-gzip') {
                return $this->abort(self::ABORT_PROTOCOL, 'Unsupported content encoding: ' . substr($encoding, 0, 64));
            }
            $inflate = inflate_init(ZLIB_ENCODING_GZIP);
            if ($inflate === false) {
                return $this->abort(self::ABORT_PROTOCOL, 'Cannot initialise gzip decoding');
            }
            $this->inflate = $inflate;
        }
        if ($length !== null && $encoding === 'identity' && ctype_digit($length) && (strlen($length) > 18 || (int) $length > $this->maxBodySize)) {
            return $this->abort(self::ABORT_TOO_LARGE, 'The response body announces ' . $length . ' bytes, more than ' . $this->maxBodySize);
        }

        $path = is_dir($this->temporaryDirectory) ? @tempnam($this->temporaryDirectory, 'pcdn-') : false;
        $file = is_string($path) ? @fopen($path, 'wb') : false;
        if (!is_string($path) || !is_resource($file)) {
            if (is_string($path)) {
                @unlink($path);
            }

            return $this->abort(self::ABORT_FILE, 'Cannot create a temporary file in ' . $this->temporaryDirectory);
        }
        $this->path = $path;
        $this->file = $file;

        return true;
    }

    /**
     * Records why the transfer is aborted; always false, for the callers'
     * return value.
     */
    private function abort(int $reason, string $message): bool
    {
        if ($this->abort === self::ABORT_NONE) {
            $this->abort = $reason;
            $this->abortMessage = $message;
        }

        return false;
    }

    /**
     * Writes decoded body bytes to the file, within the maximum size.
     */
    private function store(string $data): bool
    {
        $length = strlen($data);
        if ($length > $this->maxBodySize - $this->bytes) {
            return $this->abort(self::ABORT_TOO_LARGE, 'The response body exceeds ' . $this->maxBodySize . ' bytes');
        }
        $file = $this->file;
        if ($file === null || @fwrite($file, $data) !== $length) {
            return $this->abort(self::ABORT_FILE, 'The response body cannot be written to ' . (string) $this->path);
        }
        $this->bytes += $length;

        return true;
    }

    private function deleteFile(): void
    {
        if ($this->path !== null) {
            @unlink($this->path);
            $this->path = null;
        }
    }

    private function response(string $outcome, ?ResponseBody $body, string $effectiveUrl, float $duration, int $errorCode, string $error): HttpResponse
    {
        return new HttpResponse(
            $outcome,
            $this->status,
            $this->headers,
            $body,
            $this->protocolVersion,
            $effectiveUrl,
            $duration,
            $errorCode,
            $error
        );
    }
}
