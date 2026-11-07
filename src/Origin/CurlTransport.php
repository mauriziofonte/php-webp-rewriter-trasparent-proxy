<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * HttpClient on ext-curl, the preferred transport.
 *
 * - The body is streamed to a temporary file through the write callback;
 *   the header callback ends the transfer early when Content-Length exceeds
 *   the maximum size, and the write callback when the decoded bytes do.
 * - HTTP/2 is negotiated through ALPN on https when libcurl supports it
 *   (CURL_HTTP_VERSION_2TLS); http stays on HTTP/1.1. With libcurl 7.74
 *   and later, transfers of MultiClient wait for a connection they can
 *   multiplex (CURLOPT_PIPEWAIT).
 * - TCP_NODELAY is set, and IPv4 and IPv6 are both used (libcurl races
 *   them). libcurl gives up a host name lookup at the timeouts, but waits
 *   for its resolver thread to end before it returns, so a stalled
 *   resolver delays the failure (see HttpClientOptions::withTimeouts()).
 * - libcurl does not decode content codings: ResponseCollector asks for
 *   gzip and inflates it, so that a gzip stream cut short inside complete
 *   HTTP framing is a truncated body, which libcurl would not report.
 * - Only http and https are allowed. Redirects are followed by the
 *   transport, one transfer each, with the policy of RedirectTarget.
 * - One easy handle is reused for every send(), and a share handle shares
 *   DNS answers, TLS sessions and connections: on PHP 8.5 and later it is
 *   persistent (curl_share_init_persistent), so connections to the origin
 *   survive from one PHP request to the next in the same worker.
 *
 * The libcurl options that do not depend on the request are computed once,
 * in the constructor.
 */
final class CurlTransport implements HttpClient
{
    /**
     * libcurl error numbers and the outcome they map to. The numbers are
     * part of the libcurl ABI; several have no PHP constant on every
     * supported PHP version.
     */
    private const ERROR_OUTCOMES = [
        5 => HttpResponse::OUTCOME_DNS,           // CURLE_COULDNT_RESOLVE_PROXY
        6 => HttpResponse::OUTCOME_DNS,           // CURLE_COULDNT_RESOLVE_HOST
        7 => HttpResponse::OUTCOME_CONNECT,       // CURLE_COULDNT_CONNECT
        16 => HttpResponse::OUTCOME_NETWORK,      // CURLE_HTTP2
        18 => HttpResponse::OUTCOME_NETWORK,      // CURLE_PARTIAL_FILE
        28 => HttpResponse::OUTCOME_TIMEOUT,      // CURLE_OPERATION_TIMEDOUT
        35 => HttpResponse::OUTCOME_TLS,          // CURLE_SSL_CONNECT_ERROR
        47 => HttpResponse::OUTCOME_REDIRECT,     // CURLE_TOO_MANY_REDIRECTS
        51 => HttpResponse::OUTCOME_TLS,          // CURLE_PEER_FAILED_VERIFICATION (libcurl < 7.62)
        52 => HttpResponse::OUTCOME_CONNECT,      // CURLE_GOT_NOTHING
        53 => HttpResponse::OUTCOME_TLS,          // CURLE_SSL_ENGINE_NOTFOUND
        54 => HttpResponse::OUTCOME_TLS,          // CURLE_SSL_ENGINE_SETFAILED
        55 => HttpResponse::OUTCOME_NETWORK,      // CURLE_SEND_ERROR
        56 => HttpResponse::OUTCOME_NETWORK,      // CURLE_RECV_ERROR
        58 => HttpResponse::OUTCOME_TLS,          // CURLE_SSL_CERTPROBLEM
        59 => HttpResponse::OUTCOME_TLS,          // CURLE_SSL_CIPHER
        60 => HttpResponse::OUTCOME_TLS,          // CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CACERT
        63 => HttpResponse::OUTCOME_TOO_LARGE,    // CURLE_FILESIZE_EXCEEDED
        64 => HttpResponse::OUTCOME_TLS,          // CURLE_USE_SSL_FAILED
        66 => HttpResponse::OUTCOME_TLS,          // CURLE_SSL_ENGINE_INITFAILED
        77 => HttpResponse::OUTCOME_TLS,          // CURLE_SSL_CACERT_BADFILE
        80 => HttpResponse::OUTCOME_TLS,          // CURLE_SSL_SHUTDOWN_FAILED
        82 => HttpResponse::OUTCOME_TLS,          // CURLE_SSL_CRL_BADFILE
        83 => HttpResponse::OUTCOME_TLS,          // CURLE_SSL_ISSUER_ERROR
        90 => HttpResponse::OUTCOME_TLS,          // CURLE_SSL_PINNEDPUBKEYNOTMATCH
        91 => HttpResponse::OUTCOME_TLS,          // CURLE_SSL_INVALIDCERTSTATUS
        92 => HttpResponse::OUTCOME_NETWORK,      // CURLE_HTTP2_STREAM
        98 => HttpResponse::OUTCOME_TLS,          // CURLE_SSL_CLIENTCERT
    ];

