<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * HttpClient on PHP streams, the fallback when ext-curl is missing or its
 * functions are disabled.
 *
 * It speaks HTTP/1.1 itself over stream_socket_client(), so it does not
 * need allow_url_fopen, and it honours the same contract as CurlTransport:
 * separate connect and total timeouts, a low speed limit, the body streamed
 * to a temporary file with the same maximum size checks, chunked bodies,
 * gzip asked for when ResponseCollector decodes it, truncated bodies
 * detected, interim responses skipped, redirects followed only to http and
 * https and without the fields bound to the origin (see RedirectTarget),
 * and the connect-to pin with the original Host header and TLS server
 * name.
 *
 * Its limits: one connection per request ("Connection: close"), HTTP/1.1
 * only, no proxy, the addresses of a host name tried one after the other
 * rather than raced, and TLS 1.3 from PHP 7.4 on. As with CurlTransport,
 * the lookup of a host name blocks in the system resolver, beyond the
 * timeouts when the resolver stalls (see HttpClientOptions::withTimeouts()).
 */
final class StreamTransport implements HttpClient
{
    /**
     * The error number of each failure is the one libcurl reports for the
     * same failure (CURLE_*), so that a log or a metric reads alike for
     * both transports, and a TLS failure, for one, is never told from a
     * timeout by its message only. The system error number of a failed
     * connection is in the message.
     */
    public const ERROR_DNS = 6;

    public const ERROR_CONNECT = 7;

    public const ERROR_PROTOCOL = 8;

    public const ERROR_PARTIAL = 18;

    public const ERROR_ABORTED = 23;

    public const ERROR_TIMEOUT = 28;

    public const ERROR_TLS = 35;

    public const ERROR_GOT_NOTHING = 52;

    public const ERROR_SEND = 55;

    public const ERROR_RECEIVE = 56;

    /**
     * @var HttpClientOptions
     */
    private $options;

    /**
     * @var array{0: string, 1: int|null}|null
     */
    private $connectTo;

    /**
     * @var array<string, mixed>
     */
    private $tlsOptions;

