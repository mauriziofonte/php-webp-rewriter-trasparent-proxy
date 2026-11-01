<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * Reads one StreamTransport response from its socket: the lines of its
 * header blocks, then its body, framed by Content-Length, by the chunked
 * transfer coding, or by the end of the connection, which goes to the
 * collector.
 *
 * Every read is one fread() into a buffer, never fgets(), so that the
 * deadline and the low speed limit are checked on every read, inside a
 * line too: fgets() keeps waiting for the end of a line for as long as
 * bytes trickle in, each within the read timeout.
 *
 * The methods that can fail return the failure as
 * [HttpResponse::OUTCOME_*, error number, message].
 *
 * @internal used by StreamTransport
 */
final class ResponseReader
{
    /**
     * Bytes a line may have: a header line, a chunk size line or a trailer
     * line.
     */
    public const MAX_LINE = 16384;

    /**
     * Bytes read from the socket at once.
     */
    private const READ_SIZE = 65536;

    /**
     * @var resource
     */
    private $socket;

    /**
     * @var ResponseCollector
     */
    private $collector;

    /**
     * @var float
     */
    private $deadline;

    /**
     * @var int
     */
    private $lowSpeedLimit;

    /**
     * @var float
     */
    private $lowSpeedTime;

    /**
     * @var float
     */
    private $windowStart;

    /**
     * @var int
     */
    private $windowBytes = 0;

    /**
     * Bytes received and not consumed yet.
     *
     * @var string
     */
    private $buffer = '';

    /**
     * What is being read, for the messages.
     *
     * @var string
     */
    private $reading = 'the response header block';

    /**
     * @param resource $socket        the connection, once the request is sent
     * @param float    $deadline      microtime() at which the request times out
     * @param int      $lowSpeedLimit bytes per second under which the transfer is too slow
     * @param float    $lowSpeedTime  seconds the transfer may stay under the limit
     */
    public function __construct($socket, ResponseCollector $collector, float $deadline, int $lowSpeedLimit, float $lowSpeedTime)
    {
        $this->socket = $socket;
        $this->collector = $collector;
        $this->deadline = $deadline;
        $this->lowSpeedLimit = $lowSpeedLimit;
        $this->lowSpeedTime = $lowSpeedTime;
        $this->windowStart = microtime(true);
    }

    /**
     * One line, its line feed included, as fgets() would return it: without
     * the line feed when the line is longer than MAX_LINE bytes or when the
     * connection ends first; null at the end of the connection.
     *
     * @return string|array{0: string, 1: int, 2: string}|null
     */
    public function line()
    {
        while (true) {
            $end = strpos($this->buffer, "\n");
            if ($end !== false && $end < self::MAX_LINE) {
                $length = $end + 1;
                break;
            }
            if (strlen($this->buffer) >= self::MAX_LINE) {
                $length = self::MAX_LINE;
                break;
            }
            $data = $this->receive(self::READ_SIZE);
            if (is_array($data)) {
                return $data;
            }
            if ($data === '') {
                if ($this->buffer === '') {
                    return null;
                }
                $length = strlen($this->buffer);
                break;
            }
            $this->buffer .= $data;
        }

        $line = (string) substr($this->buffer, 0, $length);
        $this->buffer = (string) substr($this->buffer, $length);

        return $line;
    }

