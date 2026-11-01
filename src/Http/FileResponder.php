<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Cache\CacheStorage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a cache file from PHP, as 2.x did for the hits the web server
 * cannot serve itself: the body is read into memory and the headers are
 * rebuilt from the extension (MediaTypes), never from the origin.
 *
 * PRX-6 adds X-Sendfile, X-Accel-Redirect and streaming.
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

        return $this->responses->hit($body, MediaTypes::of($extension), $size);
    }
}
