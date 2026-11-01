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
use Mfonte\ProxyCdn\Compression\Precompressor;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Image\WebpConverter;
use Mfonte\ProxyCdn\Origin\CircuitBreaker;
use Mfonte\ProxyCdn\Origin\OriginFetcher;
use Mfonte\ProxyCdn\Origin\OriginRequest;
use Mfonte\ProxyCdn\Origin\OriginResponse;
use Mfonte\ProxyCdn\Origin\RedirectTarget;
use Mfonte\ProxyCdn\Security\HostAllowlist;
use Mfonte\ProxyCdn\Security\PathSanitizer;
use Mfonte\ProxyCdn\Security\PurgeAuthenticator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns a request for an asset into a response: from the cache, or from the
 * origin, storing what it fetches.
 *
 * 1. The Host header must name a host of PROXY_HOSTS (HostAllowlist, risk
 *    L-13), which selects the origin and the cache directory; OPTIONS gets
 *    the CORS preflight answer, methods other than GET and HEAD get 405;
 *    the path is decoded by PathSanitizer, and a refused path or a path
 *    whose extension is not cacheable gets an error; HEAD gets the headers
 *    of the GET;
 * 2. PURGE removes the entry and its siblings when the request proves a
 *    purge key of the origin (PurgeAuthenticator); the anonymous
 *    "?purge=1" and "?purge_all=1" of 2.x get a 401 that explains the
 *    change (risk L-7);
 * 3. a request for "X.webp" that the cache does not hold is answered from
 *    a cached raster sibling "X.jpg", "X.png"... (risk L-4: 2.x redirected
 *    raster URLs to their WEBP copy with a permanent 301);
 * 4. a fresh 404 or 410 of the negative cache answers without asking the
 *    origin;
 * 5. the query string is reduced to the parameters of QueryStringPolicy,
 *    which key the entry and reach the origin as the client wrote them; an
 *    entry on disk is served from disk, as its AVIF or WEBP variant to the
 *    clients that accept it, with "Vary: Accept" (finding P-1: 2.x
 *    redirected every client to the WEBP copy), or as its Brotli or gzip
 *    sibling;
 * 6. otherwise, unless the circuit breaker of the origin is open, the
 *    asset is fetched from the origin (a failure is answered by its kind,
 *    see failure(), finding P-13), stored as the origin sent it with its
 *    compressed siblings, a raster is converted to WEBP when a converter is
 *    configured, and the asset is served from the cache.
 *
 * The web server serves the cache files itself, without PHP, whenever it
 * can compute their location (see CacheKeyResolver); the kernel answers
 * everything else, with the same headers. It only builds the response: it
 * never sends it, never exits and reads nothing but the Request, so that a
 * host framework can mount it.
 */
