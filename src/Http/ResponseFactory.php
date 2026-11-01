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

    /** The method is neither GET, HEAD nor OPTIONS. */
    public const ERROR_METHOD_NOT_ALLOWED = 6;

    /** The query string holds a parameter outside QUERY_STRING_ALLOWLIST, in "reject" mode. */
    public const ERROR_QUERY_NOT_ALLOWED = 7;

    /**
     * The methods the proxy answers (finding P-19).
     */
    public const ALLOWED_METHODS = 'GET, HEAD, OPTIONS';

    /**
     * Seconds a browser may keep the answer to a preflight; browsers cap
     * it (Chromium at 2 hours, Firefox at 24).
     */
    public const PREFLIGHT_MAX_AGE = 86400;

    /**
     * The Content-Security-Policy of an SVG document (finding P-7): an SVG
     * opened from the proxy's own origin runs no script, loads nothing but
     * inline styles and data: images and fonts, and is sandboxed in a unique
     * origin, so that it cannot reach the cookies or storage of the domain.
     * The policy has no effect where the SVG is an <img> or a CSS image.
     */
    public const SVG_CONTENT_SECURITY_POLICY = "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; sandbox";

    /**
     * The status of each error: 5xx for the failures of the proxy or of the
     * origin, 4xx for what the client asked (finding P-21: 2.x answered 404
     * for its own failures). An error that is not listed is a 500.
     */
    private const STATUSES = [
        self::ERROR_REFUSED => 400,
        self::ERROR_QUERY_NOT_ALLOWED => 400,
        self::ERROR_NOT_CACHEABLE => 404,
        self::ERROR_ORIGIN_NOT_FOUND => 404,
        self::ERROR_METHOD_NOT_ALLOWED => 405,
        self::ERROR_CACHE_DIRECTORY => 500,
        self::ERROR_CACHE_FILE => 500,
        self::ERROR_UPSTREAM_UNAVAILABLE => 503,
        self::ERROR_CONFIGURATION => 503,
    ];

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
        self::ERROR_METHOD_NOT_ALLOWED => 'Method not allowed',
        self::ERROR_QUERY_NOT_ALLOWED => 'Query parameter not allowed',
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
     * The answer to every OPTIONS request: 204 with a valid CORS preflight
     * answer for the anonymous requests the assets get (finding P-19: 2.x
     * combined the "*" origin with credentials, which browsers refuse, and
     * allowed POST). The request headers a preflight asks for are allowed
     * when each is a valid field name: the assets are public, and without
     * credentials a header gives a page nothing it could not send itself.
     *
     * @param string $requestedHeaders the Access-Control-Request-Headers value, empty when absent
     */
    public function preflight(string $requestedHeaders): Response
    {
        $headers = [
            'Allow' => self::ALLOWED_METHODS,
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => self::ALLOWED_METHODS,
            'Access-Control-Max-Age' => (string) min($this->cacheExpiry, self::PREFLIGHT_MAX_AGE),
            'Content-Length' => '0',
            'Vary' => 'Access-Control-Request-Headers',
        ];
        $names = [];
        foreach (explode(',', $requestedHeaders) as $name) {
            $name = strtolower(trim($name));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        if ($names !== [] && preg_match('/\A[!#$%&\'*+.^_`|~0-9a-z-]+(?:,[!#$%&\'*+.^_`|~0-9a-z-]+)*\z/', implode(',', $names)) === 1) {
            $headers['Access-Control-Allow-Headers'] = implode(', ', $names);
        }

        return new Response('', 204, $headers);
    }

    /**
     * A plain-text error with the status of STATUSES: Retry-After comes with
     * ERROR_UPSTREAM_UNAVAILABLE, Allow with ERROR_METHOD_NOT_ALLOWED.
     *
     * @param string|null $path  the request path, hashed into the Ray ID; null for a random Ray ID
     * @param int         $error one of the ERROR_* constants
     */
    public function error(?string $path, int $error): Response
    {
        $headers = ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store'];
        if ($error === self::ERROR_UPSTREAM_UNAVAILABLE) {
            $headers['Retry-After'] = (string) $this->networkErrorTtl;
        } elseif ($error === self::ERROR_METHOD_NOT_ALLOWED) {
            $headers['Allow'] = self::ALLOWED_METHODS;
        }

        return new Response(self::errorBody($path, $error), self::statusOf($error), $headers);
    }

    /**
     * The status code of an ERROR_* constant.
     */
    public static function statusOf(int $error): int
    {
        return self::STATUSES[$error] ?? 500;
    }

    /**
     * The answer to every request while the configuration cannot be loaded:
     * a 503 with the ENV_FILE_LOAD_ERR code and a random Ray ID (2.x
     * answered 404, finding P-21). It needs no configuration.
     */
    public static function configurationError(): Response
    {
        return new Response(
            self::errorBody(null, self::ERROR_CONFIGURATION),
            self::statusOf(self::ERROR_CONFIGURATION),
            ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']
        );
    }

    /**
     * A plain-text 500.
     *
     * @param string $reason shown to the client, as in 2.x
     */
    public function internalError(string $reason): Response
    {
        return self::unexpectedError($reason);
    }

    /**
     * A plain-text 500 that needs no configuration: the answer of the front
     * controller when the kernel throws.
     *
     * @param string $reason shown to the client; never an exception message, which may hold paths
     */
    public static function unexpectedError(string $reason): Response
    {
        return new Response(
            "The request could not be processed.\nInternal server error: " . $reason . '.',
            500,
            ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']
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
     * @param string                $body      the asset, or its conversion
     * @param array<string, string> $headers   forwarded headers, see forwardedHeaders()
     * @param bool                  $webp      whether $body is the WEBP conversion, which adds "Vary: Accept"
     * @param string                $extension lower-case extension of the request path
     */
    public function miss(string $body, array $headers, bool $webp, string $extension): Response
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
        $this->protect($response, $extension === 'svg');
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
        $this->protect($response, false);
        $response->setContent($body);

        return $response;
    }

    /**
     * Adds "Access-Control-Allow-Origin: *", which the preflight answer
     * promises; "X-Content-Type-Options: nosniff", so that a browser never
     * takes an asset for another type; and the SVG policy to an SVG: one
     * whose request path ends in ".svg" ($svg) or whose Content-Type says
     * so, whatever the origin claims.
     */
    private function protect(Response $response, bool $svg): void
    {
        $response->headers->set('access-control-allow-origin', '*');
        $response->headers->set('x-content-type-options', 'nosniff');
        $type = strtolower(trim((string) $response->headers->get('content-type', '')));
        if ($svg || strpos($type, 'image/svg+xml') === 0) {
            $response->headers->set('content-security-policy', self::SVG_CONTENT_SECURITY_POLICY);
        }
    }

    private static function errorBody(?string $path, int $error): string
    {
        $message = self::MESSAGES[$error] ?? 'Unknown error';
        // 2.x hashed microtime(true), whose string form changes only every 100 microseconds: two errors close
        // together got the same "random" Ray ID. 16 random bytes keep the 32 hexadecimal digits of the format.
        $rayId = $path === null ? bin2hex(random_bytes(16)) : md5($path);

        return "The request could not be processed.\nError code: " . $message . ".\nRay ID: " . $rayId;
    }
}
