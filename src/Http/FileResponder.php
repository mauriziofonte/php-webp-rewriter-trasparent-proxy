<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Cache\CacheLocation;
use Mfonte\ProxyCdn\Cache\CacheStorage;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Origin\ResponseBody;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a cache file from PHP, with the headers the web server sends for
 * the same file on a hit (opportunity O-F, finding P-17):
 *
 * - SENDFILE "none": PHP streams the file in chunks, never reading it into
 *   memory, and answers a single byte range ("Range: bytes=...", with
 *   If-Range) with 206 or 416, and If-None-Match or If-Modified-Since
 *   with 304;
 * - "x-sendfile": an empty response with "X-Sendfile: <absolute path>",
 *   which Apache mod_xsendfile replaces with the file;
 * - "x-accel-redirect": an empty response with "X-Accel-Redirect:
 *   /_cache/<path>", the internal location of the nginx configuration.
 *
 * The two last modes fail safe: PHP hands a file over only when the web
 * server says it takes it, with the server variable PROXYCDN_SENDFILE set
 * to the mode (config/apache/proxy-cdn.conf sets it next to "XSendFile On",
 * inside <IfModule mod_xsendfile.c>; config/nginx/proxy-cdn-fastcgi.conf
 * passes it). Without it, an empty response would reach the client, so
 * PHP streams the file instead. A client cannot set it: request headers
 * become HTTP_* variables. The web server answers ranges and conditional
 * requests itself when it takes the file. The entity tag is the one nginx
 * computes for a static file, "<mtime>-<size>" in hexadecimal, and
 * Last-Modified the time of the file.
 * When the client accepts it, the Brotli or gzip sibling of the file is
 * sent instead, with Content-Encoding.
 */
final class FileResponder
{
    /**
     * The Content-Encoding of each encoding sibling.
     */
    private const CONTENT_ENCODINGS = [
        CacheLocation::ENCODING_BROTLI => 'br',
        CacheLocation::ENCODING_GZIP => 'gzip',
    ];

    /**
     * @var CacheStorage
     */
    private $storage;

    /**
     * @var ResponseFactory
     */
    private $responses;

    /**
     * @var string
     */
    private $mode;

    /**
     * @var string
     */
    private $cacheDirectory;

    /**
     * @param string $mode           one of the Config::SENDFILE_* constants
     * @param string $cacheDirectory absolute path of the cache root, which X-Accel-Redirect maps to "/_cache/"
     */
    public function __construct(CacheStorage $storage, ResponseFactory $responses, string $mode = Config::SENDFILE_NONE, string $cacheDirectory = '')
    {
        $this->storage = $storage;
        $this->responses = $responses;
        $this->mode = $mode;
        $this->cacheDirectory = rtrim($cacheDirectory, '/');
    }