    /**
     * @var HttpClientOptions
     */
    private $options;

    /**
     * @var array<int, mixed>
     */
    private $curlOptions;

    /**
     * @var string
     */
    private $userAgentLine;

    /**
     * @var array{0: string, 1: int|null}|null
     */
    private $connectTo;

    /**
     * @var CurlHandleOrResource|null
     */
    private $handle;

    /**
     * @param bool $share whether the handles share DNS answers, TLS sessions and connections through
     *                    createShareHandle(); false keeps each handle on its own caches
     */
    public function __construct(HttpClientOptions $options, bool $share = true)
    {
        $this->options = $options;
        $this->userAgentLine = 'user-agent: ' . $options->getUserAgent();
        $connectTo = $options->getConnectTo();
        $this->connectTo = $connectTo === null ? null : HttpClientOptions::parseAddress($connectTo);

        $curlOptions = [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($options->getConnectTimeout() * 1000),
            CURLOPT_TIMEOUT_MS => (int) ceil($options->getTimeout() * 1000),
            CURLOPT_LOW_SPEED_LIMIT => $options->getLowSpeedLimit(),
            CURLOPT_LOW_SPEED_TIME => $options->getLowSpeedTime(),
            CURLOPT_SSL_VERIFYPEER => $options->shouldVerifyTls(),
            CURLOPT_SSL_VERIFYHOST => $options->shouldVerifyTls() ? 2 : 0,
            // Redirects are followed by endTransfer().
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_WHATEVER,
            CURLOPT_TCP_NODELAY => true,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_BUFFERSIZE => 65536,
            CURLOPT_HEADER => false,
            CURLOPT_HTTP_CONTENT_DECODING => false,
            CURLOPT_HTTP_VERSION => $options->isHttp2Enabled() && self::supportsHttp2() ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_1_1,
        ];
        if ($curlOptions[CURLOPT_HTTP_VERSION] === CURL_HTTP_VERSION_2TLS && self::waitsForMultiplexingSafely()) {
            // In MultiClient, wait for a connection that can multiplex
            // rather than opening a second one to the same origin.
            $curlOptions[CURLOPT_PIPEWAIT] = true;
        }
        if ($options->getCaFile() !== null) {
            $curlOptions[CURLOPT_CAINFO] = $options->getCaFile();
        }
        if (defined('CURLOPT_HTTP09_ALLOWED')) {
            // libcurl before 7.66 takes any garbage for an HTTP/0.9 body.
            $curlOptions[(int) constant('CURLOPT_HTTP09_ALLOWED')] = false;
        }
        $shareHandle = $share ? self::createShareHandle() : null;
        if ($shareHandle !== null) {
            $curlOptions[CURLOPT_SHARE] = $shareHandle;
        }
        $this->curlOptions = $curlOptions;
    }

    /**
     * Whether ext-curl is loaded and its transfer functions are enabled.
     */
    public static function isAvailable(): bool
    {
        return function_exists('curl_init') && function_exists('curl_exec') && function_exists('curl_setopt_array');
    }

    /**
     * Whether libcurl was built with HTTP/2.
     */
    public static function supportsHttp2(): bool
    {
        $version = curl_version();
        $features = is_array($version) && isset($version['features']) && is_int($version['features']) ? $version['features'] : 0;

        return ($features & CURL_VERSION_HTTP2) !== 0;
    }