    /**
     * Reads a body of exactly $length bytes.
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    public function readLength(int $length): ?array
    {
        $this->reading = 'the response body';
        for ($remaining = $length; $remaining > 0; $remaining -= strlen($data)) {
            $data = $this->read(min(self::READ_SIZE, $remaining));
            if (is_array($data)) {
                return $data;
            }
            if ($data === '') {
                return [HttpResponse::OUTCOME_NETWORK, 0, 'Transfer closed with ' . $remaining . ' bytes remaining to read'];
            }
            $failure = $this->deliver($data);
            if ($failure !== null) {
                return $failure;
            }
        }

        return null;
    }

    /**
     * Reads a body that ends when the server closes the connection.
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    public function readUntilClose(): ?array
    {
        $this->reading = 'the response body';
        while (true) {
            $data = $this->read(self::READ_SIZE);
            if ($data === '') {
                return null;
            }
            $failure = is_array($data) ? $data : $this->deliver($data);
            if ($failure !== null) {
                return $failure;
            }
        }
    }

    /**
     * Reads a chunked body, its last chunk and its trailer fields, which
     * the collector receives as header lines.
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    public function readChunked(): ?array
    {
        $this->reading = 'the response body';
        while (true) {
            $line = $this->line();
            if (!is_string($line)) {
                return $line ?? [HttpResponse::OUTCOME_NETWORK, 0, 'Transfer closed before the last chunk'];
            }
            if (substr($line, -1) !== "\n") {
                return [HttpResponse::OUTCOME_NETWORK, 0, 'Malformed chunked body: line too long or unterminated'];
            }
            $size = trim(explode(';', $line, 2)[0]);
            if ($size === '' || strlen($size) > 15 || preg_match('/\A[0-9A-Fa-f]+\z/', $size) !== 1) {
                return [HttpResponse::OUTCOME_NETWORK, 0, 'Malformed chunk size'];
            }
            if (hexdec($size) === 0) {
                return $this->readTrailer();
            }
            $failure = $this->readLength((int) hexdec($size));
            if ($failure !== null) {
                return $failure;
            }
            $line = $this->line();
            if (!is_string($line)) {
                return $line ?? [HttpResponse::OUTCOME_NETWORK, 0, 'Transfer closed after a chunk'];
            }
            if (rtrim($line, "\r\n") !== '') {
                return [HttpResponse::OUTCOME_NETWORK, 0, 'Malformed chunk: missing line ending'];
            }
        }
    }

    /**
     * @return array{0: string, 1: int, 2: string}|null
     */
    private function readTrailer(): ?array
    {
        do {
            $line = $this->line();
            if (!is_string($line)) {
                return $line ?? [HttpResponse::OUTCOME_NETWORK, 0, 'Transfer closed in the trailer'];
            }
            if (substr($line, -1) !== "\n") {
                return [HttpResponse::OUTCOME_NETWORK, 0, 'Malformed chunked body: line too long or unterminated'];
            }
            // The trailer counts towards the size limit of the header block,
            // as with libcurl, which hands it to the header callback.
            if (!$this->collector->headerLine($line)) {
                return [HttpResponse::OUTCOME_OTHER, 0, 'Aborted'];
            }
        } while (rtrim($line, "\r\n") !== '');

        return null;
    }

    /**
     * Up to $length bytes, from the buffer first; '' at the end of the
     * connection.
     *
     * @return string|array{0: string, 1: int, 2: string}
     */
    private function read(int $length)
    {
        if ($this->buffer === '') {
            return $this->receive($length);
        }
        $data = (string) substr($this->buffer, 0, $length);
        $this->buffer = (string) substr($this->buffer, strlen($data));

        return $data;
    }

    /**
     * One read from the socket, of at most $length bytes, within the
     * deadline and the low speed limit; '' at the end of the connection.
     *
     * @return string|array{0: string, 1: int, 2: string}
     */
    private function receive(int $length)
    {
        StreamTransport::arm($this->socket, $this->deadline, $this->lowSpeedTime);
        // A failed read (false) is handled like an empty one.
        $data = (string) fread($this->socket, max(1, $length));
        if ($data === '') {
            if (StreamTransport::timedOut($this->socket)) {
                return [HttpResponse::OUTCOME_TIMEOUT, 0, 'Timed out reading ' . $this->reading];
            }

            return feof($this->socket) ? '' : [HttpResponse::OUTCOME_NETWORK, 0, 'Failure receiving ' . $this->reading];
        }

        $now = microtime(true);
        if ($now > $this->deadline) {
            return [HttpResponse::OUTCOME_TIMEOUT, 0, 'Timed out reading ' . $this->reading];
        }
        $this->windowBytes += strlen($data);
        $elapsed = $now - $this->windowStart;
        if ($elapsed >= $this->lowSpeedTime) {
            if ($this->windowBytes < $this->lowSpeedLimit * $elapsed) {
                return [HttpResponse::OUTCOME_TIMEOUT, 0, sprintf('Operation too slow: less than %d bytes/sec for %d seconds', $this->lowSpeedLimit, (int) $this->lowSpeedTime)];
            }
            $this->windowStart = $now;
            $this->windowBytes = 0;
        }

        return $data;
    }

    /**
     * Hands body bytes to the collector.
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    private function deliver(string $data): ?array
    {
        return $this->collector->bodyChunk($data) ? null : [HttpResponse::OUTCOME_OTHER, 0, 'Aborted'];
    }
}