final class ProxyKernel
{
    /**
     * The raster formats: their entries may have AVIF and WEBP variants.
     */
    public const RASTER_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'bmp'];

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
     * @var HostAllowlist
     */
    private $hosts;

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
     * @var Precompressor
     */
    private $precompressor;

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
        Precompressor $precompressor,
        ResponseFactory $responses,
        FileResponder $files,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->hosts = $config->getHostAllowlist();
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
        $this->precompressor = $precompressor;
        $this->responses = $responses;
        $this->files = $files;
        $this->logger = $logger;
    }

    public function handle(Request $request): Response
    {
        // The method on the wire: X-HTTP-Method-Override must not turn a POST into a GET or a PURGE.
        $method = $request->getRealMethod();
        // Symfony throws on a Host header that is not a host name, which
        // would reach the front controller as a 500 and an error in the log:
        // it is the client's mistake.
        if (!self::hasValidHost($request)) {
            return $this->responses->error(null, ResponseFactory::ERROR_BAD_HOST);
        }
        $host = $this->hosts->match($request->getHost());
        if ($host === null) {
            return $this->responses->error(null, ResponseFactory::ERROR_UNKNOWN_HOST);
        }
        if ($method === 'OPTIONS') {
            return $this->responses->preflight((string) $request->headers->get('Access-Control-Request-Headers', ''));
        }
        if ($method === 'PURGE') {
            return $this->handlePurge($request, $host);
        }
        // Finding P-19: 2.x served every method like GET.
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $this->responses->error($request->getPathInfo(), ResponseFactory::ERROR_METHOD_NOT_ALLOWED);
        }

        $response = $this->handleAsset($request, $host);
        if ($method === 'HEAD' && !$response instanceof StreamedResponse) {
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
     * @param string $host a host of the allowlist
     *
     * @return Response|array{string, string, QueryString, CacheLocation}
     */
    private function locate(Request $request, string $host)
    {
        $requestPath = $request->getPathInfo();
        $path = $this->pathSanitizer->sanitize($requestPath);
        if ($path === null) {
            return $this->responses->error($requestPath, ResponseFactory::ERROR_REFUSED);
        }

        $extension = self::extensionOf($path);
        if (!$this->config->isCacheableExtension($extension)) {
            return $this->responses->error($path, ResponseFactory::ERROR_NOT_CACHEABLE);
        }

        $query = $this->queryPolicy->apply(self::rawQuery($request));
        if ($query === null) {
            return $this->responses->error($path, ResponseFactory::ERROR_QUERY_NOT_ALLOWED);
        }

        return [$path, $extension, $query, $this->keyResolver->resolve($host, $path, $query->getCanonical())];
    }

    /**
     * The answer to PURGE: the entry, its variants and its encodings are
     * removed when the request proves a purge key whose scope covers the
     * origin of the host.
     */
    private function handlePurge(Request $request, string $host): Response
    {
        $located = $this->locate($request, $host);
        if ($located instanceof Response) {
            return $located;
        }
        list($path, , $query, $location) = $located;
        $client = $this->trustedProxies->getClientAddress($request);

        $originHost = strtolower((string) parse_url((string) $this->hosts->originOf($host), PHP_URL_HOST));
        $scheme = $this->purgeAuthenticator->authenticate($request, $originHost, time());
        if ($scheme === null) {
            $this->logger->warning('purge refused', ['host' => $host, 'path' => $path, 'client' => $client]);

            return $this->responses->error($path, ResponseFactory::ERROR_PURGE_UNAUTHORIZED);
        }

        $removed = $this->purger->purge($location);
        $this->logger->notice('purge', [
            'host' => $host,
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
    private function handleAsset(Request $request, string $host): Response
    {
        $located = $this->locate($request, $host);
        if ($located instanceof Response) {
            return $located;
        }
        list($path, $extension, $query, $location) = $located;
        $file = $location->getFile();
        $origin = (string) $this->hosts->originOf($host);

        // Risk L-7: 2.x purged for anyone; the request now explains how to purge.
        $rawQuery = self::rawQuery($request);
        if (self::hasFlag($rawQuery, 'purge') || self::hasFlag($rawQuery, 'purge_all')) {
            return $this->responses->error($path, ResponseFactory::ERROR_PURGE_UNAUTHORIZED);
        }

        $legacy = $this->isLegacyWebpRequest($extension, $location) ? $this->legacySiblings($host, $path, $query) : [];
        foreach ($legacy as $sibling) {
            if ($this->storage->isFile($sibling[1]->getFile())) {
                return $this->serveLegacy($request, $sibling[1], $sibling[0], ResponseFactory::CACHE_HIT);
            }
        }

        $remembered = $this->negativeCache->lookup($file);
        if ($remembered !== null && $legacy === []) {
            return $this->responses->error($path, $remembered === 410 ? ResponseFactory::ERROR_ORIGIN_GONE : ResponseFactory::ERROR_ORIGIN_NOT_FOUND);
        }

        // The web server serves the entries it can locate itself, and
        // routes the others here: an entry whose query string it cannot
        // map, or one the policy ignored (finding P-5).
        $stored = $this->serve($request, $location, $extension, ResponseFactory::CACHE_HIT);
        if ($stored !== null) {
            return $stored;
        }

        // Finding P-14: one breaker per origin, checked only before a
        // fetch, so that the entries on disk are served while it is open.
        if ($this->circuitBreaker->isOpen(RedirectTarget::originOf($origin))) {
            return $this->responses->error($path, ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE);
        }

        if ($remembered === null) {
            $fetched = $this->fetch($request, $origin, $path, $query);
            if ($fetched->isSuccessful()) {
                return $this->storeAndServe($request, $origin, $location, $extension, $fetched);
            }
            if ($legacy === [] || !$fetched->isNotFound()) {
                return $this->failure($origin, $path, $file, $fetched);
            }
            $this->negativeCache->remember($file, 404);
        }

        return $this->fetchLegacy($request, $origin, $path, $query, $legacy);
    }

    /**
     * Fetches the asset of $path from $origin, with the query string the
     * policy kept.
     */
    private function fetch(Request $request, string $origin, string $path, QueryString $query): OriginResponse
    {
        // The scheme the client used, as a trusted proxy reports it (TRUSTED_PROXIES).
        $server = ($this->trustedProxies->isSecure($request) ? 'https' : 'http') . '://' . $request->getHttpHost();
        $forwardedQuery = $query->getForwarded();
        $originUrl = $origin . PathSanitizer::encode($path) . ($forwardedQuery !== '' ? '?' . $forwardedQuery : '');

        return $this->origin->fetch(new OriginRequest($originUrl, ['Referer' => $server]));
    }

    /**
     * Stores a fetched asset with its siblings and serves it.
     */
    private function storeAndServe(Request $request, string $origin, CacheLocation $location, string $extension, OriginResponse $fetched): Response
    {
        $path = '/' . $location->getRelativePath();
        $error = $this->store($location, $extension, $fetched->getBody());
        if ($error !== null) {
            return $this->responses->error($path, $error);
        }

        if ($this->isRaster($extension) && $this->converter !== null) {
            $conversion = $this->converter->convert($location->getFile(), $location->getVariantFile(CacheLocation::FORMAT_WEBP));
            if (!$conversion->isConverted()) {
                return $this->responses->internalError('Image conversion failed: ' . (string) $conversion->getError());
            }
        }

        // The asset is back: forget any stale failure.
        $this->negativeCache->forget($location->getFile());
        $this->circuitBreaker->reset(RedirectTarget::originOf($origin));

        return $this->serve($request, $location, $extension, ResponseFactory::CACHE_MISS)
            ?? $this->responses->error($path, ResponseFactory::ERROR_CACHE_FILE);
    }

    /**
     * Writes $body as the entry of $location, replacing the entry and the
     * siblings of an older version, and writes its compressed siblings.
     *
     * @return int|null the ResponseFactory::ERROR_* constant of a failure, null on success
     */
    private function store(CacheLocation $location, string $extension, string $body): ?int
    {
        if (!$this->storage->ensureDirectory($location->getDirectory())) {
            return ResponseFactory::ERROR_CACHE_DIRECTORY;
        }
        foreach ($location->getFiles() as $file) {
            if ($file !== $location->getFile()) {
                $this->storage->delete($file);
            }
        }
        if (!$this->storage->write($location->getFile(), $body)) {
            return ResponseFactory::ERROR_CACHE_FILE;
        }
        $this->precompressor->compress($location, $extension);

        return null;
    }

    /**
     * The response of the entry of $location when it is on disk: a raster
     * as its AVIF or WEBP variant when the client accepts it (converted
     * first when a converter is configured and the WEBP variant is
     * missing), any other entry as its Brotli or gzip sibling when the
     * client accepts it; null when the entry is not on disk.
     *
     * @param string $cacheStatus one of the ResponseFactory::CACHE_* constants
     */
    private function serve(Request $request, CacheLocation $location, string $extension, string $cacheStatus): ?Response
    {
        $file = $location->getFile();
        if (!$this->storage->isFile($file)) {
            return null;
        }
        if (!$this->isRaster($extension)) {
            $encoded = [];
            if (Precompressor::isCompressible($extension)) {
                foreach (CacheLocation::ENCODINGS as $encoding) {
                    $encoded[$encoding] = $location->getEncodedFile($encoding);
                }
            }

            return $this->files->respond($request, $file, $extension, $cacheStatus, $encoded);
        }

        $webp = $location->getVariantFile(CacheLocation::FORMAT_WEBP);
        if ($this->converter !== null && !$this->storage->isFile($webp)) {
            $this->converter->convert($file, $webp);
        }
        $accept = (string) $request->headers->get('Accept', '');
        foreach (CacheLocation::FORMATS as $format) {
            $variant = $location->getVariantFile($format);
            if (AcceptHeader::accepts($accept, MediaTypes::of($format)) && $this->storage->isFile($variant)) {
                return $this->files->respond($request, $variant, $format, $cacheStatus, [], ['Accept']);
            }
        }

        return $this->files->respond($request, $file, $extension, $cacheStatus, [], ['Accept']);
    }

    /**
     * Whether a request for $location is for "X.webp" that the cache does
     * not hold, to be resolved from a raster sibling (LEGACY_WEBP_URLS).
     */
    private function isLegacyWebpRequest(string $extension, CacheLocation $location): bool
    {
        return $extension === CacheLocation::FORMAT_WEBP
            && $this->config->shouldResolveLegacyWebpUrls()
            && !$this->storage->isFile($location->getFile());
    }

    /**
     * The raster siblings of "X.webp": "X.jpg", "X.jpeg", "X.png", "X.gif"
     * and "X.bmp", with their cache locations, the cacheable ones only.
     *
     * @return list<array{string, CacheLocation}>
     */
    private function legacySiblings(string $host, string $path, QueryString $query): array
    {
        $base = (string) substr($path, 0, -strlen('.' . CacheLocation::FORMAT_WEBP));
        $siblings = [];
        foreach (self::RASTER_EXTENSIONS as $extension) {
            if ($this->config->isCacheableExtension($extension)) {
                $siblings[] = [$extension, $this->keyResolver->resolve($host, $base . '.' . $extension, $query->getCanonical())];
            }
        }

        return $siblings;
    }

    /**
     * The answer to "X.webp" from the raster entry of $location: its WEBP
     * variant, converted first when a converter is configured, or else the
     * raster itself, typed as what it is (risk L-4: the browsers that
     * cached a 301 of 2.x to "X.webp" keep asking for it, and the origin
     * has no such file).
     */
    private function serveLegacy(Request $request, CacheLocation $location, string $extension, string $cacheStatus): Response
    {
        $webp = $location->getVariantFile(CacheLocation::FORMAT_WEBP);
        if ($this->converter !== null && !$this->storage->isFile($webp)) {
            $this->converter->convert($location->getFile(), $webp);
        }
        if ($this->storage->isFile($webp)) {
            return $this->files->respond($request, $webp, CacheLocation::FORMAT_WEBP, $cacheStatus);
        }

        return $this->files->respond($request, $location->getFile(), $extension, $cacheStatus);
    }

    /**
     * Fetches the raster siblings of "X.webp" from the origin, in order,
     * until one exists, then stores and answers it; the 404 of "X.webp"
     * when none does.
     *
     * @param list<array{string, CacheLocation}> $siblings
     */
    private function fetchLegacy(Request $request, string $origin, string $path, QueryString $query, array $siblings): Response
    {
        foreach ($siblings as $sibling) {
            list($extension, $location) = $sibling;
            $siblingPath = '/' . $location->getRelativePath();
            if ($this->negativeCache->lookup($location->getFile()) !== null) {
                continue;
            }
            $fetched = $this->fetch($request, $origin, $siblingPath, $query);
            if ($fetched->isNotFound()) {
                $this->negativeCache->remember($location->getFile(), 404);
                continue;
            }
            if (!$fetched->isSuccessful()) {
                return $this->failure($origin, $path, $location->getFile(), $fetched);
            }
            $error = $this->store($location, $extension, $fetched->getBody());
            if ($error !== null) {
                return $this->responses->error($path, $error);
            }
            $this->negativeCache->forget($location->getFile());
            $this->circuitBreaker->reset(RedirectTarget::originOf($origin));
            $this->logger->info('legacy webp url', ['path' => $path, 'sibling' => $siblingPath]);

            return $this->serveLegacy($request, $location, $extension, ResponseFactory::CACHE_MISS);
        }

        return $this->responses->error($path, ResponseFactory::ERROR_ORIGIN_NOT_FOUND);
    }

    private function isRaster(string $extension): bool
    {
        return in_array($extension, self::RASTER_EXTENSIONS, true);
    }

    /**
     * The lower-case extension of a decoded path.
     */
    private static function extensionOf(string $path): string
    {
        return trim(strtolower(pathinfo($path, PATHINFO_EXTENSION)));
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
     *   network failures) opens the breaker and answers 503; an HTTP
     *   status the origin sent means it is up and closes the breaker, so
     *   that a half-open probe answered 404, 403, 410 or 3xx does not
     *   leave it open; a transport failure that is none of those (a
     *   malformed reply, an HTTP/2 stream error, a local write error) is
     *   neutral: it neither counts nor closes.
     */
    private function failure(string $origin, string $path, string $file, OriginResponse $fetched): Response
    {
        $reason = $fetched->getFailureReason();
        $breakerKey = RedirectTarget::originOf($origin);
        if (in_array($reason, self::UNAVAILABLE, true) || $fetched->getStatus() === 429) {
            $this->circuitBreaker->recordFailure($breakerKey);

            return $this->responses->error($path, ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE);
        }
        if ($fetched->getStatus() > 0 && $fetched->getTransportErrorCode() === 0) {
            $this->circuitBreaker->reset($breakerKey);
        }

        if ($reason === OriginResponse::FAILURE_NOT_FOUND || $reason === OriginResponse::FAILURE_GONE) {
            $gone = $reason === OriginResponse::FAILURE_GONE;
            $this->negativeCache->remember($file, $gone ? 410 : 404);

            return $this->responses->error($path, $gone ? ResponseFactory::ERROR_ORIGIN_GONE : ResponseFactory::ERROR_ORIGIN_NOT_FOUND);
        }
        if ($reason === OriginResponse::FAILURE_FORBIDDEN) {
            return $this->responses->error($path, ResponseFactory::ERROR_ORIGIN_FORBIDDEN);
        }
        if ($reason === OriginResponse::FAILURE_REDIRECT) {
            $location = $this->proxyLocation($origin, $fetched->getLocation());

            return $location === null
                ? $this->responses->error($path, ResponseFactory::ERROR_BAD_GATEWAY)
                : $this->responses->redirect($fetched->getStatus(), $location);
        }

        return $this->responses->error($path, ResponseFactory::ERROR_BAD_GATEWAY);
    }

    /**
     * The path and query string on the proxy of an origin URL below
     * the origin of the host; null for any other URL, which the client must not be
     * sent to (it may be a private host: SSRF through the client).
     *
     * The URL is refused when a browser could read the path as another
     * host (an open redirect): a backslash ("/\evil.example" is
     * "//evil.example" to browsers), a space or control character (browsers
     * strip tabs and line breaks), a path that starts with "//"
     * ("/.//evil.example/x" resolves to "//evil.example/x"), and any "." or
     * ".." segment left after the origin URL was resolved.
     */
    private function proxyLocation(string $origin, ?string $target): ?string
    {
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
     * Whether the Host header, if any, is a valid host name.
     */
    private static function hasValidHost(Request $request): bool
    {
        try {
            $request->getHost();
        } catch (SuspiciousOperationException $exception) {
            return false;
        }

        return true;
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
     * Whether the raw query string sets $name to "1" or "true", in any case.
     */
    private static function hasFlag(string $rawQuery, string $name): bool
    {
        return preg_match('/(?:^|&)' . $name . '=(?:1|true)(?:&|$)/i', $rawQuery) === 1;
    }
}
