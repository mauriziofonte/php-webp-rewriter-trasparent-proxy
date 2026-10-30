<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

use Mfonte\ProxyCdn\Exception\InvalidArgumentException;

/**
 * A GET or HEAD request sent by an HttpClient: an absolute http or https URL
 * and the request header fields. Immutable.
 *
 * Header names are kept in lower case, the form HTTP/2 requires and HTTP/1.1
 * accepts. Names must be HTTP tokens and values must not contain CR, LF or
 * NUL, so that no value can inject a header field or a second request; the
 * URL must not contain spaces or control characters.
 */
final class HttpRequest
{
    public const METHOD_GET = 'GET';

    public const METHOD_HEAD = 'HEAD';

    /**
     * The characters a URL must not contain, which could end or alter the
     * request line: spaces and the ASCII control characters (finding 6 of
     * the PRX-4 review).
     *
     * @internal for RedirectTarget
     */
    public const URL_FORBIDDEN = '/[\x00-\x20\x7F]/';

    /**
     * @var string
     */
    private $method;

    /**
     * @var non-empty-string
     */
    private $url;

    /**
     * @var array<string, string>
     */
    private $headers = [];

    /**
     * @param string                $url     absolute http or https URL
     * @param array<string, string> $headers header values keyed by header name, in any case
     * @param string                $method  METHOD_GET or METHOD_HEAD
     *
     * @throws InvalidArgumentException when the URL, the method or a header is invalid
     */
    public function __construct(string $url, array $headers = [], string $method = self::METHOD_GET)
    {
        if ($method !== self::METHOD_GET && $method !== self::METHOD_HEAD) {
            throw new InvalidArgumentException('The method must be GET or HEAD, got ' . $method);
        }

        $this->method = $method;
        $this->url = self::checkUrl($url);
        foreach ($headers as $name => $value) {
            $this->headers[self::checkName((string) $name)] = self::checkValue($value);
        }
    }

    /**
     * The URL.
     *
     * @return non-empty-string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * A copy for another URL, with the same method and header fields.
     *
     * @throws InvalidArgumentException when the URL is invalid
     */
    public function withUrl(string $url): self
    {
        $copy = clone $this;
        $copy->url = self::checkUrl($url);

        return $copy;
    }

    /**
     * METHOD_GET or METHOD_HEAD.
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * The header fields, keyed by lower-case name.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * The value of a header field, or null when it is not set.
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * A copy with the header field $name set to $value, replacing any value
     * it had.
     *
     * @throws InvalidArgumentException when the name or the value is invalid
     */
    public function withHeader(string $name, string $value): self
    {
        $copy = clone $this;
        $copy->headers[self::checkName($name)] = self::checkValue($value);

        return $copy;
    }

    /**
     * A copy without the header field $name.
     */
    public function withoutHeader(string $name): self
    {
        $copy = clone $this;
        unset($copy->headers[strtolower($name)]);

        return $copy;
    }

    /**
     * A conditional copy: If-None-Match carries the entity tag and
     * If-Modified-Since the HTTP date of a stored copy, so that the origin
     * answers 304 Not Modified when the copy is still current. A null value
     * removes the field.
     *
     * @param string|null $etag         entity tag as received in ETag, quotes and W/ prefix included
     * @param string|null $lastModified HTTP date as received in Last-Modified
     *
     * @throws InvalidArgumentException when a value contains CR, LF or NUL
     */
    public function withConditions(?string $etag, ?string $lastModified): self
    {
        $copy = $etag === null ? $this->withoutHeader('if-none-match') : $this->withHeader('if-none-match', $etag);

        return $lastModified === null ? $copy->withoutHeader('if-modified-since') : $copy->withHeader('if-modified-since', $lastModified);
    }

    /**
     * @return non-empty-string
     *
     * @throws InvalidArgumentException
     */
    private static function checkUrl(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if ($url === '' || !is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true) || !is_string($host) || $host === '') {
            throw new InvalidArgumentException('The URL must be an absolute http or https URL');
        }
        if (preg_match(self::URL_FORBIDDEN, $url) === 1) {
            throw new InvalidArgumentException('The URL must not contain spaces or control characters');
        }

        return $url;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function checkName(string $name): string
    {
        if (preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid header name: "' . addcslashes($name, "\0..\37\177..\377") . '"');
        }

        return strtolower($name);
    }

    /**
     * @param mixed $value
     *
     * @throws InvalidArgumentException
     */
    private static function checkValue($value): string
    {
        if (!is_string($value) || strpbrk($value, "\r\n\0") !== false) {
            throw new InvalidArgumentException('A header value must be a string without CR, LF or NUL');
        }

        return trim($value, " \t");
    }
}