    /**
     * Whether CURLOPT_PIPEWAIT only delays transfers that can share an
     * HTTP/2 connection. libcurl 7.64 (Debian 10, the PHP 7.2 and 7.3
     * images) makes every transfer to the same host name wait for the
     * previous one, whatever the port, scheme or negotiated protocol;
     * 7.74 (Debian 11) and later do not.
     */
    public static function waitsForMultiplexingSafely(): bool
    {
        $version = curl_version();
        $number = is_array($version) && isset($version['version_number']) && is_int($version['version_number']) ? $version['version_number'] : 0;

        return $number >= 0x074A00;
    }

    /**
     * A share handle for DNS answers, TLS sessions and connections.
     *
     * On PHP 8.5 and later it is persistent: PHP keeps it for the life of
     * the worker and returns the same one for the same options, so the
     * caches outlive the PHP request. Before, it lives as long as the
     * transport, and connections are shared only when the curl extension
     * exposes CURL_LOCK_DATA_CONNECT (PHP 7.3 and later); on PHP 7.2 they
     * are reused through the easy handle that send() keeps.
     *
     * @return CurlShareHandleOrResource|null null when libcurl refuses to share
     */
    public static function createShareHandle()
    {
        if (function_exists('curl_share_init_persistent')) {
            try {
                return curl_share_init_persistent([CURL_LOCK_DATA_DNS, (int) constant('CURL_LOCK_DATA_CONNECT'), CURL_LOCK_DATA_SSL_SESSION]);
            } catch (\Throwable $exception) {
                return null;
            }
        }

        $share = curl_share_init();
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
        if (defined('CURL_LOCK_DATA_CONNECT')) {
            curl_share_setopt($share, CURLSHOPT_SHARE, (int) constant('CURL_LOCK_DATA_CONNECT'));
        }

        return $share;
    }

    /**
     * The libcurl options applied to every transfer, keyed by CURLOPT_*.
     *
     * @return array<int, mixed>
     */
    public function getCurlOptions(): array
    {
        return $this->curlOptions;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        if ($this->handle === null) {
            $this->handle = $this->createHandle();
        }
        $handle = $this->handle;
        if ($handle === null) {
            return (new ResponseCollector($request, $this->options))
                ->finish(HttpResponse::OUTCOME_OTHER, 0, 'The cURL handle cannot be created', $request->getUrl(), 0.0);
        }

        $transfer = $this->startTransfer($handle, $request);
        do {
            curl_exec($handle);
            $response = $this->endTransfer($transfer, curl_errno($handle));
        } while ($response === null);

        return $response;
    }

    /**
     * A new easy handle with the options of every transfer, or null when
     * libcurl refuses them.
     *
     * @internal for MultiClient
     *
     * @return CurlHandleOrResource|null
     */
    public function createHandle()
    {
        $handle = curl_init();
        if ($handle === false || !curl_setopt_array($handle, $this->curlOptions)) {
            return null;
        }

        return $handle;
    }

    /**
     * Sets the options of $request on $handle, ready for curl_exec() or
     * curl_multi_add_handle().
     *
     * @internal for MultiClient
     *
     * @param CurlHandleOrResource $handle a handle from createHandle()
     */
    public function startTransfer($handle, HttpRequest $request): CurlTransfer
    {
        $transfer = new CurlTransfer($handle, $request, new ResponseCollector($request, $this->options));
        $this->configure($transfer);

        return $transfer;
    }

