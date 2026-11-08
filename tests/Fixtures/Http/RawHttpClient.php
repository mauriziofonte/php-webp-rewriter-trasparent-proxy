<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Http;

/**
 * Minimal HTTP/1.1 client over a plain socket. It sends exactly the request
 * line and headers it is given (including a forged Host header) and reads
 * the response until the server closes the connection, so that a body longer
 * than its Content-Length is observed as it was sent.
 */
final class RawHttpClient
{
    /**
     * @var int
     */
    private $port;

    public function __construct(int $port)
    {
        $this->port = $port;
    }

    /**
     * @param string                $target  request target as sent on the request line ("/a.png?v=1")
     * @param array<string, string> $headers extra headers; Host defaults to 127.0.0.1:<port>
     *
     * @throws \RuntimeException when the server cannot be reached or answers with no status line
     */
    public function request(string $method, string $target, array $headers = [], string $body = ''): RawHttpResponse
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $errstr, 5.0);
        if (!is_resource($socket)) {
            throw new \RuntimeException(sprintf('Cannot connect to 127.0.0.1:%d: %s', $this->port, $errstr));
        }
        stream_set_timeout($socket, 60);

        $headers += ['Host' => '127.0.0.1:' . $this->port];
        if ($body !== '') {
            $headers['Content-Length'] = (string) strlen($body);
        }
        $headers['Connection'] = 'close';

        $request = $method . ' ' . $target . " HTTP/1.1\r\n";
        foreach ($headers as $name => $value) {
            $request .= $name . ': ' . $value . "\r\n";
        }
        fwrite($socket, $request . "\r\n" . $body);

        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        return self::parse($raw);
    }

    /**
     * @throws \RuntimeException when $raw holds no status line
     */
    private static function parse(string $raw): RawHttpResponse
    {
        $separator = strpos($raw, "\r\n\r\n");
        $head = $separator === false ? $raw : substr($raw, 0, $separator);
        $body = $separator === false ? '' : substr($raw, $separator + 4);

        $lines = explode("\r\n", $head);
        $statusLine = array_shift($lines);
        if (preg_match('#^HTTP/\d(?:\.\d)? (\d{3})#', $statusLine, $matches) !== 1) {
            throw new \RuntimeException('Not an HTTP response: ' . substr($raw, 0, 200));
        }

        $headers = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $headers[strtolower(trim(substr($line, 0, $colon)))][] = trim(substr($line, $colon + 1));
        }

        if (isset($headers['transfer-encoding']) && strtolower(end($headers['transfer-encoding'])) === 'chunked') {
            $body = self::decodeChunked($body);
        }

        return new RawHttpResponse((int) $matches[1], $headers, $body);
    }

    private static function decodeChunked(string $body): string
    {
        $decoded = '';
        $offset = 0;
        while (($lineEnd = strpos($body, "\r\n", $offset)) !== false) {
            $size = (int) hexdec(trim(substr($body, $offset, $lineEnd - $offset)));
            if ($size === 0) {
                break;
            }
            $decoded .= substr($body, $lineEnd + 2, $size);
            $offset = $lineEnd + 2 + $size + 2;
        }

        return $decoded;
    }
}
