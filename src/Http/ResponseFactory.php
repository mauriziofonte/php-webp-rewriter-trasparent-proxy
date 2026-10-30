<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Config\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the responses of the proxy that do not come from a cache file, with
 * the status codes, headers and bodies of 2.x.
 *
 * The responses are returned, never sent: the caller (the front controller
 * or a host framework) sends them.
 */
final class ResponseFactory
{
    /** The request path is refused (it contains "/.."). */
    public const ERROR_REFUSED = -1;

    /** The cache directory of the entry cannot be created. */
    public const ERROR_CACHE_DIRECTORY = 1;

    /** The cache file of the entry cannot be written. */
    public const ERROR_CACHE_FILE = 2;

    /** The origin answered 404, now or less than NEGATIVE_CACHE_TTL ago. */
    public const ERROR_ORIGIN_NOT_FOUND = 3;

    /** The extension of the path is not cacheable. */
    public const ERROR_NOT_CACHEABLE = 4;

    /** The origin failed, now or less than NETWORK_ERROR_TTL ago. */
    public const ERROR_UPSTREAM_UNAVAILABLE = 5;

    /** The configuration cannot be loaded. */
    public const ERROR_CONFIGURATION = -4;

    /**
     * The "Error code" text of each error, as in 2.x.
     */
    private const MESSAGES = [
        self::ERROR_CONFIGURATION => 'ENV_FILE_LOAD_ERR',
        self::ERROR_REFUSED => 'Cannot serve this request',
        self::ERROR_CACHE_DIRECTORY => 'Cannot create cache directory',
        self::ERROR_CACHE_FILE => 'Cannot create cache file',
        self::ERROR_ORIGIN_NOT_FOUND => 'Cannot read remote origin file',
        self::ERROR_NOT_CACHEABLE => 'Request cannot be processed',
        self::ERROR_UPSTREAM_UNAVAILABLE => 'Upstream temporarily unavailable',
    ];

    /**
     * Origin response headers forwarded to the client; every other header,
     * Set-Cookie and Cache-Control included, is dropped.
     */
    private const FORWARDED_HEADERS = [
        'content-type',
        'content-length',
        'etag',
        'last-modified',
        'vary',
        'accept-ranges',
        'content-disposition',
    ];

    /**
     * @var int
     */
    private $cacheExpiry;

    /**
     * @var int
     */
    private $networkErrorTtl;

    /**
     * @param int $cacheExpiry     max-age, in seconds, of the served assets (CACHE_EXPIRY)
     * @param int $networkErrorTtl seconds a client is told to wait after an origin failure (NETWORK_ERROR_TTL)
     */
    public function __construct(int $cacheExpiry, int $networkErrorTtl)
    {
        $this->cacheExpiry = $cacheExpiry;
        $this->networkErrorTtl = $networkErrorTtl;
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->getCacheExpiry(), $config->getNetworkErrorTtl());
    }

    /**
     * The answer to every OPTIONS request: 204 with the 2.x CORS headers.
     */
    public function preflight(): Response
    {
        return new Response('', 204, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'DNT, X-User-Token, Keep-Alive, User-Agent, X-Requested-With, If-Modified-Since, Cache-Control, Content-Type',
            'Access-Control-Max-Age' => (string) $this->cacheExpiry,
            'Content-Type' => 'text/plain charset=UTF-8',
            'Content-Length' => '0',
        ]);
    }

    /**
     * A plain-text error: 503 with Retry-After for ERROR_UPSTREAM_UNAVAILABLE,
     * 404 for every other error (finding P-21).
     *
     * @param string|null $path  the request path, hashed into the Ray ID; null for a random Ray ID
     * @param int         $error one of the ERROR_* constants
     */
    public function error(?string $path, int $error): Response
    {
        $headers = ['Content-Type' => 'text/plain; charset=UTF-8'];
        $status = 404;
        if ($error === self::ERROR_UPSTREAM_UNAVAILABLE) {
            $status = 503;
            $headers['Retry-After'] = (string) $this->networkErrorTtl;
        }

        return new Response(self::errorBody($path, $error), $status, $headers);
    }

    /**
     * The answer to every request while the configuration cannot be loaded:
     * a 404 with the ENV_FILE_LOAD_ERR code and a random Ray ID, as in 2.x
     * (finding P-21). It needs no configuration.
     */
    public static function configurationError(): Response
    {
        return new Response(self::errorBody(null, self::ERROR_CONFIGURATION), 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * A plain-text 500.
     *
     * @param string $reason shown to the client, as in 2.x
     */
    public function internalError(string $reason): Response
    {
        return new Response(
            "The request could not be processed.\nInternal server error: " . $reason . '.',
            500,
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }

    /**
     * The origin headers that are forwarded to the client: the allowed
     * names only, without empty values (and, as in 2.x, without "0").
     *
     * @param array<string, string> $originHeaders keyed by lower-case name
     *
     * @return array<string, string>
     */
    public function forwardedHeaders(array $originHeaders): array
    {
        $forwarded = [];
        foreach ($originHeaders as $name => $value) {
            if (in_array($name, self::FORWARDED_HEADERS, true) && $value !== '' && $value !== '0') {
                $forwarded[$name] = $value;
            }
        }

        return $forwarded;
    }

    /**
     * The response of a cache miss: the asset just fetched from the origin.
     *
     * @param string                $body    the (possibly rewritten or converted) asset
     * @param array<string, string> $headers forwarded headers, see forwardedHeaders()
     * @param bool                  $webp    whether $body is the WEBP conversion, which adds "Vary: Accept"
     */
    public function miss(string $body, array $headers, bool $webp): Response
    {
        $response = new Response();
        $response->setStatusCode(200);
        $response->headers->add($headers);
        $response->setPublic();
        $response->setMaxAge($this->cacheExpiry);
        $response->headers->addCacheControlDirective('must-revalidate', true);
        $response->headers->set('x-cache-status', 'miss');
        if ($webp) {
            $response->headers->set('vary', 'Accept');
        }
        $response->setContent($body);

        return $response;
    }

    /**
     * The response of a cache hit served by PHP.
     *
     * @param string   $body     the cached asset
     * @param string   $mimeType its media type
     * @param int|null $size     its size in bytes, for Content-Length; null to omit the header
     */
    public function hit(string $body, string $mimeType, ?int $size): Response
    {
        $response = new Response();
        $response->setStatusCode(200);
        $response->headers->set('content-type', $mimeType);
        if ($size !== null) {
            $response->headers->set('content-length', (string) $size);
        }
        $response->setPublic();
        $response->setMaxAge($this->cacheExpiry);
        $response->headers->addCacheControlDirective('must-revalidate', true);
        $response->headers->set('x-cache-status', 'hit');
        $response->headers->set('vary', 'Accept');
        $response->setContent($body);

        return $response;
    }

    private static function errorBody(?string $path, int $error): string
    {
        $message = self::MESSAGES[$error] ?? 'Unknown error';
        $rayId = md5($path ?? (string) microtime(true));

        return "The request could not be processed.\nError code: " . $message . ".\nRay ID: " . $rayId;
    }
}
