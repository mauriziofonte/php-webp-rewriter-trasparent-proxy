<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

use Mfonte\ProxyCdn\Exception\InvalidArgumentException;

/**
 * Settings shared by every request of an HttpClient. Immutable: the with*()
 * methods return modified copies and validate their argument.
 *
 * The defaults suit an origin fetch: TLS verified, redirects not followed,
 * compression and HTTP/2 negotiated when available, bodies up to 64 MiB, and
 * an honest User-Agent that names this software (finding P-22).
 */
final class HttpClientOptions
{
    public const DEFAULT_USER_AGENT = 'mfonte-proxy-cdn/3 (+https://github.com/mauriziofonte/php-webp-rewriter-trasparent-proxy)';

    public const DEFAULT_MAX_BODY_SIZE = 67108864;

    /**
     * @var float
     */
    private $connectTimeout = 5.0;

    /**
     * @var float
     */
    private $timeout = 30.0;

    /**
     * @var int
     */
    private $lowSpeedLimit = 1024;

    /**
     * @var int
     */
    private $lowSpeedTime = 10;

    /**
     * @var bool
     */
    private $verifyTls = true;

    /**
     * @var string|null
     */
    private $caFile;

    /**
     * @var bool
     */
    private $followRedirects = false;

    /**
     * @var int
     */
    private $maxRedirects = 5;

    /**
     * @var int
     */
    private $maxBodySize = self::DEFAULT_MAX_BODY_SIZE;

    /**
     * @var string
     */
    private $userAgent = self::DEFAULT_USER_AGENT;

    /**
     * @var string|null
     */
    private $connectTo;

    /**
     * @var bool
     */
    private $compression = true;

    /**
     * @var bool
     */
    private $http2 = true;

    /**
     * @var string|null
     */
    private $temporaryDirectory;

    /**
     * Seconds the connection (TCP and TLS handshake) may take.
     */
    public function getConnectTimeout(): float
    {
        return $this->connectTimeout;
    }

    /**
     * Seconds a whole request, redirects included, may take.
     */
    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
     * @param float $connectTimeout seconds the connection may take, more than 0
     * @param float $timeout        seconds the whole request may take, at least $connectTimeout
     *
     * @throws InvalidArgumentException
     */
    public function withTimeouts(float $connectTimeout, float $timeout): self
    {
        if (!($connectTimeout > 0.0) || $timeout < $connectTimeout) {
            throw new InvalidArgumentException('The timeouts must be positive, and the total one at least the connect one');
        }
        $copy = clone $this;
        $copy->connectTimeout = $connectTimeout;
        $copy->timeout = $timeout;

        return $copy;
    }

    /**
     * Bytes per second under which a transfer counts as stalled.
     */
    public function getLowSpeedLimit(): int
    {
        return $this->lowSpeedLimit;
    }

    /**
     * Seconds a transfer may stay under the low speed limit before it is
     * aborted as a timeout.
     */
    public function getLowSpeedTime(): int
    {
        return $this->lowSpeedTime;
    }

    /**
     * Aborts a transfer slower than $bytesPerSecond for $seconds seconds, so
     * that a slow origin cannot hold a worker until the total timeout.
     *
     * @param int $bytesPerSecond at least 1
     * @param int $seconds        at least 1
     *
     * @throws InvalidArgumentException
     */
    public function withLowSpeedLimit(int $bytesPerSecond, int $seconds): self
    {
        if ($bytesPerSecond < 1 || $seconds < 1) {
            throw new InvalidArgumentException('The low speed limit and time must be at least 1');
        }
        $copy = clone $this;
        $copy->lowSpeedLimit = $bytesPerSecond;
        $copy->lowSpeedTime = $seconds;

        return $copy;
    }

    /**
     * Whether the certificate and the host name of TLS servers are verified.
     */
    public function shouldVerifyTls(): bool
    {
        return $this->verifyTls;
    }

    /**
     * The PEM file of the trusted certificate authorities; null for the
     * system bundle.
     */
    public function getCaFile(): ?string
    {
        return $this->caFile;
    }

    /**
     * @param string|null $caFile PEM file of the trusted certificate authorities; null for the system bundle
     */
    public function withTlsVerification(bool $verify, ?string $caFile = null): self
    {
        $copy = clone $this;
        $copy->verifyTls = $verify;
        $copy->caFile = $caFile;

        return $copy;
    }

    /**
     * Whether redirects are followed. When they are not, a 3xx response is
     * returned as it is, with its Location.
     */
    public function shouldFollowRedirects(): bool
    {
        return $this->followRedirects;
    }

    /**
     * Redirects followed at most; one more is reported as a redirect.
     */
    public function getMaxRedirects(): int
    {
        return $this->maxRedirects;
    }