    /**
     * @param string                $file        absolute path of the cache file
     * @param string                $extension   extension that types the body (e.g. "webp" for a variant)
     * @param string                $cacheStatus the X-Cache-Status value (ResponseFactory::CACHE_*)
     * @param array<string, string> $encoded     encoding siblings of the file, keyed by CacheLocation::ENCODING_*
     * @param list<string>          $vary        request headers that select the file, for Vary
     * @param int|null              $maxAge      the max-age of an entry that PHP revalidates (null for CACHE_EXPIRY);
     *                                           such an entry is always sent by PHP, as the web server
     *                                           configurations send the Cache-Control of CACHE_EXPIRY
     */
    public function respond(Request $request, string $file, string $extension, string $cacheStatus, array $encoded = [], array $vary = [], ?int $maxAge = null): Response
    {
        $acceptEncoding = (string) $request->headers->get('Accept-Encoding', '');
        $contentEncoding = null;
        foreach ($encoded as $encoding => $encodedFile) {
            if (isset(self::CONTENT_ENCODINGS[$encoding]) && AcceptHeader::accepts($acceptEncoding, self::CONTENT_ENCODINGS[$encoding]) && $this->storage->isFile($encodedFile)) {
                $file = $encodedFile;
                $contentEncoding = self::CONTENT_ENCODINGS[$encoding];
                break;
            }
        }

        $size = $this->storage->size($file);
        $modified = $this->storage->modifiedAt($file);
        if ($size === null || $modified === null) {
            return $this->responses->internalError('Cannot read cached file from disk');
        }

        $headers = $this->responses->assetHeaders($extension, $cacheStatus, $maxAge);
        if ($encoded !== []) {
            $vary[] = 'Accept-Encoding';
        }
        if ($vary !== []) {
            $headers['Vary'] = implode(', ', array_values(array_unique($vary)));
        }
        if ($contentEncoding !== null) {
            $headers['Content-Encoding'] = $contentEncoding;
        }

        if ($maxAge !== null) {
            return $this->stream($request, $file, $size, $modified, $headers);
        }
        $mode = $this->serverSendfile($request);
        if ($mode === Config::SENDFILE_X_SENDFILE) {
            $headers['X-Sendfile'] = $file;

            return new Response('', 200, $headers);
        }
        if ($mode === Config::SENDFILE_X_ACCEL_REDIRECT && $this->cacheDirectory !== '' && strpos($file, $this->cacheDirectory . '/') === 0) {
            $relative = (string) substr($file, strlen($this->cacheDirectory) + 1);
            $headers['X-Accel-Redirect'] = '/' . CacheKeyResolver::CACHE_DIRECTORY . '/' . implode('/', array_map('rawurlencode', explode('/', $relative)));

            return new Response('', 200, $headers);
        }

        return $this->stream($request, $file, $size, $modified, $headers);
    }

    /**
     * The SENDFILE mode, when the web server of $request declares that it
     * takes the files that way (PROXYCDN_SENDFILE, or REDIRECT_
     * PROXYCDN_SENDFILE after an Apache internal redirect); else
     * SENDFILE_NONE.
     */
    private function serverSendfile(Request $request): string
    {
        if ($this->mode === Config::SENDFILE_NONE) {
            return $this->mode;
        }
        foreach (['PROXYCDN_SENDFILE', 'REDIRECT_PROXYCDN_SENDFILE'] as $variable) {
            if ($request->server->get($variable) === $this->mode) {
                return $this->mode;
            }
        }

        return Config::SENDFILE_NONE;
    }

