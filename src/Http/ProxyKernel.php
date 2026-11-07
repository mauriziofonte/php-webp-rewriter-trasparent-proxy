<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Cache\CacheLocation;
use Mfonte\ProxyCdn\Cache\CacheStorage;
use Mfonte\ProxyCdn\Cache\EntryMetadata;
use Mfonte\ProxyCdn\Cache\Freshness;
use Mfonte\ProxyCdn\Cache\NegativeCache;
use Mfonte\ProxyCdn\Cache\Purger;
use Mfonte\ProxyCdn\Cache\QueryString;
use Mfonte\ProxyCdn\Cache\QueryStringPolicy;
use Mfonte\ProxyCdn\Cache\RequestCoalescer;
use Mfonte\ProxyCdn\Compression\Precompressor;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Image\FormatNegotiator;
use Mfonte\ProxyCdn\Image\SignedUrlVerifier;
use Mfonte\ProxyCdn\Image\VariantConverter;
use Mfonte\ProxyCdn\Origin\CircuitBreaker;
use Mfonte\ProxyCdn\Origin\OriginFetcher;
use Mfonte\ProxyCdn\Origin\OriginRequest;
use Mfonte\ProxyCdn\Origin\OriginResponse;
use Mfonte\ProxyCdn\Origin\RedirectTarget;
use Mfonte\ProxyCdn\Origin\ResponseBody;
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
 * 2. PURGE removes the entry and its siblings, in both tiers, and without
 *    query string the entries of every query string of the path (Purger,
 *    finding P-16), when the request proves a purge key of the origin
 *    (PurgeAuthenticator); the anonymous
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
 *    sibling; an entry of the tier PHP revalidates is served while fresh,
 *    served stale and revalidated after the response within its
 *    stale-while-revalidate, and revalidated first beyond (serveManaged(),
 *    finding P-15, opportunity O-D);
 * 6. otherwise, unless the circuit breaker of the origin is open, one
 *    request of those that miss the entry together fetches it while the
 *    others wait for it (RequestCoalescer, finding P-24), and answer as
 *    it did when it stored nothing; a failure is answered by its kind
 *    (see failure(), finding P-13); a response the
 *    origin forbids storing (no-store, private) is streamed to the client
 *    only; any other is stored as the origin sent it, streamed from its
 *    temporary file (finding P-17), in the tier its lifetime calls for,
 *    with its compressed siblings, and the asset is served from the
 *    cache; the AVIF and WEBP variants of a raster are made after the
 *    response, within IMAGE_CONVERSION_TIMEOUT (VariantConverter: until
 *    one exists, or when a conversion fails, the original is served,
 *    finding P-10, with "max-age=0" while a variant is pending); in the
 *    tier the web server serves, the raster waits in its pending file,
 *    which PHP alone serves, until its variants are settled (publish());
 * 7. a signed resize URL (SignedUrlVerifier, opportunity O-I) is verified
 *    before anything else is done for it: one that is not signed, or
 *    whose width is not listed, gets a 403 without touching the origin;
 *    otherwise its entry is served like any other, and a miss fetches
 *    the image, resizes it and stores the result at the path of the
 *    resize URL, for the web server to serve.
 *
 * The web server serves the cache files itself, without PHP, whenever it
 * can compute their location (see CacheKeyResolver); the kernel answers
 * everything else, with the same headers. It only builds the response: it
 * never sends it, never exits and reads nothing but the Request, so that a
 * host framework can mount it; terminate() runs the background
 * revalidations and conversions once the response is sent.
 */