    /**
     * Ends a transfer of $transfer: its response, or null when the
     * transfer was a redirect to follow and the handle is set for the next
     * one, to run the same way.
     *
     * Redirects are followed here rather than by libcurl, so that every
     * transport applies the policy of RedirectTarget: libcurl before 7.83
     * (the PHP 7.2 and 7.3 images) sends Authorization and Cookie to
     * another port of the same host, and every libcurl sends
     * Proxy-Authorization, and a custom Host to another port, along.
     *
     * @internal for MultiClient
     *
     * @param int $errno the CURLE_* result of the transfer, 0 on success
     */
    public function endTransfer(CurlTransfer $transfer, int $errno): ?HttpResponse
    {
        $collector = $transfer->collector;
        $url = (string) curl_getinfo($transfer->handle, CURLINFO_EFFECTIVE_URL);
        // A followed redirect ends with CURLE_WRITE_ERROR (23) when its
        // body was too large to read.
        if (($errno === 0 || $errno === 23) && $collector->isFollowingRedirect()) {
            $next = RedirectTarget::follow($transfer->request, (string) $collector->getHeader('location'));
            if ($next === null) {
                return $collector->finish(HttpResponse::OUTCOME_OTHER, 0, 'Redirect to an unsupported location', $url, microtime(true) - $transfer->startedAt);
            }
            if ($transfer->redirects >= $this->options->getMaxRedirects()) {
                return $collector->finish(
                    HttpResponse::OUTCOME_REDIRECT,
                    0,
                    'Maximum (' . $this->options->getMaxRedirects() . ') redirects followed',
                    $url,
                    microtime(true) - $transfer->startedAt
                );
            }
            $guard = RedirectGuard::check($this->options, $transfer->originUrl, $next->getUrl());
            if (!$guard['allowed']) {
                return $collector->finish($guard['outcome'], $guard['code'], $guard['error'], $url, microtime(true) - $transfer->startedAt);
            }
            ++$transfer->redirects;
            $transfer->request = $next;
            $transfer->pinnedAddress = $guard['address'];
            $this->configure($transfer);

            return null;
        }

        $failure = null;
        $error = '';
        if ($errno !== 0) {
            $failure = self::ERROR_OUTCOMES[$errno] ?? HttpResponse::OUTCOME_OTHER;
            $error = curl_error($transfer->handle);
            if ($error === '') {
                $error = (string) curl_strerror($errno);
            }
        }

        return $collector->finish($failure, $errno, $error, $url, microtime(true) - $transfer->startedAt);
    }

    /**
     * Sets the options of the current request of $transfer on its handle:
     * URL, header fields, callbacks, method, the time left before the
     * deadline of the first transfer, and the connect-to pin of its origin.
     */
    private function configure(CurlTransfer $transfer): void
    {
        $request = $transfer->request;
        $collector = $transfer->collector;

        $headers = [];
        foreach ($request->getHeaders() as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        if ($request->getHeader('user-agent') === null) {
            $headers[] = $this->userAgentLine;
        }
        if ($collector->requestsGzip()) {
            $headers[] = 'accept-encoding: gzip';
        }

        $timeLeft = $transfer->startedAt + $this->options->getTimeout() - microtime(true);
        $options = [
            CURLOPT_URL => $request->getUrl(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT_MS => max(1, (int) ceil($timeLeft * 1000)),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use ($collector): int {
                return $collector->headerLine($line) ? strlen($line) : 0;
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use ($collector): int {
                return $collector->bodyChunk($data) ? strlen($data) : 0;
            },
        ];
        if ($request->getMethod() === HttpRequest::METHOD_HEAD) {
            $options[CURLOPT_NOBODY] = true;
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }
        $connectTo = [];
        if ($this->connectTo !== null) {
            // Pinned on the host and port of the first URL: libcurl ignores
            // the entry for a redirect to another one.
            $connectTo[] = self::connectToEntry($transfer->originUrl, $this->connectTo);
        }
        if ($transfer->pinnedAddress !== null) {
            // The address RedirectGuard checked, so that DNS is not asked again.
            $connectTo[] = self::connectToEntry($request->getUrl(), [$transfer->pinnedAddress, null]);
        }
        $options[CURLOPT_CONNECT_TO] = $connectTo;
        curl_setopt_array($transfer->handle, $options);
    }

    /**
     * The CURLOPT_CONNECT_TO entry that pins the host and port of $url,
     * the port being the default of the scheme when the URL has none
     * (2.x assumed 443 even for http, finding P-22).
     *
     * @param array{0: string, 1: int|null} $address
     */
    public static function connectToEntry(string $url, array $address): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        if (!is_int($port)) {
            $port = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80;
        }
        $target = strpos($address[0], ':') !== false ? '[' . $address[0] . ']' : $address[0];

        return $host . ':' . $port . ':' . $target . ':' . ($address[1] ?? '');
    }
}