    public function __construct(HttpClientOptions $options)
    {
        $this->options = $options;
        $connectTo = $options->getConnectTo();
        $this->connectTo = $connectTo === null ? null : HttpClientOptions::parseAddress($connectTo);

        $tlsOptions = [
            'verify_peer' => $options->shouldVerifyTls(),
            'verify_peer_name' => $options->shouldVerifyTls(),
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'disable_compression' => true,
        ];
        if ($options->getCaFile() !== null) {
            $tlsOptions['cafile'] = $options->getCaFile();
        }
        // TLS 1.2, and TLS 1.3 where PHP knows it (7.4 and later).
        $tlsOptions['crypto_method'] = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? (int) constant('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') : 0);
        $this->tlsOptions = $tlsOptions;
    }

    /**
     * Whether stream_socket_client() is enabled.
     */
    public static function isAvailable(): bool
    {
        return function_exists('stream_socket_client');
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $startedAt = microtime(true);
        $deadline = $startedAt + $this->options->getTimeout();
        $collector = new ResponseCollector($request, $this->options);
        $url = $request->getUrl();
        $origin = self::hostAndPort($url);

        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_WARNING | E_NOTICE);

        $firstUrl = $url;
        $pinnedAddress = null;
        try {
            for ($redirects = 0; ; ++$redirects) {
                $warnings = [];
                $failure = $this->exchange($request, $url, $origin, $pinnedAddress, $collector, $deadline, $warnings);
                if ($failure !== null) {
                    return $collector->finish($failure[0], $failure[1], $failure[2], $url, microtime(true) - $startedAt);
                }
                if (!$collector->isFollowingRedirect()) {
                    return $collector->finish(null, 0, '', $url, microtime(true) - $startedAt);
                }

                $next = RedirectTarget::follow($request, (string) $collector->getHeader('location'));
                if ($next === null) {
                    return $collector->finish(HttpResponse::OUTCOME_OTHER, 0, 'Redirect to an unsupported location', $url, microtime(true) - $startedAt);
                }
                if ($redirects >= $this->options->getMaxRedirects()) {
                    return $collector->finish(
                        HttpResponse::OUTCOME_REDIRECT,
                        0,
                        'Maximum (' . $this->options->getMaxRedirects() . ') redirects followed',
                        $url,
                        microtime(true) - $startedAt
                    );
                }
                $guard = RedirectGuard::check($this->options, $firstUrl, $next->getUrl());
                if (!$guard['allowed']) {
                    return $collector->finish($guard['outcome'], $guard['code'], $guard['error'], $url, microtime(true) - $startedAt);
                }
                $request = $next;
                $url = $request->getUrl();
                $pinnedAddress = $guard['address'];
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * One request and its response, up to the end of the body. The
     * collector receives what arrives.
     *
     * @param array{0: string, 1: int} $origin        host and port the connect-to pin applies to
     * @param string|null              $pinnedAddress the address RedirectGuard checked for the host of $url, null for none
     * @param list<string>             $warnings      the PHP warnings raised so far, filled by the error handler of send()
     *
     * @return array{0: string, 1: int, 2: string}|null the outcome, error number and message of a failure; null on success
     */
    private function exchange(HttpRequest $request, string $url, array $origin, ?string $pinnedAddress, ResponseCollector $collector, float $deadline, array &$warnings): ?array
    {
        list($host, $port) = self::hostAndPort($url);
        $secure = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
        $connectHost = $host;
        $connectPort = $port;
        if ($this->connectTo !== null && $host === $origin[0] && $port === $origin[1]) {
            $connectHost = $this->connectTo[0];
            $connectPort = $this->connectTo[1] ?? $port;
        } elseif ($pinnedAddress !== null) {
            $connectHost = $pinnedAddress;
        }

        $connectDeadline = min($deadline, microtime(true) + $this->options->getConnectTimeout());
        $context = stream_context_create([
            'socket' => ['tcp_nodelay' => true],
            'ssl' => $this->tlsOptions + ['peer_name' => $host],
        ]);
        $errno = 0;
        $errstr = '';
        $socket = stream_socket_client(
            'tcp://' . (strpos($connectHost, ':') !== false ? '[' . $connectHost . ']' : $connectHost) . ':' . $connectPort,
            $errno,
            $errstr,
            max(0.001, $connectDeadline - microtime(true)),
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($socket)) {
            return self::connectFailure(is_int($errno) ? $errno : 0, trim((string) $errstr . ' ' . implode(' ', $warnings)));
        }

        try {
            if ($secure) {
                self::arm($socket, $connectDeadline, (float) $this->options->getLowSpeedTime());
                // The protocol versions are the crypto_method of the context.
                if (stream_socket_enable_crypto($socket, true) !== true) {
                    // OpenSSL reports a handshake that ran out of time as a
                    // warning, without setting the timed_out flag.
                    $handshakeError = implode(' ', $warnings);

                    return self::timedOut($socket) || stripos($handshakeError, 'timed out') !== false
                        ? [HttpResponse::OUTCOME_TIMEOUT, self::ERROR_TIMEOUT, 'The TLS handshake timed out']
                        : [HttpResponse::OUTCOME_TLS, self::ERROR_TLS, trim('The TLS handshake failed. ' . $handshakeError)];
                }
            }

            $failure = $this->writeRequest($socket, $request, $collector->requestsGzip(), $url, $host, $port, $secure, $deadline);

            return $failure ?? $this->readResponse($socket, $collector, $deadline);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    private function writeRequest($socket, HttpRequest $request, bool $gzip, string $url, string $host, int $port, bool $secure, float $deadline): ?array
    {
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        $target = (is_string($path) && $path !== '' ? $path : '/') . (is_string($query) ? '?' . $query : '');

        $headers = $request->getHeaders();
        $head = $request->getMethod() . ' ' . $target . " HTTP/1.1\r\n";
        if (!isset($headers['host'])) {
            $head .= 'host: ' . (strpos($host, ':') !== false ? '[' . $host . ']' : $host) . ($port === ($secure ? 443 : 80) ? '' : ':' . $port) . "\r\n";
        }
        foreach ($headers as $name => $value) {
            if ($name !== 'connection') {
                $head .= $name . ': ' . $value . "\r\n";
            }
        }
        if (!isset($headers['user-agent'])) {
            $head .= 'user-agent: ' . $this->options->getUserAgent() . "\r\n";
        }
        if ($gzip) {
            $head .= "accept-encoding: gzip\r\n";
        }
        $head .= "connection: close\r\n\r\n";

        $length = strlen($head);
        for ($written = 0; $written < $length; $written += $count) {
            self::arm($socket, $deadline, (float) $this->options->getLowSpeedTime());
            $count = fwrite($socket, substr($head, $written));
            if (!is_int($count) || $count === 0) {
                return self::timedOut($socket)
                    ? [HttpResponse::OUTCOME_TIMEOUT, self::ERROR_TIMEOUT, 'Timed out sending the request']
                    : [HttpResponse::OUTCOME_NETWORK, self::ERROR_SEND, 'Failed sending the request'];
            }
        }

        return null;
    }

    /**
     * Reads the header blocks up to the final response or a redirect to
     * follow, then the body when the collector wants it.
     *
     * @param resource $socket
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    private function readResponse($socket, ResponseCollector $collector, float $deadline): ?array
    {
        $reader = new ResponseReader($socket, $collector, $deadline, $this->options->getLowSpeedLimit(), (float) $this->options->getLowSpeedTime());
        $received = false;
        while (true) {
            $line = $reader->line();
            if (is_array($line)) {
                return $line;
            }
            if ($line === null) {
                return $received
                    ? [HttpResponse::OUTCOME_NETWORK, self::ERROR_RECEIVE, 'The connection was closed in the response header block']
                    : [HttpResponse::OUTCOME_CONNECT, self::ERROR_GOT_NOTHING, 'Empty reply from server'];
            }
            if (substr($line, -1) !== "\n") {
                return [HttpResponse::OUTCOME_OTHER, self::ERROR_PROTOCOL, 'A response header line is too long or unterminated'];
            }
            if (!$received && strncmp($line, 'HTTP/', 5) !== 0) {
                return [HttpResponse::OUTCOME_OTHER, self::ERROR_PROTOCOL, 'The response does not start with an HTTP status line'];
            }
            $received = true;
            if (!$collector->headerLine($line)) {
                return [HttpResponse::OUTCOME_OTHER, self::ERROR_ABORTED, 'Aborted'];
            }
            if (rtrim($line, "\r\n") === '' && ($collector->isFinal() || $collector->isFollowingRedirect())) {
                break;
            }
        }

        if (!$collector->wantsBody()) {
            // A redirect, HEAD, or a status whose body is discarded: the
            // connection is closed anyway.
            return null;
        }

        $transferEncoding = strtolower((string) $collector->getHeader('transfer-encoding'));
        $length = $collector->getHeader('content-length');
        if (strpos($transferEncoding, 'chunked') !== false) {
            $failure = $reader->readChunked();
        } elseif ($length !== null && preg_match('/\A[0-9]+\z/', $length) === 1) {
            $failure = $reader->readLength((int) $length);
        } else {
            $failure = $reader->readUntilClose();
        }

        return $failure;
    }

    /**
     * Sets the timeout of the next blocking read or write: the low speed
     * time, or less when the deadline is closer.
     *
     * @internal for ResponseReader
     *
     * @param resource $socket
     */
    public static function arm($socket, float $deadline, float $lowSpeedTime): void
    {
        $timeout = max(0.001, min($lowSpeedTime, $deadline - microtime(true)));
        $seconds = (int) $timeout;
        stream_set_timeout($socket, $seconds, (int) (($timeout - $seconds) * 1000000));
    }

    /**
     * Whether the last read or write on $socket timed out.
     *
     * @internal for ResponseReader
     *
     * @param resource $socket
     */
    public static function timedOut($socket): bool
    {
        $meta = stream_get_meta_data($socket);

        return $meta['timed_out'];
    }

    /**
     * The host, without brackets, and the port of an http or https URL.
     *
     * @return array{0: string, 1: int}
     */
    private static function hostAndPort(string $url): array
    {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $port = parse_url($url, PHP_URL_PORT);
        if (!is_int($port)) {
            $port = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80;
        }

        return [strtolower($host), $port];
    }

    /**
     * Classifies a failed stream_socket_client(). The system error number
     * goes into the message: the error number is the libcurl one.
     *
     * @return array{0: string, 1: int, 2: string}
     */
    private static function connectFailure(int $errno, string $message): array
    {
        $lower = strtolower($message);
        if ($message === '') {
            $message = 'Connection failed';
        }
        if ($errno !== 0) {
            $message .= ' (errno ' . $errno . ')';
        }
        if ($errno === 110 || strpos($lower, 'timed out') !== false) {
            return [HttpResponse::OUTCOME_TIMEOUT, self::ERROR_TIMEOUT, $message];
        }
        foreach (['getaddrinfo', 'name or service not known', 'no address associated', 'name resolution', 'nodename nor servname'] as $needle) {
            if (strpos($lower, $needle) !== false) {
                return [HttpResponse::OUTCOME_DNS, self::ERROR_DNS, $message];
            }
        }

        return [HttpResponse::OUTCOME_CONNECT, self::ERROR_CONNECT, $message];
    }
}