final class ProxyKernel
{
    /**
     * The raster formats: their entries may have AVIF and WEBP variants.
     */
    public const RASTER_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'bmp'];

    /**
     * The request attribute that holds the ID of the request (risk L-20),
     * when the front controller or a host application set one: it is sent
     * to the conversion API in X-Request-Id, also by the conversions made
     * after the response. It is the attribute of RequestId, which the
     * front controller and EdgeKernel fill (RequestId::of(): the valid ID
     * the client sent, else a generated one).
     */
    public const REQUEST_ID_ATTRIBUTE = RequestId::ATTRIBUTE;

    /**
     * Seconds a raster entry waits, pending, for its variants before it is
     * published without the ones still missing: the converter kept
     * failing transiently meanwhile (see publish()).
     */
    public const PENDING_HOLD_SECONDS = 3600;

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
     * How many times a request enters the coalescer of its entry
     * (RequestCoalescer::enter()) when what it was told to look for is
     * gone each time it looks; then it waits for the lock and leads
     * (RequestCoalescer::lead()), and answers 503 (the entry stale, on a
     * revalidation) only when that wait times out.
     */
    private const MAX_ENTRIES = 3;

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
     * @var VariantConverter|null
     */
    private $variants;

    /**
     * The negotiator of the variants: the one of $variants, or one that
     * makes no variant but still serves those on disk.
     *
     * @var FormatNegotiator
     */
    private $negotiator;

    /**
     * @var SignedUrlVerifier|null
     */
    private $resize;

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
     * @var RequestCoalescer
     */
    private $coalescer;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var OriginFetchGuard|null
     */
    private $guard;

    /**
     * The entries served stale, to revalidate in terminate(), by key.
     *
     * @var array<string, array{CacheLocation, string, string, string, QueryString}>
     */
    private $pendingRevalidations = [];

    /**
     * When the last origin fetch started (Unix time), for the circuit
     * breaker; null before the first.
     *
     * @var int|null
     */
    private $fetchStartedAt;

    /**
     * The conversions left for after the response (terminate()), by entry
     * key: the entry, whether it is a new version (whose every enabled
     * format is made) and the request ID. Either way the job covers every
     * enabled format of the entry.
     *
     * @var array<string, array{CacheLocation, bool, string|null}>
     */
    private $pendingConversions = [];

    /**
     * The optional $guard runs before each miss is fetched (the miss rate
     * limit and Early Hints of PRX-9, OriginFetchGuard); null for none.
     *
     * @param VariantConverter|null  $variants null when IMAGE_CONVERTER is "none"
     * @param SignedUrlVerifier|null $resize   null when resizing is off
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
        ?VariantConverter $variants,
        Precompressor $precompressor,
        ResponseFactory $responses,
        FileResponder $files,
        RequestCoalescer $coalescer,
        LoggerInterface $logger,
        ?SignedUrlVerifier $resize = null,
        ?OriginFetchGuard $guard = null
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
        $this->variants = $variants;
        $this->negotiator = $variants === null ? new FormatNegotiator([]) : $variants->getNegotiator();
        $this->resize = $resize;
        $this->precompressor = $precompressor;
        $this->responses = $responses;
        $this->files = $files;
        $this->coalescer = $coalescer;
        $this->logger = $logger;
        $this->guard = $guard;
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
            $this->logger->warning('purge refused', ['host' => $host, 'path' => SignedUrlVerifier::mask($path), 'client' => $client]);

            return $this->responses->error($path, ResponseFactory::ERROR_PURGE_UNAUTHORIZED);
        }

        $removed = array_merge($this->purger->purge($location), $this->purgeResized($host, $path, $query));
        $this->logger->notice('purge', [
            'host' => $host,
            'path' => SignedUrlVerifier::mask($path),
            'query' => $query->getCanonical(),
            'removed' => count($removed),
            'auth' => $scheme,
            'client' => $client,
        ]);

        return $this->responses->purged($path, $query->getCanonical(), count($removed));
    }

    /**
     * The files of the resized images of $path, the image of a purge, at
     * every width of RESIZE_WIDTHS (with the query string of the purge):
     * they are removed with it.
     *
     * @return list<string>
     */
    private function purgeResized(string $host, string $path, QueryString $query): array
    {
        if ($this->resize === null || SignedUrlVerifier::isResizePath($path) || !$this->isRaster(self::extensionOf($path))) {
            return [];
        }
        $removed = [];
        foreach ($this->resize->getWidths() as $width) {
            $resized = $this->resize->pathOf($host, $width, $path, $query->getCanonical());
            $removed = array_merge($removed, $this->purger->purge($this->keyResolver->resolve($host, $resized, $query->getCanonical())));
        }

        return $removed;
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

        if ($this->resize !== null && SignedUrlVerifier::isResizePath($path)) {
            $resize = $this->resize->verify($host, $path, $query->getCanonical());
            if (!is_array($resize) || !$this->isRaster($extension)) {
                return $this->responses->error(SignedUrlVerifier::mask($path), ResponseFactory::ERROR_RESIZE_REFUSED);
            }

            return $this->handleResize($request, $origin, $path, $extension, $query, $location, $resize[0], $resize[1]);
        }

        $legacy = $this->isLegacyWebpRequest($extension, $location) ? $this->legacySiblings($host, $path, $query) : [];
        $legacyHit = $this->serveLegacyHit($request, $legacy);
        if ($legacyHit !== null) {
            return $legacyHit;
        }

        // For "X.webp", what the negative cache remembers is that neither it
        // nor any raster sibling exists (fetchLegacy()).
        $remembered = $this->negativeCache->lookup($file);
        if ($remembered !== null) {
            return $this->notFound($path, $remembered);
        }

        // The web server serves the entries it can locate itself, and
        // routes the others here: an entry whose query string it cannot
        // map, one the policy ignored (finding P-5), or one PHP revalidates.
        $stored = $this->serve($request, $location, $extension, ResponseFactory::CACHE_HIT)
            ?? $this->serveManaged($request, $location, $extension, $origin, $path, $query);
        if ($stored !== null) {
            return $stored;
        }

        // Finding P-14: one breaker per origin, checked only before a
        // fetch, so that the entries on disk are served while it is open.
        if ($this->circuitBreaker->isOpen(RedirectTarget::originOf($origin))) {
            return $this->responses->error($path, ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE);
        }

        // Finding P-24: one fetch in flight for a burst of requests on a
        // cold entry, also while the origin fails it; for "X.webp", the
        // fetches of its raster siblings too.
        $key = $location->getKey();
        for ($entries = 1;; ++$entries) {
            $uncacheable = false;
            if ($entries > self::MAX_ENTRIES) {
                $lease = $this->coalescer->lead($key);
            } else {
                $uncacheable = $this->coalescer->isRecentlyUncacheable($key);
                // Ready once the body below has something to answer.
                $lease = $uncacheable ? null : $this->coalescer->enter($key, function () use ($location, $file, $legacy): bool {
                    return $this->storedFile($location) !== null
                        || $this->storage->isFile($location->managed()->getFile())
                        || $this->negativeCache->lookup($file) !== null
                        || $this->legacyHit($legacy) !== null;
                });
            }
            if ($lease === false) {
                return $this->responses->error($path, ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE);
            }
            try {
                // What the leader, or another request, has done meanwhile.
                $legacyHit = $this->serveLegacyHit($request, $legacy);
                if ($legacyHit !== null) {
                    return $legacyHit;
                }
                $remembered = $this->negativeCache->lookup($file);
                if ($remembered !== null) {
                    return $this->notFound($path, $remembered);
                }
                $stored = $this->serve($request, $location, $extension, ResponseFactory::CACHE_HIT)
                    ?? $this->serveManaged($request, $location, $extension, $origin, $path, $query, $lease !== null);
                if ($stored !== null) {
                    return $stored;
                }
                // A request that waited for a leader that stored nothing.
                $shared = $lease === null ? $this->sharedOutcome($key, $path) : null;
                if ($shared !== null) {
                    return $shared;
                }
                if ($lease === null && !$uncacheable && $this->coalescer->isEnabled()) {
                    // What enter() saw is gone (the outcome expired, the
                    // entry or its outcome was purged, or the outcome of
                    // another key replaced it in the slot): enter again
                    // rather than fetch without the lock while the next
                    // leader fetches, and after MAX_ENTRIES times wait for
                    // the lock and lead.
                    continue;
                }

                $refused = $this->guard === null ? null : $this->guard->beforeFetch($request, $path);
                if ($refused !== null) {
                    return $refused;
                }
                $fetched = $this->fetch($request, $origin, $path, $query);
                if ($fetched->isSuccessful()) {
                    return $this->storeAndServe($request, $origin, $location, $extension, $fetched);
                }
                if ($legacy === [] || !$fetched->isNotFound()) {
                    return $this->failure($origin, $path, $location, $fetched);
                }

                return $this->fetchLegacy($request, $origin, $path, $query, $legacy, $location);
            } finally {
                if ($lease !== null) {
                    $lease->release();
                }
            }
        }
    }

    /**
     * The answer to a signed resize URL, verified: its entry when stored;
     * else, by one request of those that miss it together, the image at
     * $imagePath fetched from the origin, resized to $width and stored
     * with its variants at the path of the resize URL, in the tier the web
     * server serves (kept until purged, whatever its lifetime: PURGE of
     * the image removes its resized entries). An image that cannot be
     * resized as asked is stored as it is, so that it is not tried again;
     * when the converter cannot resize now, the image is streamed as it
     * is without being stored, and so is an image the origin forbids
     * storing: either way the requests that waited for it, and those of
     * the coalescer window, fetch and stream it in parallel rather than
     * one after another, none of them resizing it; the first request
     * after the window resizes again, with the lock. A failure of the
     * conversion never fails the request.
     *
     * @param string $path      the decoded path of the resize URL
     * @param string $imagePath the decoded path of the image on the origin
     */
    private function handleResize(Request $request, string $origin, string $path, string $extension, QueryString $query, CacheLocation $location, int $width, string $imagePath): Response
    {
        $logPath = SignedUrlVerifier::mask($path);
        $file = $location->getFile();
        $remembered = $this->negativeCache->lookup($file);
        if ($remembered !== null) {
            return $this->notFound($logPath, $remembered);
        }
        $stored = $this->serve($request, $location, $extension, ResponseFactory::CACHE_HIT);
        if ($stored !== null) {
            return $stored;
        }
        if ($this->circuitBreaker->isOpen(RedirectTarget::originOf($origin))) {
            return $this->responses->error($logPath, ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE);
        }

        // The coalescing of the miss path (handleAsset()): enter again when
        // what enter() saw is gone, then lead after MAX_ENTRIES times.
        $key = $location->getKey();
        for ($entries = 1;; ++$entries) {
            $uncacheable = false;
            if ($entries > self::MAX_ENTRIES) {
                $lease = $this->coalescer->lead($key);
            } else {
                $uncacheable = $this->coalescer->isRecentlyUncacheable($key);
                // Ready once the body below has something to answer: the
                // resized entry, published or pending, or a 404.
                $lease = $uncacheable ? null : $this->coalescer->enter($key, function () use ($location, $file): bool {
                    return $this->storedFile($location) !== null || $this->negativeCache->lookup($file) !== null;
                });
            }
            if ($lease === false) {
                return $this->responses->error($logPath, ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE);
            }
            try {
                $remembered = $this->negativeCache->lookup($file);
                if ($remembered !== null) {
                    return $this->notFound($logPath, $remembered);
                }
                $stored = $this->serve($request, $location, $extension, ResponseFactory::CACHE_HIT);
                if ($stored !== null) {
                    return $stored;
                }
                $shared = $lease === null ? $this->sharedOutcome($key, $logPath) : null;
                if ($shared !== null) {
                    return $shared;
                }
                if ($lease === null && !$uncacheable && $this->coalescer->isEnabled()) {
                    continue;
                }

                $fetched = $this->fetch($request, $origin, $imagePath, $query);
                if ($fetched->getFailureReason() === OriginResponse::FAILURE_REDIRECT) {
                    // The redirect would lead the client to the image, not to
                    // its resized copy.
                    $fetched->release();

                    return $this->sharedError($location, $logPath, ResponseFactory::ERROR_BAD_GATEWAY);
                }
                if (!$fetched->isSuccessful()) {
                    return $this->failure($origin, $logPath, $location, $fetched);
                }
                $this->circuitBreaker->reset(RedirectTarget::originOf($origin));
                // Review G1: within the window of an outcome that stored
                // nothing, the request streams the image without resizing
                // it, whether it found the outcome, waited for the leader
                // that left it, or holds the lock after it (lead()). Only
                // a request that holds the lock once the outcome expired
                // resizes, so one attempt is made per window at most.
                $resize = !$uncacheable && !$this->coalescer->isRecentlyUncacheable($key);

                return $this->resizeAndServe($request, $location, $extension, $width, $fetched, $resize);
            } finally {
                if ($lease !== null) {
                    $lease->release();
                }
            }
        }
    }

    /**
     * Stores the image of $fetched resized as the entry of the resize URL
     * of $location and serves it (see handleResize()).
     *
     * @param bool $resize whether to resize: false streams the image as it is, without storing it
     */
    private function resizeAndServe(Request $request, CacheLocation $location, string $extension, int $width, OriginResponse $fetched, bool $resize): Response
    {
        $logPath = SignedUrlVerifier::mask('/' . $location->getRelativePath());
        $body = $fetched->getBodyFile() ?? ResponseBody::fromString($fetched->getBody(), sys_get_temp_dir());
        if ($body === null) {
            $fetched->release();

            return $this->sharedError($location, $logPath, ResponseFactory::ERROR_CACHE_FILE);
        }
        $freshness = Freshness::fromHeaders($fetched->getHeaders(), time());
        $requestId = self::requestIdOf($request);
        $variants = $this->variants;
        $outcome = !$resize || !$freshness->isStorable() || $variants === null
            ? VariantConverter::UNAVAILABLE
            : $variants->resizeEntry($location, $body->getPath(), $width, $requestId);
        if ($outcome === VariantConverter::UNAVAILABLE || $variants === null) {
            // Nothing is stored: the requests waiting for this one stream
            // the image too, in parallel, as for a response the origin
            // forbids storing, instead of resizing in turn after one
            // another (review F2: a converter that hangs made the last
            // of them wait past the coalescing timeout, then answer 503).
            // The outcome expires with the coalescer window, after which
            // one request resizes again; a stored entry forgets it. Only
            // the request that tried records it: one that streamed
            // without trying would renew the window without a retry
            // (review G1).
            if ($resize) {
                $this->coalescer->recordUncacheable($location->getKey());
            }

            return $this->files->respondUncached($request, $body, $extension, $freshness->isNoStore());
        }
        if ($outcome === VariantConverter::REJECTED) {
            // Stored as it is, with the variants of the image.
            if (!$this->storage->import($variants->newEntryFile($location), $body->getPath())) {
                $fetched->release();
                $body->delete();

                return $this->sharedError($location, $logPath, ResponseFactory::ERROR_CACHE_FILE);
            }
        }
        $variants->forgetFailures($location);
        $this->scheduleConversion($location, true, $requestId);
        $fetched->release();
        $body->delete();
        $this->negativeCache->forget($location->getFile());
        $this->coalescer->forget($location->getKey());

        return $this->serve($request, $location, $extension, ResponseFactory::CACHE_MISS)
            ?? $this->responses->error($logPath, ResponseFactory::ERROR_CACHE_FILE);
    }

    /**
     * Runs the work left for after the response was sent: the background
     * revalidations of the expired entries served stale
     * (stale-while-revalidate), then the AVIF and WEBP variants of the
     * entries this request stored or served without them (finding P-10:
     * no client, and no request waiting for an entry, waits for a
     * conversion). The front controller calls it once the client has its
     * response (fastcgi_finish_request() under PHP-FPM; under a SAPI
     * without it, the response is sent and flushed first, and the
     * connection is held for the conversion budget at most); a host
     * application calls it from its own terminate hook.
     */
    public function terminate(): void
    {
        $this->runRevalidations();
        $this->runConversions();
    }

    /**
     * The background revalidations of terminate().
     */
    private function runRevalidations(): void
    {
        $pending = $this->pendingRevalidations;
        $this->pendingRevalidations = [];
        foreach ($pending as $revalidation) {
            list($location, $extension, $origin, $path, $query) = $revalidation;
            $key = $location->unmanaged()->getKey();
            $lease = $this->coalescer->tryEnter($key);
            if ($lease === null && $this->coalescer->isEnabled()) {
                // Another request is fetching or revalidating the entry.
                continue;
            }
            try {
                $metadata = $this->readMetadata($location);
                if ($metadata === null || !$metadata->isFresh(time())) {
                    $this->revalidate(null, $location, $extension, $origin, $path, $query, $metadata);
                }
            } finally {
                if ($lease !== null) {
                    $lease->release();
                }
            }
        }
    }

    /**
     * The 404 or 410 that the negative cache remembers.
     */
    private function notFound(string $path, int $status): Response
    {
        return $this->responses->error($path, $status === 410 ? ResponseFactory::ERROR_ORIGIN_GONE : ResponseFactory::ERROR_ORIGIN_NOT_FOUND);
    }

    /**
     * The response of an entry that PHP revalidates with the origin
     * (finding P-15, opportunity O-D), when it is on disk: fresh, it is
     * served with the max-age it has left; expired within its
     * stale-while-revalidate, it is served stale and revalidated after the
     * response (terminate()); expired beyond, it is revalidated first, by
     * one request at a time. Null when the entry is not on disk.
     *
     * @param CacheLocation $location  the entry, in the tier the web server serves
     * @param bool          $holdsLock whether this request already holds the lock of the entry (the lock is not
     *                                 reentrant)
     *
     * @phpstan-impure
     */
    private function serveManaged(Request $request, CacheLocation $location, string $extension, string $origin, string $path, QueryString $query, bool $holdsLock = false): ?Response
    {
        $managed = $location->managed();
        if (!$this->storage->isFile($managed->getFile())) {
            return null;
        }
        $now = time();
        $metadata = $this->readMetadata($managed);
        if ($metadata !== null && $metadata->isFresh($now)) {
            return $this->serve($request, $managed, $extension, ResponseFactory::CACHE_HIT, $metadata->getRemainingLifetime($now));
        }
        if ($metadata !== null && $metadata->isWithinStaleWhileRevalidate($now)) {
            $this->pendingRevalidations[$managed->getKey()] = [$managed, $extension, $origin, $path, $query];

            return $this->serve($request, $managed, $extension, ResponseFactory::CACHE_STALE, 0);
        }

        if ($holdsLock) {
            return $this->revalidate($request, $managed, $extension, $origin, $path, $query, $metadata);
        }
        // One lock per entry, in either tier: the one of the miss path.
        $key = $location->getKey();
        for ($entries = 1;; ++$entries) {
            $lease = $entries > self::MAX_ENTRIES ? $this->coalescer->lead($key) : $this->coalescer->enter($key, function () use ($managed): bool {
                $current = $this->readMetadata($managed);

                return !$this->storage->isFile($managed->getFile()) || ($current !== null && $current->isFresh(time()));
            });
            if ($lease === false) {
                return $this->staleOrError($request, $managed, $extension, $path, $metadata, ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE);
            }
            try {
                $metadata = $this->readMetadata($managed);
                $now = time();
                if ($metadata !== null && $metadata->isFresh($now)) {
                    return $this->serve($request, $managed, $extension, ResponseFactory::CACHE_HIT, $metadata->getRemainingLifetime($now));
                }
                if (!$this->storage->isFile($managed->getFile())) {
                    // Purged, or found gone, meanwhile.
                    return null;
                }
                // A request that waited for a leader the origin failed.
                $failed = $lease === null ? $this->coalescer->recentFailure($key) : null;
                if ($failed !== null) {
                    return $this->staleOrError($request, $managed, $extension, $path, $metadata, $failed);
                }
                $redirect = $lease === null ? $this->coalescer->recentRedirect($key) : null;
                if ($redirect !== null) {
                    return $this->responses->redirect($redirect[0], $redirect[1]);
                }
                if ($lease === null && $this->coalescer->isEnabled() && !$this->coalescer->isRecentlyUncacheable($key)) {
                    // What enter() saw is gone, as on the miss path.
                    continue;
                }

                return $this->revalidate($request, $managed, $extension, $origin, $path, $query, $metadata);
            } finally {
                if ($lease !== null) {
                    $lease->release();
                }
            }
        }
    }

    /**
     * The answer of an expired entry that the origin cannot revalidate now
     * (another request has just found it failing, or waited too long for
     * it): the entry served stale while its stale-if-error allows, else
     * the error.
     *
     * @param int $error one of the ResponseFactory::ERROR_* constants
     */
    private function staleOrError(Request $request, CacheLocation $managed, string $extension, string $path, ?EntryMetadata $metadata, int $error): Response
    {
        $stale = $metadata === null || $metadata->isWithinStaleIfError(time())
            ? $this->serve($request, $managed, $extension, ResponseFactory::CACHE_STALE, 0)
            : null;

        return $stale ?? $this->responses->error($path, $error);
    }

    /**
     * Asks the origin whether the expired entry $managed is still current,
     * with its validators: a 304 renews it, a 200 replaces it, a 404 or 410
     * removes it, and a failure leaves it, served stale while its
     * stale-if-error allows (and while the breaker of the origin is open).
     *
     * @param Request|null $request the request to answer; null for a background revalidation
     *
     * @return Response|null the response to $request; null without request, or when the entry was removed
     */
    private function revalidate(?Request $request, CacheLocation $managed, string $extension, string $origin, string $path, QueryString $query, ?EntryMetadata $metadata): ?Response
    {
        $breakerKey = RedirectTarget::originOf($origin);
        $mayServeStale = $metadata === null || $metadata->isWithinStaleIfError(time());
        if ($this->circuitBreaker->isOpen($breakerKey)) {
            if ($request === null) {
                return null;
            }

            return $mayServeStale
                ? $this->serve($request, $managed, $extension, ResponseFactory::CACHE_STALE, 0)
                : $this->responses->error($path, ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE);
        }

        $fetched = $this->fetchFromOrigin($this->originRequest($request, $origin, $path, $query, $metadata === null ? [] : $metadata->conditionalHeaders()));
        $now = time();
        if ($fetched->isNotModified() && $metadata !== null) {
            $renewed = $metadata->revalidated($fetched->getHeaders(), $now, $this->config->getStaleWhileRevalidate(), $this->config->getStaleIfError());
            $this->storage->write($managed->getMetadataFile(), $renewed->toJson());
            $this->circuitBreaker->reset($breakerKey);

            return $request === null ? null : $this->serve($request, $managed, $extension, ResponseFactory::CACHE_REVALIDATED, $renewed->getRemainingLifetime($now));
        }
        $web = $managed->unmanaged();
        if ($fetched->isSuccessful()) {
            if ($request === null) {
                if (!Freshness::fromHeaders($fetched->getHeaders(), $now)->isStorable()) {
                    // The origin no longer lets the entry be stored.
                    $fetched->release();
                    foreach ($managed->getFiles() as $file) {
                        $this->storage->delete($file);
                    }

                    return null;
                }
                $this->storeFetched($web, $extension, $fetched);
                $this->circuitBreaker->reset($breakerKey);

                return null;
            }

            return $this->storeAndServe($request, $origin, $web, $extension, $fetched);
        }
        $fetched->release();

        $reason = $fetched->getFailureReason();
        if ($reason === OriginResponse::FAILURE_NOT_FOUND || $reason === OriginResponse::FAILURE_GONE) {
            foreach ($managed->getFiles() as $file) {
                $this->storage->delete($file);
            }
        } elseif ($mayServeStale && (in_array($reason, self::UNAVAILABLE, true) || $fetched->getStatus() === 429)) {
            // The outcome first, then the breaker: see failure().
            $this->coalescer->recordFailure($web->getKey(), ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE);
            $this->circuitBreaker->recordFailure($breakerKey, $this->fetchStartedAt);
            $this->logger->warning('stale entry served, origin unavailable', ['path' => SignedUrlVerifier::mask($path), 'reason' => $reason]);

            return $request === null ? null : $this->serve($request, $managed, $extension, ResponseFactory::CACHE_STALE, 0);
        }
        $answer = $this->failure($origin, $path, $web, $fetched);

        return $request === null ? null : $answer;
    }

    /**
     * The EntryMetadata of a managed entry; null when it is missing or
     * unreadable, and the entry is then treated as expired without
     * validators.
     */
    private function readMetadata(CacheLocation $managed): ?EntryMetadata
    {
        $json = $this->storage->read($managed->getMetadataFile());

        return $json === null ? null : EntryMetadata::fromJson($json);
    }

    /**
     * Fetches the asset of $path from $origin, with the query string the
     * policy kept.
     */
    private function fetch(Request $request, string $origin, string $path, QueryString $query): OriginResponse
    {
        return $this->fetchFromOrigin($this->originRequest($request, $origin, $path, $query, []));
    }

    /**
     * Every origin fetch of the kernel, which notes when it started: the
     * circuit breaker measures the pause between failures up to the start
     * of the fetch that failed, not to its end.
     */
    private function fetchFromOrigin(OriginRequest $request): OriginResponse
    {
        $this->fetchStartedAt = time();

        return $this->origin->fetch($request);
    }

    /**
     * The origin request for the asset of $path.
     *
     * @param Request|null          $request    the client request, for the Referer; null in the background
     * @param array<string, string> $conditions If-None-Match and If-Modified-Since of a revalidation
     */
    private function originRequest(?Request $request, string $origin, string $path, QueryString $query, array $conditions): OriginRequest
    {
        $headers = $conditions;
        if ($request !== null) {
            // The scheme the client used, as a trusted proxy reports it (TRUSTED_PROXIES).
            $headers['Referer'] = ($this->trustedProxies->isSecure($request) ? 'https' : 'http') . '://' . $request->getHttpHost();
        }
        $forwardedQuery = $query->getForwarded();
        $originUrl = $origin . PathSanitizer::encode($path) . ($forwardedQuery !== '' ? '?' . $forwardedQuery : '');

        return new OriginRequest($originUrl, $headers);
    }

    /**
     * Stores a fetched asset with its siblings and serves it, or streams it
     * without storing it when the origin forbids that (no-store, private:
     * finding P-15).
     *
     * @param CacheLocation $location the entry, in the tier the web server serves
     */
    private function storeAndServe(Request $request, string $origin, CacheLocation $location, string $extension, OriginResponse $fetched): Response
    {
        $path = '/' . $location->getRelativePath();
        $freshness = Freshness::fromHeaders($fetched->getHeaders(), time());
        if (!$freshness->isStorable()) {
            $this->circuitBreaker->reset(RedirectTarget::originOf($origin));
            // The requests of the entry need not wait for one another.
            $this->coalescer->recordUncacheable($location->getKey());
            // An expired entry that the origin no longer lets be stored.
            foreach ($location->managed()->getFiles() as $file) {
                $this->storage->delete($file);
            }
            $body = $fetched->getBodyFile() ?? ResponseBody::fromString($fetched->getBody(), sys_get_temp_dir());
            if ($body === null) {
                return $this->responses->error($path, ResponseFactory::ERROR_CACHE_FILE);
            }

            return $this->files->respondUncached($request, $body, $extension, $freshness->isNoStore());
        }

        $result = $this->storeFetched($location, $extension, $fetched, $freshness, self::requestIdOf($request));
        if (is_int($result)) {
            return $this->sharedError($location, $path, $result);
        }
        list($stored, $maxAge) = $result;

        // The asset is back: forget any stale failure.
        $this->circuitBreaker->reset(RedirectTarget::originOf($origin));

        return $this->serve($request, $stored, $extension, ResponseFactory::CACHE_MISS, $maxAge)
            ?? $this->responses->error($path, ResponseFactory::ERROR_CACHE_FILE);
    }

    /**
     * Stores a fetched asset in its tier: the one PHP revalidates when the
     * origin gives it a lifetime below CACHE_REVALIDATE_BELOW, with its
     * metadata; else the one the web server serves, kept until purged. The
     * entry of the other tier is removed.
     *
     * @param CacheLocation $location  the entry, in the tier the web server serves
     * @param string|null   $requestId the ID of the client request, for the conversion API
     *
     * @return int|array{CacheLocation, int|null} the ResponseFactory::ERROR_* of a failure; or the stored entry and
     *                                            the max-age it has left (null in the tier of the web server)
     */
    private function storeFetched(CacheLocation $location, string $extension, OriginResponse $fetched, ?Freshness $freshness = null, ?string $requestId = null)
    {
        $now = time();
        $freshness = $freshness ?? Freshness::fromHeaders($fetched->getHeaders(), $now);
        $lifetime = $freshness->getLifetime();
        $isManaged = $lifetime !== null && $lifetime < $this->config->getCacheRevalidateBelow();
        $target = $isManaged ? $location->managed() : $location;
        foreach (($isManaged ? $location : $location->managed())->getFiles() as $file) {
            $this->storage->delete($file);
        }

        $error = $this->store($target, $extension, $fetched, $requestId);
        if ($error !== null) {
            return $error;
        }
        $this->negativeCache->forget($location->getFile());
        $this->coalescer->forget($location->getKey());
        if (!$isManaged) {
            return [$target, null];
        }
        $metadata = EntryMetadata::fromResponse($fetched->getHeaders(), $freshness, $now, $this->config->getStaleWhileRevalidate(), $this->config->getStaleIfError());
        if (!$this->storage->write($target->getMetadataFile(), $metadata->toJson())) {
            return ResponseFactory::ERROR_CACHE_FILE;
        }

        return [$target, $metadata->getRemainingLifetime($now)];
    }

    /**
     * Writes the body of $fetched as the entry of $location, replacing the
     * entry and the siblings of an older version, and writes its
     * compressed siblings; the AVIF and WEBP variants of a raster are made
     * after the response (terminate()), so that neither the client nor
     * the requests waiting for the entry wait for a conversion. A raster
     * of the tier the web server serves is written to its pending file
     * meanwhile, served by PHP only, and published once its variants are
     * settled (publish()): the web server never serves the original to a
     * client that is about to get a variant. The body
     * is copied from its temporary file in chunks (finding P-17), which is
     * then deleted.
     *
     * @param string|null $requestId the ID of the client request, for the conversion API
     *
     * @return int|null the ResponseFactory::ERROR_* constant of a failure, null on success
     */
    private function store(CacheLocation $location, string $extension, OriginResponse $fetched, ?string $requestId = null): ?int
    {
        if (!$this->storage->ensureDirectory($location->getDirectory())) {
            $fetched->release();

            return ResponseFactory::ERROR_CACHE_DIRECTORY;
        }
        $target = $this->isRaster($extension) && $this->variants !== null ? $this->variants->newEntryFile($location) : $location->getFile();
        foreach ($location->getFiles() as $file) {
            if ($file !== $target) {
                $this->storage->delete($file);
            }
        }
        $body = $fetched->getBodyFile();
        $written = $body === null
            ? $this->storage->write($target, $fetched->getBody())
            : $this->storage->import($target, $body->getPath());
        $fetched->release();
        if (!$written) {
            return ResponseFactory::ERROR_CACHE_FILE;
        }
        $this->precompressor->compress($location, $extension);
        if ($this->isRaster($extension) && $this->variants !== null) {
            $this->variants->forgetFailures($location);
            $this->scheduleConversion($location, true, $requestId);
        }

        return null;
    }

    /**
     * The response of the entry of $location when it is on disk: a raster
     * as its AVIF or WEBP variant when the client accepts it, any other
     * entry as its Brotli or gzip sibling when the client accepts it; null
     * when the entry is not on disk. A variant the client prefers that is
     * missing, enabled and not remembered as failed is made after the
     * response (convertLater()); meanwhile the response says
     * "max-age=0, must-revalidate", so that no cache keeps the original,
     * or a variant the client likes less, for CACHE_EXPIRY. So does the
     * original of a pending entry (storedFile()), whatever the client
     * accepts, until it is published.
     *
     * @param string   $cacheStatus one of the ResponseFactory::CACHE_* constants
     * @param int|null $maxAge      the max-age of a managed entry; null for CACHE_EXPIRY
     *
     * @phpstan-impure
     */
    private function serve(Request $request, CacheLocation $location, string $extension, string $cacheStatus, ?int $maxAge = null): ?Response
    {
        $file = $this->storedFile($location);
        if ($file === null) {
            return null;
        }
        if (!$this->isRaster($extension)) {
            $encoded = [];
            if (Precompressor::isCompressible($extension)) {
                foreach (CacheLocation::ENCODINGS as $encoding) {
                    $encoded[$encoding] = $location->getEncodedFile($encoding);
                }
            }

            return $this->files->respond($request, $file, $extension, $cacheStatus, $encoded, [], $maxAge);
        }

        $unpublished = $file !== $location->getFile();
        if ($unpublished) {
            // Its variants are made, or tried again, and it is published.
            $this->scheduleConversion($location, false, self::requestIdOf($request));
        }
        $pending = false;
        foreach ($this->negotiator->acceptedFormats((string) $request->headers->get('Accept', '')) as $format) {
            // The variants of a pending entry wait with it (review F1).
            $variant = $unpublished ? $location->getPendingVariantFile($format) : $location->getVariantFile($format);
            if ($this->storage->isFile($variant)) {
                return $this->files->respond($request, $variant, $format, $cacheStatus, [], ['Accept'], $pending || $unpublished ? 0 : $maxAge);
            }
            $pending = $this->convertLater($request, $location, $format) || $pending;
        }

        return $this->files->respond($request, $file, $extension, $cacheStatus, [], ['Accept'], $pending || $unpublished ? 0 : $maxAge);
    }

    /**
     * Leaves the missing variant $format of the stored entry of $location
     * to be made after the response, when it may be made
     * (VariantConverter::shouldConvert(), which asks the converter
     * nothing). Whether it will be.
     */
    private function convertLater(Request $request, CacheLocation $location, string $format): bool
    {
        if ($this->variants === null) {
            return false;
        }
        $scheduled = $this->pendingConversions[$location->getKey()] ?? null;
        if ($scheduled !== null && $scheduled[1]) {
            // A new version: every enabled format is made.
            return $this->negotiator->isEnabled($format);
        }
        if (!$this->variants->shouldConvert($location, $format)) {
            return false;
        }
        $this->scheduleConversion($location, false, self::requestIdOf($request));

        return true;
    }

    /**
     * Leaves the conversions of the entry of $location for after the
     * response, every enabled format of it: of a new version ($newVersion,
     * which forgets the failures of the former one), else the missing
     * variants that may be made.
     */
    private function scheduleConversion(CacheLocation $location, bool $newVersion, ?string $requestId): void
    {
        if ($this->variants === null) {
            return;
        }
        $key = $location->getKey();
        $scheduled = $this->pendingConversions[$key] ?? null;
        $this->pendingConversions[$key] = [$location, $newVersion || ($scheduled !== null && $scheduled[1]), $requestId];
    }

    /**
     * Makes the conversions left for after the response, within one
     * IMAGE_CONVERSION_TIMEOUT per format of IMAGE_FORMATS (as long as
     * the conversions of one entry may take): no conversion starts once
     * it is spent, and one that runs is bounded by the timeout. Each
     * entry is converted by one request at a time, under a lock of its
     * own that covers every format of it: a request that finds it taken
     * leaves the entry to the request that holds it. A pending entry is
     * published afterwards when it may be (publish()); else a later
     * request for it, which PHP answers, tries again.
     */
    private function runConversions(): void
    {
        $pending = $this->pendingConversions;
        $this->pendingConversions = [];
        $variants = $this->variants;
        if ($variants === null || $pending === []) {
            return;
        }
        $budget = $this->config->getImageConversionTimeout() * max(1, count($this->config->getImageFormats()));
        $deadline = microtime(true) + $budget;
        foreach ($pending as $key => $conversion) {
            list($location, $newVersion, $requestId) = $conversion;
            $lease = $this->coalescer->tryEnter($key . '#variants');
            if ($lease === null && $this->coalescer->isEnabled()) {
                continue;
            }
            try {
                $source = $this->storedFile($location);
                if ($source === null) {
                    continue;
                }
                if ($newVersion) {
                    $variants->convertAll($location, $source, $requestId, $deadline);
                } else {
                    foreach ($this->negotiator->getEnabledFormats() as $format) {
                        if (microtime(true) >= $deadline) {
                            break;
                        }
                        $variants->convertOnHit($location, $format, $requestId);
                    }
                }
                if (microtime(true) >= $deadline && !$variants->isSettled($location)) {
                    $this->logger->notice('image conversions left for a later request', ['path' => SignedUrlVerifier::mask('/' . $location->getRelativePath())]);
                }
                $this->publish($location);
            } finally {
                if ($lease !== null) {
                    $lease->release();
                }
            }
        }
    }

    /**
     * Publishes the pending raster entry of $location in the tier the web
     * server serves, its variants then itself, each with one rename(),
     * once its variants are settled
     * (VariantConverter::isSettled()), or once it has waited
     * PENDING_HOLD_SECONDS for them: then without the variants still
     * missing, which are not tried again until the entry is stored anew
     * (the web server serves its hits). Until then PHP serves it, with
     * "max-age=0".
     */
    private function publish(CacheLocation $location): void
    {
        $pendingFile = $location->getPendingFile();
        if ($this->variants === null || !$this->storage->isFile($pendingFile)) {
            return;
        }
        $settled = $this->variants->isSettled($location);
        $storedAt = $this->storage->modifiedAt($pendingFile);
        if (!$settled && $storedAt !== null && time() - $storedAt < self::PENDING_HOLD_SECONDS) {
            return;
        }
        $path = SignedUrlVerifier::mask('/' . $location->getRelativePath());
        // The variants first: once the entry is published, the web server
        // serves them. Each is settled already, so serving one before the
        // entry is published gives no client a variant it would not keep.
        foreach (CacheLocation::FORMATS as $format) {
            $staged = $location->getPendingVariantFile($format);
            if ($this->storage->isFile($staged) && !$this->storage->move($staged, $location->getVariantFile($format))) {
                $this->logger->warning('image entry not published', ['path' => $path, 'format' => $format]);

                return;
            }
        }
        if (!$this->storage->move($pendingFile, $location->getFile())) {
            $this->logger->warning('image entry not published', ['path' => $path]);

            return;
        }
        if (!$settled) {
            $this->logger->notice('image entry published without all its variants', ['path' => $path, 'pending_seconds' => self::PENDING_HOLD_SECONDS]);
        }
    }

    /**
     * The stored entry of $location: the published file, else its pending
     * one (VariantConverter::newEntryFile()); null when neither exists.
     *
     * @phpstan-impure
     */
    private function storedFile(CacheLocation $location): ?string
    {
        if ($this->variants === null) {
            return $this->storage->isFile($location->getFile()) ? $location->getFile() : null;
        }

        return $this->variants->storedFileOf($location);
    }

    /**
     * The ID of $request (REQUEST_ID_ATTRIBUTE), when one was set.
     */
    private static function requestIdOf(Request $request): ?string
    {
        $requestId = $request->attributes->get(self::REQUEST_ID_ATTRIBUTE);

        return is_string($requestId) && $requestId !== '' ? $requestId : null;
    }

    /**
     * Whether a request for $location is for "X.webp" that the cache does
     * not hold, to be resolved from a raster sibling (LEGACY_WEBP_URLS).
     *
     * Such a request reaches PHP even when the sibling is cached, by
     * design: the web server knows neither the setting nor the order of
     * the siblings, nor that a raster without WEBP variant is answered as
     * its own type, and it cannot convert one. The URLs exist only for
     * the clients that cached a 301 of 2.x.
     */
    private function isLegacyWebpRequest(string $extension, CacheLocation $location): bool
    {
        return $extension === CacheLocation::FORMAT_WEBP
            && $this->config->shouldResolveLegacyWebpUrls()
            && $this->storedFile($location) === null
            && !$this->storage->isFile($location->managed()->getFile());
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
     * variant, converted first when WEBP is enabled, or else the
     * raster itself, typed as what it is (risk L-4: the browsers that
     * cached a 301 of 2.x to "X.webp" keep asking for it, and the origin
     * has no such file).
     */
    private function serveLegacy(Request $request, CacheLocation $location, string $extension, string $cacheStatus, ?int $maxAge = null): Response
    {
        $file = $this->storedFile($location) ?? $location->getFile();
        $unpublished = $file !== $location->getFile();
        $webp = $unpublished ? $location->getPendingVariantFile(CacheLocation::FORMAT_WEBP) : $location->getVariantFile(CacheLocation::FORMAT_WEBP);
        if ($this->storage->isFile($webp)) {
            if ($unpublished) {
                $this->scheduleConversion($location, false, self::requestIdOf($request));
            }

            return $this->files->respond($request, $webp, CacheLocation::FORMAT_WEBP, $cacheStatus, [], [], $unpublished ? 0 : $maxAge);
        }
        if ($unpublished) {
            $this->scheduleConversion($location, false, self::requestIdOf($request));
        }
        $pending = $this->convertLater($request, $location, CacheLocation::FORMAT_WEBP);

        return $this->files->respond($request, $file, $extension, $cacheStatus, [], [], $pending || $unpublished ? 0 : $maxAge);
    }

    /**
     * The answer to "X.webp" from a raster sibling the cache holds: in the
     * tier the web server serves, or fresh in the one PHP revalidates (an
     * expired one is fetched again); null when none is held.
     *
     * @param list<array{string, CacheLocation}> $siblings
     *
     * @phpstan-impure
     */
    private function serveLegacyHit(Request $request, array $siblings): ?Response
    {
        $hit = $this->legacyHit($siblings);
        if ($hit === null) {
            return null;
        }
        list($extension, $location, $maxAge) = $hit;

        return $this->serveLegacy($request, $location, $extension, ResponseFactory::CACHE_HIT, $maxAge);
    }

    /**
     * The raster sibling of "X.webp" that serveLegacyHit() serves, with
     * its extension and the max-age it has left (null in the tier the web
     * server serves); null when the cache holds none it may serve. The
     * requests waiting on "X.webp" take only such a sibling as ready: an
     * expired one is fetched again by the leader.
     *
     * @param list<array{string, CacheLocation}> $siblings
     *
     * @return array{string, CacheLocation, int|null}|null
     *
     * @phpstan-impure
     */
    private function legacyHit(array $siblings): ?array
    {
        $now = time();
        foreach ($siblings as $sibling) {
            list($extension, $location) = $sibling;
            if ($this->storedFile($location) !== null) {
                return [$extension, $location, null];
            }
            $managed = $location->managed();
            $metadata = $this->storage->isFile($managed->getFile()) ? $this->readMetadata($managed) : null;
            if ($metadata !== null && $metadata->isFresh($now)) {
                return [$extension, $managed, $metadata->getRemainingLifetime($now)];
            }
        }

        return null;
    }

    /**
     * Fetches the raster siblings of "X.webp" from the origin, in order,
     * until one exists, then answers it as a fetched asset is answered: it
     * is streamed without being stored when the origin forbids storing it,
     * else stored in the tier its lifetime calls for; the 404 of "X.webp"
     * when none exists, which the negative cache then remembers for
     * "X.webp" itself. What it answers without storing is shared with the
     * requests that wait on "X.webp".
     *
     * @param list<array{string, CacheLocation}> $siblings
     * @param CacheLocation                      $webp     the entry of "X.webp", whose lock this request holds
     */
    private function fetchLegacy(Request $request, string $origin, string $path, QueryString $query, array $siblings, CacheLocation $webp): Response
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
                return $this->failure($origin, $path, $location, $fetched, $webp);
            }
            $this->circuitBreaker->reset(RedirectTarget::originOf($origin));
            $this->logger->info('legacy webp url', ['path' => SignedUrlVerifier::mask($path), 'sibling' => SignedUrlVerifier::mask($siblingPath)]);
            $freshness = Freshness::fromHeaders($fetched->getHeaders(), time());
            if (!$freshness->isStorable()) {
                $this->coalescer->recordUncacheable($webp->getKey());
                foreach ($location->managed()->getFiles() as $file) {
                    $this->storage->delete($file);
                }
                $body = $fetched->getBodyFile() ?? ResponseBody::fromString($fetched->getBody(), sys_get_temp_dir());
                if ($body === null) {
                    return $this->responses->error($path, ResponseFactory::ERROR_CACHE_FILE);
                }

                return $this->files->respondUncached($request, $body, $extension, $freshness->isNoStore());
            }
            $result = $this->storeFetched($location, $extension, $fetched, $freshness, self::requestIdOf($request));
            if (is_int($result)) {
                return $this->sharedError($webp, $path, $result);
            }
            list($stored, $maxAge) = $result;

            return $this->serveLegacy($request, $stored, $extension, ResponseFactory::CACHE_MISS, $maxAge);
        }

        // Remembered last, and once for all the siblings: the next request
        // answers 404 from this one entry, even when two of the entries of
        // the siblings share a slot of the negative cache and evict each
        // other.
        $this->negativeCache->remember($webp->getFile(), 404);

        return $this->sharedError($webp, $path, ResponseFactory::ERROR_ORIGIN_NOT_FOUND);
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
     *
     * What it answers is recorded for the requests waiting on $sharedAs,
     * the entry whose lock this request holds (by default $location).
     */
    private function failure(string $origin, string $path, CacheLocation $location, OriginResponse $fetched, ?CacheLocation $sharedAs = null): Response
    {
        $file = $location->getFile();
        $shared = $sharedAs ?? $location;
        $reason = $fetched->getFailureReason();
        $breakerKey = RedirectTarget::originOf($origin);
        if (in_array($reason, self::UNAVAILABLE, true) || $fetched->getStatus() === 429) {
            // The outcome is recorded before the failure is counted: the
            // next fetch of the entry comes once the outcome expired, so
            // the next failing fetch starts at most the window after this
            // failure is counted, and ProxyKernelFactory keeps the window
            // within NETWORK_ERROR_TTL (the pause that starts the count
            // again, up to the start of a failing fetch).
            $response = $this->sharedError($shared, $path, ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE);
            $this->circuitBreaker->recordFailure($breakerKey, $this->fetchStartedAt);

            return $response;
        }
        if ($fetched->getStatus() > 0 && $fetched->getTransportErrorCode() === 0) {
            $this->circuitBreaker->reset($breakerKey);
        }

        if ($reason === OriginResponse::FAILURE_NOT_FOUND || $reason === OriginResponse::FAILURE_GONE) {
            $gone = $reason === OriginResponse::FAILURE_GONE;
            $this->negativeCache->remember($file, $gone ? 410 : 404);

            // Shared also when NEGATIVE_CACHE_TTL is 0.
            return $this->sharedError($shared, $path, $gone ? ResponseFactory::ERROR_ORIGIN_GONE : ResponseFactory::ERROR_ORIGIN_NOT_FOUND);
        }
        if ($reason === OriginResponse::FAILURE_FORBIDDEN) {
            return $this->sharedError($shared, $path, ResponseFactory::ERROR_ORIGIN_FORBIDDEN);
        }
        if ($reason === OriginResponse::FAILURE_REDIRECT) {
            $target = $this->proxyLocation($origin, $fetched->getLocation());
            if ($target === null) {
                return $this->sharedError($shared, $path, ResponseFactory::ERROR_BAD_GATEWAY);
            }
            $this->coalescer->recordRedirect($shared->getKey(), $fetched->getStatus(), $target);

            return $this->responses->redirect($fetched->getStatus(), $target);
        }

        return $this->sharedError($shared, $path, ResponseFactory::ERROR_BAD_GATEWAY);
    }

    /**
     * The answer a leader recorded for the entry of $key within the window
     * of RequestCoalescer, when it stored nothing: its error, or the
     * redirect it passed on; null when there is none.
     */
    private function sharedOutcome(string $key, string $path): ?Response
    {
        $failed = $this->coalescer->recentFailure($key);
        if ($failed !== null) {
            return $this->responses->error($path, $failed);
        }
        $redirect = $this->coalescer->recentRedirect($key);

        return $redirect === null ? null : $this->responses->redirect($redirect[0], $redirect[1]);
    }

    /**
     * The error response of a failed fetch, recorded for the requests that
     * coalesce on the entry (RequestCoalescer): they answer with it for a
     * few seconds instead of fetching in turn.
     *
     * @param CacheLocation $location the entry, in the tier the web server serves
     * @param int           $error    one of the ResponseFactory::ERROR_* constants
     */
    private function sharedError(CacheLocation $location, string $path, int $error): Response
    {
        $this->coalescer->recordFailure($location->getKey(), $error);

        return $this->responses->error($path, $error);
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
