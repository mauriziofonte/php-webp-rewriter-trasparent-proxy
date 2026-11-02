<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image;

use Mfonte\ProxyCdn\Config\Config;

/**
 * The signed resize URLs (opportunity O-I):
 *
 *     /_resize/<width>/<signature>/<path of the image>[?<query string>]
 *
 * ask for the raster image at <path> resized to <width> pixels wide (never
 * enlarged), in its own format, with its AVIF and WEBP variants like any
 * raster entry. <width> must be one of RESIZE_WIDTHS (risk L-14: the
 * variants of an image are bounded), and <signature> the first 32
 * hexadecimal digits of
 *
 *     HMAC-SHA256(RESIZE_KEY, "resize\n<host>\n<width>\n<path>\n<query>")
 *
 * where <host> is the host name of PROXY_HOSTS the URL is served under
 * (lower case, without port), <path> the decoded path of the image
 * ("/images/a b.jpg") and <query> its canonical query string (the
 * parameters QUERY_STRING_ALLOWLIST keeps, sorted; empty for none). The
 * signature is compared in constant time (hash_equals()). A URL that is
 * not signed, or whose width is not listed, is refused before anything is
 * fetched from the origin, so that no one but the holders of the key can
 * make the proxy resize, and store, images.
 *
 * The resized entry lives at the path of the resize URL, so the web server
 * serves its hits without PHP. A signature is not a secret once
 * published, but it is masked in the logs of the proxy (mask()).
 */
final class SignedUrlVerifier
{
    /**
     * The first path segment of the resize URLs.
     */
    public const PREFIX = '/_resize/';

    /**
     * Hexadecimal digits of a signature: 128 bits.
     */
    public const SIGNATURE_LENGTH = 32;

    /**
     * What verify() returns for a URL that is not a resize URL.
     */
    public const NOT_RESIZE = 'not_resize';

    /**
     * What verify() returns for a resize URL that is refused.
     */
    public const REFUSED = 'refused';

    private const PATTERN = '#\A/_resize/([1-9][0-9]{0,4})/([0-9a-f]{32})(/.+)\z#';

    /**
     * @var string
     */
    private $key;

    /**
     * @var list<int>
     */
    private $widths;

    /**
     * @param string    $key    RESIZE_KEY
     * @param list<int> $widths RESIZE_WIDTHS
     */
    public function __construct(string $key, array $widths)
    {
        $this->key = $key;
        $this->widths = $widths;
    }

    /**
     * The verifier of the configuration; null when resizing is off.
     */
    public static function fromConfig(Config $config): ?self
    {
        $key = $config->getResizeKey();
        if ($key === null || $config->getResizeWidths() === [] || $config->getImageConverter() === Config::CONVERTER_NONE) {
            return null;
        }

        return new self($key, $config->getResizeWidths());
    }

    /**
     * Whether $path, a decoded request path, starts like a resize URL.
     */
    public static function isResizePath(string $path): bool
    {
        return strncmp($path, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    /**
     * The width and the path of the image a resize URL asks for; REFUSED
     * when it is not signed, or its width is not listed; NOT_RESIZE when
     * $path is not a resize URL.
     *
     * @param string $host           the host name of the allowlist the request is for
     * @param string $path           the decoded request path
     * @param string $canonicalQuery the canonical query string of the request, empty for none
     *
     * @return array{int, string}|string
     */
    public function verify(string $host, string $path, string $canonicalQuery)
    {
        if (!self::isResizePath($path)) {
            return self::NOT_RESIZE;
        }
        if (preg_match(self::PATTERN, $path, $match) !== 1) {
            return self::REFUSED;
        }
        $width = (int) $match[1];
        $expected = $this->sign($host, $width, $match[3], $canonicalQuery);
        // Compared in constant time, and whatever the width, so that the
        // time taken tells nothing about the key.
        $signed = hash_equals($expected, $match[2]);
        if (!$signed || !in_array($width, $this->widths, true)) {
            return self::REFUSED;
        }

        return [$width, $match[3]];
    }

    /**
     * The signature of the resize URL of $path at $width.
     *
     * @param string $host           the host name of the allowlist, lower case, without port
     * @param string $path           the decoded path of the image, starting with "/"
     * @param string $canonicalQuery its canonical query string, empty for none
     */
    public function sign(string $host, int $width, string $path, string $canonicalQuery): string
    {
        $message = "resize\n" . strtolower($host) . "\n" . $width . "\n" . $path . "\n" . $canonicalQuery;

        return substr(hash_hmac('sha256', $message, $this->key), 0, self::SIGNATURE_LENGTH);
    }

    /**
     * The decoded path of the resize URL of $path at $width.
     */
    public function pathOf(string $host, int $width, string $path, string $canonicalQuery): string
    {
        return self::PREFIX . $width . '/' . $this->sign($host, $width, $path, $canonicalQuery) . $path;
    }

    /**
     * The widths of RESIZE_WIDTHS.
     *
     * @return list<int>
     */
    public function getWidths(): array
    {
        return $this->widths;
    }

    /**
     * $text with the signature of every resize URL in it replaced by
     * "<masked>": for log entries and messages.
     */
    public static function mask(string $text): string
    {
        return (string) preg_replace('#(/_resize/[0-9]{1,5}/)[0-9A-Fa-f]{1,64}#', '$1<masked>', $text);
    }
}
