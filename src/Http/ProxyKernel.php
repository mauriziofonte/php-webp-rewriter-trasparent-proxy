<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Cache\CacheStorage;
use Mfonte\ProxyCdn\Cache\NegativeCache;
use Mfonte\ProxyCdn\Cache\Purger;
use Mfonte\ProxyCdn\Cache\QueryStringPolicy;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Image\WebpConverter;
use Mfonte\ProxyCdn\Origin\CircuitBreaker;
use Mfonte\ProxyCdn\Origin\OriginFetcher;
use Mfonte\ProxyCdn\Origin\OriginRequest;
use Mfonte\ProxyCdn\Security\PathSanitizer;
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
 * 2. "purge=1" or "purge_all=1" in the query string removes the entry first
 *    (anonymously: finding L-7);
 * 3. a fresh 404 marker or an open circuit breaker answers without asking
 *    the origin;
 * 4. the query string is reduced to the parameters of QueryStringPolicy,
 *    which key the entry and reach the origin as the client wrote them;
 *    a raster whose WEBP sibling exists is served as WEBP to clients that
 *    accept it; an entry on disk is served from disk;
 * 5. otherwise the asset is fetched from the origin and stored as the
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
        $method = $request->getMethod();
        if ($method === 'OPTIONS') {
            return $this->responses->preflight((string) $request->headers->get('Access-Control-Request-Headers', ''));
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
     * The answer to a GET of an asset.
     */
    private function handleAsset(Request $request): Response
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

        $rawQuery = $request->server->get('QUERY_STRING');
        $rawQuery = is_scalar($rawQuery) ? (string) $rawQuery : '';
        $query = $this->queryPolicy->apply($rawQuery);
        if ($query === null) {
            return $this->responses->error($path, ResponseFactory::ERROR_QUERY_NOT_ALLOWED);
        }
        $location = $this->keyResolver->resolve($path, $query->getCanonical());
        $file = $location->getFile();

        $purgeAll = self::hasFlag($rawQuery, 'purge_all');
        if ($purgeAll || self::hasFlag($rawQuery, 'purge')) {
            foreach ($this->purger->purge($file, $purgeAll) as $entry) {
                $this->circuitBreaker->reset($entry);
            }
            $this->logger->info('purge', ['file' => $file, 'aggressive' => $purgeAll]);
        }

        if ($this->negativeCache->isFresh($file)) {
            return $this->responses->error($path, ResponseFactory::ERROR_ORIGIN_NOT_FOUND);
        }
        if ($this->circuitBreaker->isOpen($file)) {
            return $this->responses->error($path, ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE);
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

        $server = $request->getScheme() . '://' . $request->getHttpHost();
        $forwardedQuery = $query->getForwarded();
        $originUrl = $this->config->getSourceOrigin() . PathSanitizer::encode($path) . ($forwardedQuery !== '' ? '?' . $forwardedQuery : '');
        $fetched = $this->origin->fetch(new OriginRequest($originUrl, ['Referer' => $server]));

        if (!$fetched->isSuccessful()) {
            if ($fetched->isNotFound()) {
                $this->negativeCache->remember($file);

                return $this->responses->error($path, ResponseFactory::ERROR_ORIGIN_NOT_FOUND);
            }
            // Every other failure opens the breaker, redirects, 403 and 410
            // included (finding P-13, resolved by PRX-5).
            $this->circuitBreaker->recordFailure($file);

            return $this->responses->error($path, ResponseFactory::ERROR_UPSTREAM_UNAVAILABLE);
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
        $this->circuitBreaker->reset($file);

        return $this->responses->miss($body, $headers, $servedWebp, $extension);
    }

    /**
     * Whether the raw query string sets $name to "1" or "true", in any case.
     */
    private static function hasFlag(string $rawQuery, string $name): bool
    {
        return preg_match('/(?:^|&)' . $name . '=(?:1|true)(?:&|$)/i', $rawQuery) === 1;
    }
}
