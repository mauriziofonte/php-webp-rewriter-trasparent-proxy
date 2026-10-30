<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin\Legacy;

use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Origin\OriginFetcher;
use Mfonte\ProxyCdn\Origin\OriginRequest;
use Mfonte\ProxyCdn\Origin\OriginResponse;
use Psr\Log\LoggerInterface;

/**
 * Interim port of the 2.x getRemoteFile(): one buffered cURL GET with the
 * 2.x options, headers and status semantics, unchanged.
 *
 * It keeps the known 2.x defects pinned by the characterization tests: the
 * Firefox 81 User-Agent, IPv4 only, port 443 assumed when an http origin is
 * pinned with SOURCE_ORIGIN_SERVER (finding P-22), the whole body in
 * memory, and every status other than 200 and 404 treated as a failure
 * (finding P-13).
 *
 * @internal replaced by HttpClient and CurlTransport in PRX-4; do not build on it
 */
final class CurlOriginFetcher implements OriginFetcher
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:81.0) Gecko/20100101 Firefox/81.0';

    /**
     * cURL error constants and the failure reason they map to. Looked up by
     * name because some of them depend on the libcurl build.
     */
    private const ERROR_REASONS = [
        'CURLE_COULDNT_RESOLVE_HOST' => OriginResponse::FAILURE_DNS,
        'CURLE_COULDNT_CONNECT' => OriginResponse::FAILURE_CONNECT,
        'CURLE_OPERATION_TIMEOUTED' => OriginResponse::FAILURE_TIMEOUT,
        'CURLE_SSL_CONNECT_ERROR' => OriginResponse::FAILURE_TLS,
        'CURLE_PEER_FAILED_VERIFICATION' => OriginResponse::FAILURE_TLS,
        'CURLE_SSL_CERTPROBLEM' => OriginResponse::FAILURE_TLS,
        'CURLE_SSL_CIPHER' => OriginResponse::FAILURE_TLS,
        'CURLE_SSL_CACERT' => OriginResponse::FAILURE_TLS,
        'CURLE_GOT_NOTHING' => OriginResponse::FAILURE_CONNECT,
        'CURLE_SEND_ERROR' => OriginResponse::FAILURE_NETWORK,
        'CURLE_RECV_ERROR' => OriginResponse::FAILURE_NETWORK,
        'CURLE_PARTIAL_FILE' => OriginResponse::FAILURE_NETWORK,
    ];

    /**
     * @var int
     */
    private $connectTimeout;

    /**
     * @var int
     */
    private $timeout;

    /**
     * @var bool
     */
    private $verifyTls;

    /**
     * @var bool
     */
    private $followRedirects;

    /**
     * @var string|null
     */
    private $pinnedServer;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param int         $connectTimeout seconds the connection may take
     * @param int         $timeout        seconds the whole transfer may take
     * @param string|null $pinnedServer   "address:port" every connection goes to, bypassing DNS
     */
    public function __construct(
        int $connectTimeout,
        int $timeout,
        bool $verifyTls,
        bool $followRedirects,
        ?string $pinnedServer,
        LoggerInterface $logger
    ) {
        $this->connectTimeout = $connectTimeout;
        $this->timeout = $timeout;
        $this->verifyTls = $verifyTls;
        $this->followRedirects = $followRedirects;
        $this->pinnedServer = $pinnedServer;
        $this->logger = $logger;
    }

    public static function fromConfig(Config $config, LoggerInterface $logger): self
    {
        return new self(
            $config->getUpstreamConnectTimeout(),
            $config->getUpstreamTimeout(),
            $config->shouldVerifyUpstreamTls(),
            $config->shouldFollowUpstreamRedirects(),
            $config->getSourceOriginServer(),
            $logger
        );
    }

    public function fetch(OriginRequest $request): OriginResponse
    {
        $url = $request->getUrl();
        $handle = curl_init();
        if ($handle === false) {
            return $this->transportFailure($url, 0, 0, 'curl_init() failed');
        }
        curl_setopt_array($handle, $this->buildOptions($request));

        $response = @curl_exec($handle);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        $error = curl_error($handle);

        if ($errno !== 0) {
            return $this->transportFailure($url, $status, $errno, $error);
        }

        if ($status === 404) {
            $this->logger->info('upstream 404', ['url' => $url]);

            return OriginResponse::failure(OriginResponse::FAILURE_NOT_FOUND, 404);
        }

        if ($status !== 200 || !is_string($response)) {
            $this->logger->error('upstream non-200', ['url' => $url, 'response_code' => $status]);

            return OriginResponse::failure($status >= 500 ? OriginResponse::FAILURE_SERVER_ERROR : OriginResponse::FAILURE_OTHER, $status);
        }

        return OriginResponse::success(
            self::parseHeaders((string) substr($response, 0, $headerSize)),
            (string) substr($response, $headerSize)
        );
    }

    /**
     * The cURL options of a request, as 2.x set them.
     *
     * @return array<int, mixed>
     */
    public function buildOptions(OriginRequest $request): array
    {
        $headers = ['Expect:'];
        foreach ($request->getHeaders() as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $headers[] = 'Pragma: no-cache';
        $headers[] = 'Cache-Control: no-cache';
        $headers[] = 'User-Agent: ' . self::USER_AGENT;

        $options = [
            CURLOPT_URL => $request->getUrl(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HEADER => true,
            CURLOPT_VERBOSE => false,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $this->followRedirects,
            CURLOPT_MAXREDIRS => $this->followRedirects ? 10 : 0,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            // A transfer stuck under 1 KB/s for 10 seconds is aborted, so a
            // slow origin cannot pin every worker until the timeout.
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 10,
        ];

        if ($this->pinnedServer !== null) {
            $host = parse_url($request->getUrl(), PHP_URL_HOST);
            $port = parse_url($request->getUrl(), PHP_URL_PORT);
            $options[CURLOPT_CONNECT_TO] = [
                (is_string($host) ? $host : '') . ':' . (is_int($port) ? $port : 443) . ':' . $this->pinnedServer,
            ];
        }

        return $options;
    }

    /**
     * The failure reason of a cURL error number.
     */
    public static function reasonOf(int $errno): string
    {
        foreach (self::ERROR_REASONS as $name => $reason) {
            if (defined($name) && constant($name) === $errno) {
                return $reason;
            }
        }

        return OriginResponse::FAILURE_OTHER;
    }

    /**
     * The header fields of a raw header block, keyed by lower-case name.
     * As in 2.x the blocks of every response of a redirect chain are read,
     * and the last occurrence of a field wins.
     *
     * @return array<string, string>
     */
    public static function parseHeaders(string $headerBlock): array
    {
        $headers = [];
        $lines = explode("\n", trim(str_replace(["\r\n", "\r"], "\n", $headerBlock)));
        foreach ($lines as $line) {
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            list($name, $value) = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }

    private function transportFailure(string $url, int $status, int $errno, string $error): OriginResponse
    {
        $reason = self::reasonOf($errno);
        $this->logger->error('upstream fetch failed', [
            'url' => $url,
            'reason' => $reason,
            'curl_errno' => $errno,
            'curl_error' => $error,
            'response_code' => $status,
        ]);

        return OriginResponse::failure($reason, $status, $errno, $error);
    }
}
