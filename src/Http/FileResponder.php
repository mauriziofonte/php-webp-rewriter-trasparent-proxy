<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Cache\CacheStorage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a cache file from PHP, with the headers the web server sends for
 * the same file on a hit (finding P-17): PHP streams the file in chunks,
 * never reading it into memory. The entity tag is the one nginx computes
 * for a static file, "<mtime>-<size>" in hexadecimal, and Last-Modified
 * the time of the file.
 */
final class FileResponder
{
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
     * @param string       $file        absolute path of the cache file
     * @param string       $extension   extension that types the body (e.g. "webp" for a variant)
     * @param string       $cacheStatus the X-Cache-Status value (ResponseFactory::CACHE_*)
     * @param list<string> $vary        request headers that select the file, for Vary
     */
    public function respond(Request $request, string $file, string $extension, string $cacheStatus, array $vary = []): Response
    {
        $size = $this->storage->size($file);
        $modified = $this->storage->modifiedAt($file);
        if ($size === null || $modified === null) {
            return $this->responses->internalError('Cannot read cached file from disk');
        }

        $headers = $this->responses->assetHeaders($extension, $cacheStatus);
        if ($vary !== []) {
            $headers['Vary'] = implode(', ', array_values(array_unique($vary)));
        }

        return $this->stream($request, $file, $size, $modified, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    private function stream(Request $request, string $file, int $size, int $modified, array $headers): Response
    {
        $headers['ETag'] = sprintf('"%x-%x"', $modified, $size);
        $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $modified) . ' GMT';
        $headers['Content-Length'] = (string) $size;

        if ($request->getRealMethod() === 'HEAD' || $size === 0) {
            return new Response('', 200, $headers);
        }

        return new StreamedResponse(static function () use ($file, $size): void {
            $input = @fopen($file, 'rb');
            $output = @fopen('php://output', 'wb');
            if ($input !== false && $output !== false) {
                // Copied in chunks by the stream layer: the file is never held in memory.
                stream_copy_to_stream($input, $output, $size);
            }
            if ($input !== false) {
                fclose($input);
            }
            if ($output !== false) {
                fclose($output);
            }
        }, 200, $headers);
    }
}
