<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Cache\CacheStorage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a cache file from PHP, as 2.x did for the hits the web server
 * cannot serve itself: the body is read into memory and the headers are
 * rebuilt from the extension, never from the origin.
 *
 * PRX-6 adds X-Sendfile, X-Accel-Redirect and streaming.
 */
final class FileResponder
{
    /**
     * Media type of each extension, as in 2.x; PRX-5 replaces it with a
     * single table (finding P-23).
     */
    private const MIME_TYPES = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'bmp' => 'image/bmp',
        'gif' => 'image/gif',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'ttf' => 'application/x-font-ttf',
        'ttc' => 'application/x-font-ttf',
        'otf' => 'application/x-font-otf',
        'eot' => 'application/vnd.ms-fontobject',
        'woff' => 'application/font-woff',
        'woff2' => 'application/font-woff2',
        'pdf' => 'application/pdf',
    ];

    /**
     * @var CacheStorage
     */
    private $storage;

    /**
     * @var ResponseFactory
     */
    private $responses;

    public function __construct(CacheStorage $storage, ResponseFactory $responses)
    {
        $this->storage = $storage;
        $this->responses = $responses;
    }

    /**
     * @param string $file      absolute path of the cache file
     * @param string $extension extension that describes the body (e.g. "webp" for a converted sibling)
     */
    public function respond(string $file, string $extension): Response
    {
        $size = $this->storage->size($file);
        $body = $this->storage->read($file);
        if ($body === null) {
            return $this->responses->internalError('Cannot read cached file from disk');
        }

        return $this->responses->hit($body, self::mimeTypeOf($extension), $size);
    }

    /**
     * The media type of $extension (any case); application/octet-stream when unknown.
     */
    public static function mimeTypeOf(string $extension): string
    {
        return self::MIME_TYPES[strtolower($extension)] ?? 'application/octet-stream';
    }
}
