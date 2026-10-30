<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * Reads the body of one StreamTransport response from its socket: framed by
 * Content-Length, by the chunked transfer coding, or by the end of the
 * connection. It enforces the deadline and the low speed limit, and feeds
 * the bytes to the collector, which decodes them.
 *
 * Every method returns null on success, or the failure as
 * [HttpResponse::OUTCOME_*, error number, message].
 *
 * @internal used by StreamTransport
 */
final class BodyReader
{
    /**
     * Bytes read from the socket at once.
     */
    private const READ_SIZE = 65536;

    /**
     * Bytes a chunk size line or a trailer line may have.
     */
    private const MAX_LINE = 16384;

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
     * @param resource $socket        the connection, positioned after the header block
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
     * Reads exactly $length bytes.
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    public function readLength(int $length): ?array
    {
        for ($remaining = $length; $remaining > 0; $remaining -= strlen($data)) {
            $data = $this->read(min(self::READ_SIZE, $remaining));
            if (is_array($data)) {
                return $data;
            }
            if ($data === null) {
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
     * Reads until the server closes the connection.
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    public function readUntilClose(): ?array
    {
        while (true) {
            $data = $this->read(self::READ_SIZE);
            if ($data === null) {
                return null;
            }
            $failure = is_array($data) ? $data : $this->deliver($data);
            if ($failure !== null) {
                return $failure;
            }
        }
    }

    /**
     * Reads a chunked body, its last chunk and its trailer fields.
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    public function readChunked(): ?array
    {
        while (true) {
            $line = $this->line();
            if (!is_string($line)) {
                return $line ?? [HttpResponse::OUTCOME_NETWORK, 0, 'Transfer closed before the last chunk'];
            }
            $size = trim(explode(';', $line, 2)[0]);
            if ($size === '' || strlen($size) > 15 || !ctype_xdigit($size)) {
                return [HttpResponse::OUTCOME_NETWORK, 0, 'Malformed chunk size'];
            }
            if (hexdec($size) === 0) {
                return $this->skipTrailer();
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
    private function skipTrailer(): ?array
    {
        do {
            $line = $this->line();
            if (!is_string($line)) {
                return $line ?? [HttpResponse::OUTCOME_NETWORK, 0, 'Transfer closed in the trailer'];
            }
        } while (rtrim($line, "\r\n") !== '');

        return null;
    }

    /**
     * Up to $length bytes; null at the end of the connection.
     *
     * @return string|array{0: string, 1: int, 2: string}|null
     */
    private function read(int $length)
    {
        StreamTransport::arm($this->socket, $this->deadline, $this->lowSpeedTime);
        // A failed read (false) is handled like an empty one.
        $data = (string) fread($this->socket, max(1, $length));
        if ($data === '') {
            if (StreamTransport::timedOut($this->socket)) {
                return [HttpResponse::OUTCOME_TIMEOUT, 0, 'Timed out reading the response body'];
            }

            return feof($this->socket) ? null : [HttpResponse::OUTCOME_NETWORK, 0, 'Failure receiving the response body'];
        }

        $now = microtime(true);
        if ($now > $this->deadline) {
            return [HttpResponse::OUTCOME_TIMEOUT, 0, 'Timed out reading the response body'];
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
     * One line, line ending included; null at the end of the connection.
     *
     * @return string|array{0: string, 1: int, 2: string}|null
     */
    private function line()
    {
        StreamTransport::arm($this->socket, $this->deadline, $this->lowSpeedTime);
        $line = fgets($this->socket, self::MAX_LINE);
        if (!is_string($line)) {
            return StreamTransport::timedOut($this->socket) ? [HttpResponse::OUTCOME_TIMEOUT, 0, 'Timed out reading the response body'] : null;
        }
        if (substr($line, -1) !== "\n") {
            return [HttpResponse::OUTCOME_NETWORK, 0, 'Malformed chunked body: line too long or unterminated'];
        }

        return $line;
    }

    /**
     * Hands body bytes to the collector.
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    private function deliver(string $raw): ?array
    {
        return $this->collector->bodyChunk($raw) ? null : [HttpResponse::OUTCOME_OTHER, 0, 'Aborted'];
    }
}