    /**
     * A response the origin forbade storing (no-store, private), streamed
     * from the temporary file of the fetch, which is deleted once sent.
     * Never cached by a shared cache; HEAD gets the headers only.
     *
     * @param string $extension extension that types the body
     * @param bool   $noStore   whether the origin said "no-store" (else "private")
     */
    public function respondUncached(Request $request, ResponseBody $body, string $extension, bool $noStore): Response
    {
        $headers = $this->responses->assetHeaders($extension, ResponseFactory::CACHE_BYPASS);
        $headers['Cache-Control'] = $noStore ? 'no-store' : 'private, no-cache';
        $headers['Content-Length'] = (string) $body->getSize();
        if ($request->getRealMethod() === 'HEAD' || $body->getSize() === 0) {
            $body->delete();

            return new Response('', 200, $headers);
        }

        return new StreamedResponse(static function () use ($body): void {
            $input = @fopen($body->getPath(), 'rb');
            $output = @fopen('php://output', 'wb');
            if ($input !== false && $output !== false) {
                stream_copy_to_stream($input, $output);
            }
            if ($input !== false) {
                fclose($input);
            }
            if ($output !== false) {
                fclose($output);
            }
            $body->delete();
        }, 200, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    private function stream(Request $request, string $file, int $size, int $modified, array $headers): Response
    {
        $etag = sprintf('"%x-%x"', $modified, $size);
        $lastModified = gmdate('D, d M Y H:i:s', $modified) . ' GMT';
        $headers['ETag'] = $etag;
        $headers['Last-Modified'] = $lastModified;
        $headers['Accept-Ranges'] = 'bytes';

        if (self::isNotModified($request, $etag, $modified)) {
            unset($headers['Content-Type'], $headers['Content-Encoding']);

            return new Response('', 304, $headers);
        }

        $start = 0;
        $end = $size - 1;
        $status = 200;
        $range = self::rangeOf($request, $etag, $lastModified);
        if ($range !== null) {
            $bounds = self::boundsOf($range, $size);
            if ($bounds === null) {
                $headers['Content-Range'] = 'bytes */' . $size;
                unset($headers['Content-Encoding']);

                return new Response('', 416, $headers);
            }
            list($start, $end) = $bounds;
            $status = 206;
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
        }
        $length = $size === 0 ? 0 : $end - $start + 1;
        $headers['Content-Length'] = (string) $length;

        if ($request->getRealMethod() === 'HEAD' || $length === 0) {
            return new Response('', $status, $headers);
        }

        return new StreamedResponse(static function () use ($file, $start, $length): void {
            $input = @fopen($file, 'rb');
            $output = @fopen('php://output', 'wb');
            if ($input !== false && $output !== false) {
                // Copied in chunks by the stream layer: the file is never held in memory.
                stream_copy_to_stream($input, $output, $length, $start);
            }
            if ($input !== false) {
                fclose($input);
            }
            if ($output !== false) {
                fclose($output);
            }
        }, $status, $headers);
    }

    /**
     * Whether the client's copy is current: If-None-Match lists the entity
     * tag (weak comparison, or "*"), or, without If-None-Match,
     * If-Modified-Since is not earlier than the file.
     */
    private static function isNotModified(Request $request, string $etag, int $modified): bool
    {
        if (!in_array($request->getRealMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }
        $ifNoneMatch = $request->headers->get('If-None-Match');
        if ($ifNoneMatch !== null) {
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '*' || preg_replace('#\AW/#', '', $candidate) === $etag) {
                    return true;
                }
            }

            return false;
        }
        $ifModifiedSince = $request->headers->get('If-Modified-Since');
        if ($ifModifiedSince === null) {
            return false;
        }
        $since = strtotime($ifModifiedSince);

        return $since !== false && $since >= $modified;
    }

    /**
     * The "bytes=" range of the request, or null when the whole file must
     * be sent: no Range, or an If-Range that names another version.
     */
    private static function rangeOf(Request $request, string $etag, string $lastModified): ?string
    {
        if ($request->getRealMethod() !== 'GET') {
            return null;
        }
        $range = $request->headers->get('Range');
        if ($range === null) {
            return null;
        }
        $ifRange = $request->headers->get('If-Range');
        if ($ifRange !== null && trim($ifRange) !== $etag && trim($ifRange) !== $lastModified) {
            return null;
        }
        // A syntactically invalid or multiple range is answered with the whole file, as RFC 9110 allows.
        $range = trim($range);
        if (preg_match('/\Abytes=(\d*)-(\d*)\z/i', $range, $match) !== 1 || ($match[1] === '' && $match[2] === '')) {
            return null;
        }

        return $range;
    }

    /**
     * The first and last byte of a single "bytes=first-last" range, as
     * rangeOf() returns it; null when it is not satisfiable.
     *
     * @return array{int, int}|null
     */
    private static function boundsOf(string $range, int $size): ?array
    {
        if (preg_match('/\Abytes=(\d*)-(\d*)\z/i', $range, $match) !== 1) {
            return null;
        }
        if ($match[1] === '') {
            $suffix = (int) $match[2];
            if ($suffix === 0 || $size === 0) {
                return null;
            }

            return [max(0, $size - $suffix), $size - 1];
        }
        $start = (int) $match[1];
        $end = $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1);
        if ($start >= $size || $start > $end) {
            return null;
        }

        return [$start, $end];
    }
}