    /**
     * @param int $maxRedirects redirects followed at most, 0 to 50
     *
     * @throws InvalidArgumentException
     */
    public function withRedirects(bool $follow, int $maxRedirects = 5): self
    {
        if ($maxRedirects < 0 || $maxRedirects > 50) {
            throw new InvalidArgumentException('The maximum number of redirects must be between 0 and 50');
        }
        $copy = clone $this;
        $copy->followRedirects = $follow;
        $copy->maxRedirects = $maxRedirects;

        return $copy;
    }

    /**
     * Bytes a response body may have, after decoding; a larger body aborts
     * the transfer with HttpResponse::OUTCOME_TOO_LARGE.
     */
    public function getMaxBodySize(): int
    {
        return $this->maxBodySize;
    }

    /**
     * @param int $bytes at least 1
     *
     * @throws InvalidArgumentException
     */
    public function withMaxBodySize(int $bytes): self
    {
        if ($bytes < 1) {
            throw new InvalidArgumentException('The maximum body size must be at least 1 byte');
        }
        $copy = clone $this;
        $copy->maxBodySize = $bytes;

        return $copy;
    }

    /**
     * The User-Agent of requests that do not set their own.
     */
    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * @param string $userAgent 1 to 512 printable ASCII characters
     *
     * @throws InvalidArgumentException
     */
    public function withUserAgent(string $userAgent): self
    {
        if (!self::isUserAgent($userAgent)) {
            throw new InvalidArgumentException('The User-Agent must be 1 to 512 printable ASCII characters');
        }
        $copy = clone $this;
        $copy->userAgent = $userAgent;

        return $copy;
    }

    /**
     * Whether $userAgent can be sent as a User-Agent: 1 to 512 printable
     * ASCII characters.
     */
    public static function isUserAgent(string $userAgent): bool
    {
        return preg_match('/\A[\x20-\x7E]{1,512}\z/', $userAgent) === 1;
    }

    /**
     * The address every connection to the host and port of a request URL is
     * made to instead, "address" or "address:port"; null to follow DNS.
     */
    public function getConnectTo(): ?string
    {
        return $this->connectTo;
    }

    /**
     * Pins the connections of every request to an address: the URL, the Host
     * header and the TLS server name keep the original host. The pin applies
     * to the host and port of the request URL, the port being the default
     * of its scheme when the URL has none; a redirect to another host or port
     * follows DNS.
     *
     * @param string|null $connectTo host name, IPv4 address or bracketed IPv6 address, optionally followed by
     *                               ":port"; without a port the URL's port is kept. Null to follow DNS
     *
     * @throws InvalidArgumentException when the address is malformed
     */
    public function withConnectTo(?string $connectTo): self
    {
        if ($connectTo !== null && self::parseAddress($connectTo) === null) {
            throw new InvalidArgumentException('The connect-to address must be "host", "host:port" or "[ipv6]:port"');
        }
        $copy = clone $this;
        $copy->connectTo = $connectTo;

        return $copy;
    }

    /**
     * Splits "host", "host:port", "[ipv6]" or "[ipv6]:port" into the host,
     * without brackets, and the port (null when absent); null when the
     * address is malformed.
     *
     * @return array{0: string, 1: int|null}|null
     */
    public static function parseAddress(string $address): ?array
    {
        if (preg_match('/\A(?:\[([0-9A-Fa-f:.]+)\]|([A-Za-z0-9.-]+))(?::([0-9]{1,5}))?\z/', $address, $matches) !== 1) {
            return null;
        }
        $ipv6 = $matches[1] ?? '';
        $host = $ipv6 !== '' ? $ipv6 : ($matches[2] ?? '');
        $port = isset($matches[3]) ? (int) $matches[3] : null;
        if ($host === '' || ($port !== null && ($port < 1 || $port > 65535))) {
            return null;
        }
        if ($ipv6 !== '' && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        return [$host, $port];
    }

    /**
     * Whether the transport asks for compressed bodies and decodes them.
     */
    public function isCompressionEnabled(): bool
    {
        return $this->compression;
    }

    public function withCompression(bool $enabled): self
    {
        $copy = clone $this;
        $copy->compression = $enabled;

        return $copy;
    }

    /**
     * Whether HTTP/2 is negotiated (through ALPN, https only) when the
     * transport supports it.
     */
    public function isHttp2Enabled(): bool
    {
        return $this->http2;
    }

    public function withHttp2(bool $enabled): self
    {
        $copy = clone $this;
        $copy->http2 = $enabled;

        return $copy;
    }

    /**
     * The directory response bodies are written to: the system temporary
     * directory unless another one was set.
     */
    public function getTemporaryDirectory(): string
    {
        return $this->temporaryDirectory ?? sys_get_temp_dir();
    }

    /**
     * Bodies are streamed to files in $directory. Choose a directory on the
     * filesystem of their final location, so that moving them is a rename.
     *
     * @param string|null $directory existing writable directory; null for the system temporary directory
     */
    public function withTemporaryDirectory(?string $directory): self
    {
        $copy = clone $this;
        $copy->temporaryDirectory = $directory;

        return $copy;
    }
}
