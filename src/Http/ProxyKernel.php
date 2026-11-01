<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Cache\CacheLocation;
use Mfonte\ProxyCdn\Cache\CacheStorage;
use Mfonte\ProxyCdn\Cache\NegativeCache;
use Mfonte\ProxyCdn\Cache\Purger;
use Mfonte\ProxyCdn\Cache\QueryString;
use Mfonte\ProxyCdn\Cache\QueryStringPolicy;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Image\WebpConverter;
use Mfonte\ProxyCdn\Origin\CircuitBreaker;
use Mfonte\ProxyCdn\Origin\OriginFetcher;
use Mfonte\ProxyCdn\Origin\OriginRequest;
use Mfonte\ProxyCdn\Origin\OriginResponse;
use Mfonte\ProxyCdn\Origin\RedirectTarget;
use Mfonte\ProxyCdn\Security\PathSanitizer;
use Mfonte\ProxyCdn\Security\PurgeAuthenticator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a request for an asset into a response: from the cache, or from the
 * origin, storing what it fetches. The flow is the one of 2.x:
 *
 * 1. OPTIONS gets the CORS preflight answer, methods other than GET and
 *    HEAD get 405; the path is decoded by PathSanitizer, and a refused
 *    path or a path whose extension is not cacheable gets an error; HEAD
 *    gets the headers of the GET;
 * 2. PURGE removes the entry when the request proves a purge key of the
 *    origin (PurgeAuthenticator); the anonymous "?purge=1" and
 *    "?purge_all=1" of 2.x get a 401 that explains the change (risk L-7);
 * 3. a fresh 404 or 410 of the negative cache answers without asking the
 *    origin;
 * 4. the query string is reduced to the parameters of QueryStringPolicy,
 *    which key the entry and reach the origin as the client wrote them;
 *    a raster whose WEBP sibling exists is served as WEBP to clients that
 *    accept it; an entry on disk is served from disk;
 * 5. otherwise, unless the circuit breaker of the origin is open, the
 *    asset is fetched from the origin (a failure is answered
 *    by its kind, see failure(), finding P-13) and stored as the
 *    origin sent it (2.x rewrote stylesheets, findings P-2, P-3, P-4), a
 *    raster is converted to WEBP when a converter is configured, and the
 *    asset is answered.
 *
 * The kernel only builds the response: it never sends it, never exits and
 * reads nothing but the Request, so that a host framework can mount it.
 */
