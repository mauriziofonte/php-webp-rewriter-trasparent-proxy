<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Config\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds every response of the proxy: the misses, the hits served by PHP,
 * the preflights, redirects and purges, and the errors, whose bodies keep
 * the format of 2.x while their status codes follow finding P-21.
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

    /** A purge without a valid purge key: PURGE, or the "?purge=1" of 2.x (risk L-7). */
    public const ERROR_PURGE_UNAUTHORIZED = 8;

    /** The origin answered 403 (finding P-13: 2.x answered 503 and opened the breaker). */
    public const ERROR_ORIGIN_FORBIDDEN = 9;

    /** The origin answered 410, now or less than NEGATIVE_CACHE_TTL ago. */
    public const ERROR_ORIGIN_GONE = 10;

    /**
     * The origin answered what the proxy cannot serve: a redirect to
     * another origin, another 4xx, or a body over UPSTREAM_MAX_SIZE.
     */
    public const ERROR_BAD_GATEWAY = 11;

    /** The Host header is not a valid host name ("Host: bad host"). */
    public const ERROR_BAD_HOST = 12;

    /** The host is not in PROXY_HOSTS (risk L-13). */
    public const ERROR_UNKNOWN_HOST = 13;

    /**
     * A resize URL that is not signed, or whose width is not one of
     * RESIZE_WIDTHS (opportunity O-I, risk L-14).
     */
    public const ERROR_RESIZE_REFUSED = 14;

    /** X-Cache-Status of a response served from the cache. */
    public const CACHE_HIT = 'HIT';

    /** X-Cache-Status of a response fetched from the origin. */
    public const CACHE_MISS = 'MISS';

    /** X-Cache-Status of an expired entry the origin confirmed with a 304. */
    public const CACHE_REVALIDATED = 'REVALIDATED';

    /**
     * X-Cache-Status of an expired entry served while it is revalidated in
     * the background, or because the origin failed to revalidate it.
     */
    public const CACHE_STALE = 'STALE';

    /** X-Cache-Status of a response the origin forbade storing (no-store, private). */
    public const CACHE_BYPASS = 'BYPASS';

    /**
     * The realm of the WWW-Authenticate challenge of a refused purge.
     */
    public const PURGE_REALM = 'proxy-cdn purge';

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
        self::ERROR_BAD_HOST => 400,
        self::ERROR_UNKNOWN_HOST => 421,
        self::ERROR_PURGE_UNAUTHORIZED => 401,
        self::ERROR_NOT_CACHEABLE => 404,
        self::ERROR_ORIGIN_NOT_FOUND => 404,
        self::ERROR_ORIGIN_FORBIDDEN => 403,
        self::ERROR_ORIGIN_GONE => 410,
        self::ERROR_BAD_GATEWAY => 502,
        self::ERROR_METHOD_NOT_ALLOWED => 405,
        self::ERROR_CACHE_DIRECTORY => 500,
        self::ERROR_CACHE_FILE => 500,
        self::ERROR_UPSTREAM_UNAVAILABLE => 503,
        self::ERROR_CONFIGURATION => 503,
        self::ERROR_RESIZE_REFUSED => 403,
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
        self::ERROR_PURGE_UNAUTHORIZED => 'Purge requires a purge key',
        self::ERROR_ORIGIN_FORBIDDEN => 'Remote origin refused the file',
        self::ERROR_ORIGIN_GONE => 'Remote origin file is gone',
        self::ERROR_BAD_GATEWAY => 'Bad response from the remote origin',
        self::ERROR_BAD_HOST => 'Invalid Host header',
        self::ERROR_UNKNOWN_HOST => 'Unknown host',
        self::ERROR_RESIZE_REFUSED => 'Resize URL refused',
    ];

    /**
     * A line of guidance after the Ray ID of some errors.
     */
    private const GUIDANCE = [
        self::ERROR_PURGE_UNAUTHORIZED => 'The anonymous ?purge=1 and ?purge_all=1 of 2.x were removed in 3.0. '
            . 'Send "PURGE <url>" with "Authorization: Bearer <key>", or with the X-Purge-Timestamp and '
            . 'X-Purge-Signature headers, where the key is one of PURGE_KEYS for this origin (see the Purge section of the README).',
        self::ERROR_RESIZE_REFUSED => 'A resize URL must be signed with RESIZE_KEY and ask for one of RESIZE_WIDTHS '
            . '(see the Image conversion section of the README).',
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
        } elseif ($error === self::ERROR_PURGE_UNAUTHORIZED) {
            $headers['WWW-Authenticate'] = 'Bearer realm="' . self::PURGE_REALM . '"';
        }

        return new Response(self::errorBody($path, $error), self::statusOf($error), $headers);
    }

    /**
     * A redirect of the origin passed on to the client, with the origin's
     * status and the Location rewritten to the path on the proxy (finding
     * P-13: 2.x answered 503 and opened the breaker). It is not cached.
     *
     * @param int    $status   the 3xx status of the origin
     * @param string $location the path and query string on the proxy, starting with "/"
     */
    public function redirect(int $status, string $location): Response
    {
        $response = new Response('', $status, ['Location' => $location, 'Cache-Control' => 'no-store', 'Content-Length' => '0']);
        $this->protect($response, false);

        return $response;
    }

    /**
     * The answer to a purge that was carried out: a JSON document with the
     * decoded path, the canonical query string that keys the entry, and how
     * many files were removed (0 when nothing was cached).
     */
    public function purged(string $path, string $canonicalQuery, int $removed): Response
    {
        $body = json_encode(['purged' => true, 'path' => $path, 'query' => $canonicalQuery, 'removed' => $removed], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new Response(
            ($body === false ? '{"purged":true}' : $body) . "\n",
            200,
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']
        );
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
     * The headers of an asset served from the cache or just fetched, the
     * same the web server configurations send on a hit: the type of the
     * extension (finding P-23: one table of media types, whatever the
     * origin claims), the Cache-Control of CACHE_EXPIRY, X-Cache-Status
     * (finding P-18: only on assets, with what really happened), CORS,
     * Cross-Origin-Resource-Policy and Timing-Allow-Origin (opportunity
     * O-G), nosniff, and the SVG policy for an SVG.
     *
     * @param string   $extension   lower-case extension that types the body
     * @param string   $cacheStatus one of the CACHE_* constants
     * @param int|null $maxAge      the max-age of an entry that expires sooner than CACHE_EXPIRY; null for CACHE_EXPIRY
     *
     * @return array<string, string>
     */
    public function assetHeaders(string $extension, string $cacheStatus, ?int $maxAge = null): array
    {
        $headers = [
            'Content-Type' => MediaTypes::of($extension),
            'Cache-Control' => 'public, max-age=' . ($maxAge === null ? $this->cacheExpiry : min($maxAge, $this->cacheExpiry)) . ', must-revalidate',
            'X-Cache-Status' => $cacheStatus,
        ] + self::securityHeaders();
        if (MediaTypes::of($extension) === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = self::SVG_CONTENT_SECURITY_POLICY;
        }

        return $headers;
    }

    /**
     * The headers every asset carries, whoever serves it: the Apache and
     * nginx configurations send the same ones on a hit.
     *
     * @return array<string, string>
     */
    public static function securityHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Timing-Allow-Origin' => '*',
            'X-Content-Type-Options' => 'nosniff',
        ];
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

        $body = "The request could not be processed.\nError code: " . $message . ".\nRay ID: " . $rayId;

        return isset(self::GUIDANCE[$error]) ? $body . "\n" . self::GUIDANCE[$error] : $body;
    }
}
