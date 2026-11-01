<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Security;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;

/**
 * Turns the raw, percent-encoded path of a request into the decoded path
 * that names its cache file, or refuses it (findings P-8 and L-15).
 *
 * 2.x used the path as received: "/my%20file.png" was stored as a file
 * named "my%20file.png", which the web server, looking for "my file.png",
 * never found, so every request reached PHP and the origin again; and
 * "%2e%2e" passed its check for "/..". The path is now decoded exactly
 * once, and refused when the decoded path could name another file than
 * the one the client meant, or a file the proxy must not write:
 *
 * - malformed percent-encoding, and an encoded "/" or "\" ("%2F", "%5C"),
 *   which would change where the segments end;
 * - a decoded path that is not valid UTF-8, or that holds a control
 *   character (NUL, C0, DEL or C1) or a backslash;
 * - an empty segment ("//"), a segment "." or "..", and any segment that
 *   starts with "." (hidden files such as ".htaccess", and "..name");
 * - a segment longer than 255 bytes, which no common filesystem stores;
 * - a first segment that is reserved for the cache layout ("_cache", the
 *   cache root, and "_qs", the query-string buckets of 2.x): a client
 *   could otherwise read or write the entry of another host or URL.
 *
 * A trailing "/" is kept: the request then names no file, and the kernel
 * refuses it for its missing extension.
 */
final class PathSanitizer
{
    /**
     * First segments the cache layout reserves, compared without letter
     * case.
     */
    private const RESERVED_FIRST_SEGMENTS = [CacheKeyResolver::CACHE_DIRECTORY, CacheKeyResolver::LEGACY_BUCKET_DIRECTORY];

    /**
     * Bytes a segment may have: NAME_MAX of Linux, macOS and the BSDs.
     */
    private const MAX_SEGMENT_BYTES = 255;

    /**
     * Bytes the raw path may have.
     */
    private const MAX_PATH_BYTES = 4096;

    /**
     * The decoded path when it is allowed, null when it is refused.
     *
     * @param string $path the raw request path, still percent-encoded, starting with "/"
     *
     * @return string|null the decoded path, starting with "/"
     *
     * @psalm-taint-escape file
     */
    public function sanitize(string $path): ?string
    {
        if ($path === '' || $path[0] !== '/' || strlen($path) > self::MAX_PATH_BYTES) {
            return null;
        }
        // Every "%" starts a well-formed escape, and no escape stands for a separator.
        if (preg_match('/%(?![0-9A-Fa-f]{2})|%2[Ff]|%5[Cc]/', $path) === 1) {
            return null;
        }

        $decoded = rawurldecode($path);
        if (preg_match('//u', $decoded) !== 1 || preg_match('/[\x00-\x1F\x7F\\\\]|\xC2[\x80-\x9F]/', $decoded) === 1) {
            return null;
        }

        $segments = explode('/', substr($decoded, 1));
        $last = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                if ($index === $last) {
                    continue;
                }

                return null;
            }
            if ($segment[0] === '.' || strlen($segment) > self::MAX_SEGMENT_BYTES) {
                return null;
            }
        }
        if (in_array(strtolower($segments[0]), self::RESERVED_FIRST_SEGMENTS, true)) {
            return null;
        }

        return $decoded;
    }

    /**
     * The path encoded for a request URL: every byte but the unreserved
     * characters, the sub-delimiters, ":", "@" and "/" (RFC 3986, section
     * 3.3) is percent-encoded, so that the origin receives the path that
     * names the cache file, whatever escapes the client chose.
     *
     * @param string $path a path returned by sanitize()
     */
    public static function encode(string $path): string
    {
        return (string) preg_replace_callback(
            '#[^A-Za-z0-9\-._~!$&\'()*+,;=:@/]#',
            static function (array $match): string {
                return sprintf('%%%02X', ord($match[0]));
            },
            $path
        );
    }
}