final class ProxyKernel
{
    private const RASTER_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'bmp'];

    /**
     * The failures that mean the origin is unavailable, which open the
     * circuit breaker.
     */
    private const UNAVAILABLE = [
        OriginResponse::FAILURE_SERVER_ERROR,
        OriginResponse::FAILURE_TIMEOUT,
        OriginResponse::FAILURE_DNS,
        OriginResponse::FAILURE_CONNECT,
        OriginResponse::FAILURE_TLS,
        OriginResponse::FAILURE_NETWORK,
    ];

    /**
     * @var Config
     */
    private $config;

    /**
     * @var PathSanitizer
     */
    private $pathSanitizer;

    /**
     * @var CacheKeyResolver
     */
    private $keyResolver;

    /**
     * @var QueryStringPolicy
     */
    private $queryPolicy;

    /**
     * @var TrustedProxies
     */
    private $trustedProxies;

    /**
     * @var PurgeAuthenticator
     */
    private $purgeAuthenticator;

    /**
     * @var CacheStorage
     */
    private $storage;

    /**
     * @var NegativeCache
     */
    private $negativeCache;

    /**
     * @var CircuitBreaker
     */
    private $circuitBreaker;

    /**
     * @var Purger
     */
    private $purger;

    /**
     * @var OriginFetcher
     */
    private $origin;

    /**
     * @var WebpConverter|null
     */
    private $converter;

    /**
     * @var ResponseFactory
     */
    private $responses;

    /**
     * @var FileResponder
     */
    private $files;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param WebpConverter|null $converter null when IMAGE_CONVERSION_MODE is "none"
     */
    public function __construct(
        Config $config,
        PathSanitizer $pathSanitizer,
        CacheKeyResolver $keyResolver,
        QueryStringPolicy $queryPolicy,
        TrustedProxies $trustedProxies,
        PurgeAuthenticator $purgeAuthenticator,
        CacheStorage $storage,
        NegativeCache $negativeCache,
        CircuitBreaker $circuitBreaker,
        Purger $purger,
        OriginFetcher $origin,
        ?WebpConverter $converter,
        ResponseFactory $responses,
        FileResponder $files,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->pathSanitizer = $pathSanitizer;
        $this->keyResolver = $keyResolver;
        $this->queryPolicy = $queryPolicy;
        $this->trustedProxies = $trustedProxies;
        $this->purgeAuthenticator = $purgeAuthenticator;
        $this->storage = $storage;
        $this->negativeCache = $negativeCache;
        $this->circuitBreaker = $circuitBreaker;
        $this->purger = $purger;
        $this->origin = $origin;
        $this->converter = $converter;
        $this->responses = $responses;
        $this->files = $files;
        $this->logger = $logger;
    }

    public function handle(Request $request): Response
    {
        // The method on the wire: X-HTTP-Method-Override must not turn a POST into a GET or a PURGE.
        $method = $request->getRealMethod();
        if ($method === 'OPTIONS') {
            return $this->responses->preflight((string) $request->headers->get('Access-Control-Request-Headers', ''));
        }
        if ($method === 'PURGE') {
            return $this->handlePurge($request);
        }
        // Finding P-19: 2.x served every method like GET.
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $this->responses->error($request->getPathInfo(), ResponseFactory::ERROR_METHOD_NOT_ALLOWED);
        }

        $response = $this->handleAsset($request);
        if ($method === 'HEAD') {
            // The headers of the GET, Content-Length included, without its body.
            $response->setContent('');
        }

        return $response;
    }

    /**
     * The decoded path, its extension, the query string kept by the policy
     * and the cache location of a request; or the error response when the
     * path or the query string is refused.
     *
     * @return Response|array{string, string, QueryString, CacheLocation}
     */
    private function locate(Request $request)
    {
        $requestPath = $request->getPathInfo();
        $path = $this->pathSanitizer->sanitize($requestPath);
        if ($path === null) {
            return $this->responses->error($requestPath, ResponseFactory::ERROR_REFUSED);
        }

        $extension = trim(strtolower(pathinfo($path, PATHINFO_EXTENSION)));
        if (!$this->config->isCacheableExtension($extension)) {
            return $this->responses->error($path, ResponseFactory::ERROR_NOT_CACHEABLE);
        }

        $query = $this->queryPolicy->apply(self::rawQuery($request));
        if ($query === null) {
            return $this->responses->error($path, ResponseFactory::ERROR_QUERY_NOT_ALLOWED);
        }

        return [$path, $extension, $query, $this->keyResolver->resolve($path, $query->getCanonical())];
    }

    /**
     * The answer to PURGE: the entry is removed when the request proves a
     * purge key whose scope covers the origin.
     */
    private function handlePurge(Request $request): Response
    {
        $located = $this->locate($request);
        if ($located instanceof Response) {
            return $located;
        }
        list($path, $extension, $query, $location) = $located;
        $client = $this->trustedProxies->getClientAddress($request);

        $scheme = $this->purgeAuthenticator->authenticate($request, $this->originHost(), time());
        if ($scheme === null) {
            $this->logger->warning('purge refused', ['path' => $path, 'client' => $client]);

            return $this->responses->error($path, ResponseFactory::ERROR_PURGE_UNAUTHORIZED);
        }

        $isRaster = in_array($extension, self::RASTER_EXTENSIONS, true);
        $removed = $this->purger->purge($location, $isRaster);
        $this->logger->notice('purge', [
            'path' => $path,
            'query' => $query->getCanonical(),
            'removed' => count($removed),
            'auth' => $scheme,
            'client' => $client,
        ]);

        return $this->responses->purged($path, $query->getCanonical(), count($removed));
    }

    /**
     * The answer to a GET of an asset.
     */
    private function handleAsset(Request $request): Response
    {
        $located = $this->locate($request);
        if ($located instanceof Response) {
            return $located;
        }
        list($path, $extension, $query, $location) = $located;
        $file = $location->getFile();

        // Risk L-7: 2.x purged for anyone; the request now explains how to purge.
        $rawQuery = self::rawQuery($request);
        if (self::hasFlag($rawQuery, 'purge') || self::hasFlag($rawQuery, 'purge_all')) {
            return $this->responses->error($path, ResponseFactory::ERROR_PURGE_UNAUTHORIZED);
        }

        $remembered = $this->negativeCache->lookup($file);
        if ($remembered !== null) {
            return $this->responses->error($path, $remembered === 410 ? ResponseFactory::ERROR_ORIGIN_GONE : ResponseFactory::ERROR_ORIGIN_NOT_FOUND);
        }

        $acceptsWebp = stripos((string) $request->headers->get('Accept', ''), 'image/webp') !== false;
        $isRaster = in_array($extension, self::RASTER_EXTENSIONS, true);
        $webpFile = $location->getWebpFile();

        if ($isRaster && $acceptsWebp && $this->storage->isFile($webpFile)) {
            return $this->files->respond($webpFile, 'webp');
        }

        // The web server serves the entries of the path itself, but routes
        // every request with a query string here: an entry on disk is
        // served, whether it is a bucket's or the path's one (a query
        // string the policy ignored, finding P-5).
        if ($this->storage->isFile($file)) {
            if ($isRaster && $this->converter !== null && !$this->storage->isFile($webpFile)) {
                $this->converter->convert($file);
                if ($acceptsWebp && $this->storage->isFile($webpFile)) {
                    return $this->files->respond($webpFile, 'webp');
                }
            }

            return $this->files->respond($file, $extension);
        }

        // Finding P-14: one breaker for the origin, checked only before a
        // fetch, so that the entries on disk are served while it is open.
        $origin = RedirectTarget::originOf($this->config->getSourceOrigin());
        if ($this->circuitBreaker->isOpen($origin)) {
            return $this->responses->error($path, ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE);
        }

        // The scheme the client used, as a trusted proxy reports it (TRUSTED_PROXIES).
        $server = ($this->trustedProxies->isSecure($request) ? 'https' : 'http') . '://' . $request->getHttpHost();
        $forwardedQuery = $query->getForwarded();
        $originUrl = $this->config->getSourceOrigin() . PathSanitizer::encode($path) . ($forwardedQuery !== '' ? '?' . $forwardedQuery : '');
        $fetched = $this->origin->fetch(new OriginRequest($originUrl, ['Referer' => $server]));

        if (!$fetched->isSuccessful()) {
            return $this->failure($path, $file, $fetched);
        }

        $body = $fetched->getBody();
        $headers = $this->responses->forwardedHeaders($fetched->getHeaders());

        if (!$this->storage->ensureDirectory($location->getDirectory())) {
            return $this->responses->error($path, ResponseFactory::ERROR_CACHE_DIRECTORY);
        }
        if (!$this->storage->write($file, $body)) {
            return $this->responses->error($path, ResponseFactory::ERROR_CACHE_FILE);
        }

        $servedWebp = false;
        if ($isRaster && $this->converter !== null) {
            $conversion = $this->converter->convert($file);
            $convertedFile = $conversion->getPath();
            if ($convertedFile === null) {
                return $this->responses->internalError('Image conversion failed: ' . (string) $conversion->getError());
            }
            $webp = $acceptsWebp ? $this->storage->read($convertedFile) : null;
            if ($webp !== null) {
                $body = $webp;
                $headers['content-type'] = MediaTypes::of('webp');
                $headers['content-length'] = (string) strlen($webp);
                $servedWebp = true;
            }
        }

        // The asset is back: forget any stale failure.
        $this->negativeCache->forget($file);
        $this->circuitBreaker->reset($origin);

        return $this->responses->miss($body, $headers, $servedWebp, $extension);
    }

    /**
     * The answer to a failed origin fetch (finding P-13: 2.x answered 503
     * and opened the breaker for every failure but 404):
     *
     * - 404 and 410 are answered as such and remembered by the negative
     *   cache; 403 is answered as such;
     * - a redirect within SOURCE_ORIGIN is passed on with its Location
     *   rewritten to the proxy, so that the client never leaves it; a
     *   redirect anywhere else, another 4xx or a body too large is a 502;
     * - only unavailability (5xx, 429, timeout, DNS, connection, TLS and
     *   network failures) opens the breaker and answers 503; any other
     *   answer means the origin is up and closes it, so that a half-open
     *   probe answered 404, 403, 410 or 3xx does not leave it open.
     */
    private function failure(string $path, string $file, OriginResponse $fetched): Response
    {
        $reason = $fetched->getFailureReason();
        $origin = RedirectTarget::originOf($this->config->getSourceOrigin());
        if (in_array($reason, self::UNAVAILABLE, true) || $fetched->getStatus() === 429) {
            $this->circuitBreaker->recordFailure($origin);

            return $this->responses->error($path, ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE);
        }
        $this->circuitBreaker->reset($origin);

        if ($reason === OriginResponse::FAILURE_NOT_FOUND || $reason === OriginResponse::FAILURE_GONE) {
            $gone = $reason === OriginResponse::FAILURE_GONE;
            $this->negativeCache->remember($file, $gone ? 410 : 404);

            return $this->responses->error($path, $gone ? ResponseFactory::ERROR_ORIGIN_GONE : ResponseFactory::ERROR_ORIGIN_NOT_FOUND);
        }
        if ($reason === OriginResponse::FAILURE_FORBIDDEN) {
            return $this->responses->error($path, ResponseFactory::ERROR_ORIGIN_FORBIDDEN);
        }
        if ($reason === OriginResponse::FAILURE_REDIRECT) {
            $location = $this->proxyLocation($fetched->getLocation());

            return $location === null
                ? $this->responses->error($path, ResponseFactory::ERROR_BAD_GATEWAY)
                : $this->responses->redirect($fetched->getStatus(), $location);
        }

        return $this->responses->error($path, ResponseFactory::ERROR_BAD_GATEWAY);
    }

    /**
     * The path and query string on the proxy of an origin URL below
     * SOURCE_ORIGIN; null for any other URL, which the client must not be
     * sent to (it may be a private host: SSRF through the client).
     *
     * The URL is refused when a browser could read the path as another
     * host (an open redirect): a backslash ("/\evil.example" is
     * "//evil.example" to browsers), a space or control character (browsers
     * strip tabs and line breaks), a path that starts with "//"
     * ("/.//evil.example/x" resolves to "//evil.example/x"), and any "." or
     * ".." segment left after the origin URL was resolved.
     */
    private function proxyLocation(?string $target): ?string
    {
        $origin = $this->config->getSourceOrigin();
        // parse_url() turns control characters into "_": look at them first.
        if ($target === null || preg_match('#[\\\\\x00-\x20\x7f]#', $target) === 1 || !RedirectTarget::isSameOrigin($target, $origin)) {
            return null;
        }
        $basePath = rtrim((string) parse_url($origin, PHP_URL_PATH), '/');
        $path = (string) parse_url($target, PHP_URL_PATH);
        if ($basePath !== '' && strpos($path, $basePath . '/') !== 0) {
            return null;
        }
        $path = (string) substr($path, strlen($basePath));
        if (strncmp($path, '//', 2) === 0 || preg_match('#(?:\A|/)\.{1,2}(?:/|\z)#', $path) === 1) {
            return null;
        }
        $query = parse_url($target, PHP_URL_QUERY);

        return ($path === '' ? '/' : $path) . (is_string($query) && $query !== '' ? '?' . $query : '');
    }

    /**
     * The query string as the client sent it, without "?".
     */
    private static function rawQuery(Request $request): string
    {
        $rawQuery = $request->server->get('QUERY_STRING');

        return is_scalar($rawQuery) ? (string) $rawQuery : '';
    }

    /**
     * The host name of the origin, which scopes the purge keys.
     */
    private function originHost(): string
    {
        return strtolower((string) parse_url($this->config->getSourceOrigin(), PHP_URL_HOST));
    }

    /**
     * Whether the raw query string sets $name to "1" or "true", in any case.
     */
    private static function hasFlag(string $rawQuery, string $name): bool
    {
        return preg_match('/(?:^|&)' . $name . '=(?:1|true)(?:&|$)/i', $rawQuery) === 1;
    }
}
