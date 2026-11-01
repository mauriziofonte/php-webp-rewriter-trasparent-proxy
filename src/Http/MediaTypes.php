<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

/**
 * The one table of media types of the proxy (finding P-23): the
 * Content-Type of every file PHP serves from the cache, and the AddType
 * lines of public/.htaccess, which a test keeps identical to it.
 *
 * The types are the registered ones: text/javascript (RFC 9239), the
 * font/* types of RFC 8081, image/vnd.microsoft.icon. 2.x used
 * application/javascript, application/x-font-ttf, application/font-woff
 * and image/x-icon, and disagreed with the types origins send.
 */
final class MediaTypes
{
    /**
     * The type of a file whose extension is not in the table.
     */
    public const DEFAULT_TYPE = 'application/octet-stream';

    /**
     * Media type of each lower-case extension.
     */
    public const TYPES = [
        'css' => 'text/css',
        'js' => 'text/javascript',
        'mjs' => 'text/javascript',
        'json' => 'application/json',
        'map' => 'application/json',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/vnd.microsoft.icon',
        'bmp' => 'image/bmp',
        'gif' => 'image/gif',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jpe' => 'image/jpeg',
        'png' => 'image/png',
        'apng' => 'image/apng',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'ttf' => 'font/ttf',
        'ttc' => 'font/collection',
        'otf' => 'font/otf',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'eot' => 'application/vnd.ms-fontobject',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'xml' => 'application/xml',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
    ];

    private function __construct()
    {
    }

    /**
     * The media type of $extension, in any letter case; DEFAULT_TYPE when
     * it is not in the table.
     *
     * @param string $extension without the dot
     */
    public static function of(string $extension): string
    {
        return self::TYPES[strtolower($extension)] ?? self::DEFAULT_TYPE;
    }
}
