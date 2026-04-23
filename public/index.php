<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

define('PARENT_DIR', rtrim(realpath(__DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);

if (! is_file(PARENT_DIR.'bootstrap.php')) {
    die('Cannot find app boostrapper. Re-sync this repository, as it won\'t work without the application boostrapper.');
}

require_once PARENT_DIR.'bootstrap.php';

/**
 * ---------------------------------------------------------------------------
 * Transparent Proxy CDN - request dispatcher
 *
 * Flow:
 *  1. guard rails: OPTIONS, directory traversal, extension whitelist
 *  2. resolve cache location (QS-aware: meaningful query strings are bucketed
 *     under public/_qs/<sha256(canonical_qs)>/ so they are cached per-version)
 *  3. handle purge= / purge_all= if present
 *  4. short-circuit on recent negative-cache markers (.404, .netfail)
 *  5. serve from disk if possible (this is the main path for QS-keyed hits,
 *     which Apache cannot serve directly because the query string always
 *     routes through index.php)
 *  6. on miss, fetch origin with a distinguished error envelope so that a
 *     real 404 arms the long-TTL .404 marker and any network-class failure
 *     arms the short-TTL .netfail marker (circuit breaker)
 * ---------------------------------------------------------------------------
 */

// ----- 1. per-request init --------------------------------------------------
$SOURCE_ORIGIN = rtrim(SOURCE_ORIGIN, '/');
$CACHEABLE_EXTENSIONS = @unserialize(CACHEABLE_EXTENSIONS);
if (! is_array($CACHEABLE_EXTENSIONS)) {
    $CACHEABLE_EXTENSIONS = [];
}

$request = Request::createFromGlobals();

if ($request->getMethod() === 'OPTIONS') {
    $response = new Response('', 204, [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Credentials' => 'true',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'DNT, X-User-Token, Keep-Alive, User-Agent, X-Requested-With, If-Modified-Since, Cache-Control, Content-Type',
        'Access-Control-Max-Age' => CACHE_EXPIRY,
        'Content-Type' => 'text/plain charset=UTF-8',
        'Content-Length' => 0,
    ]);
    $response->send();
    exit();
}

$server = $request->getScheme().'://'.$request->getHttpHost();
$request_file = $request->getPathInfo();

if (strpos($request_file, '/..') !== false) {
    error($request_file, -1);
}

$extension = trim(strtolower(pathinfo($request_file, PATHINFO_EXTENSION)));
if (! ($extension && in_array($extension, $CACHEABLE_EXTENSIONS, true))) {
    return error($request_file, 4);
}

// ----- 2. cache location + QS handling --------------------------------------
$qs_raw = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
$purge = preg_match('/(?:^|&)purge=(?:1|true)(?:&|$)/i', $qs_raw) === 1;
$purge_all = preg_match('/(?:^|&)purge_all=(?:1|true)(?:&|$)/i', $qs_raw) === 1;
$canonical_qs = canonicalQueryString($qs_raw);

$loc = resolveCacheLocation(PUBLIC_DIR, $request_file, $canonical_qs);

if ($purge || $purge_all) {
    purgeCacheFor($loc['file'], $purge_all);
    logLine('info', 'purge', ['file' => $loc['file'], 'aggressive' => $purge_all]);
}

// ----- 3. negative cache short-circuits -------------------------------------
if (is_file($loc['marker_404']) && (time() - (int) @filemtime($loc['marker_404'])) < NEGATIVE_CACHE_TTL) {
    return error($request_file, 3);
}
if (is_file($loc['marker_net']) && (time() - (int) @filemtime($loc['marker_net'])) < NETWORK_ERROR_TTL) {
    return error($request_file, 5);
}

// ----- 4. disk hit path -----------------------------------------------------
$acceptsWebp = clientAcceptsWebp();
$isRaster = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp'], true);

// Raster request, client understands webp, and we have the webp sibling:
// serve webp inline. Works both for the zero-QS case (where Apache would
// have done the 301 redirect anyway) and for the QS-keyed case (where it
// would not).
if ($isRaster && $acceptsWebp && is_file($loc['webp_file'])) {
    serveFromDisk($loc['webp_file'], 'webp');
    exit();
}

// QS-keyed hit: Apache always routes QS requests to index.php, so cache
// hits for QS-keyed files must be served here explicitly.
if ($loc['is_qs_cache'] && is_file($loc['file'])) {
    // Lazy webp generation: if we cached a raster earlier (e.g. for a client
    // that did not advertise webp), and now a webp-capable client arrives,
    // produce the sibling now so the next request is O(disk).
    if ($isRaster && IMAGE_CONVERSION_MODE !== 'none' && ! is_file($loc['webp_file'])) {
        convertImageToWebp($loc['file']);
        if ($acceptsWebp && is_file($loc['webp_file'])) {
            serveFromDisk($loc['webp_file'], 'webp');
            exit();
        }
    }
    serveFromDisk($loc['file'], $extension);
    exit();
}

// ----- 5. fetch from origin -------------------------------------------------
$origin_url = $SOURCE_ORIGIN.$request_file;
if ($canonical_qs !== '') {
    $origin_url .= '?'.$canonical_qs;
}

$result = getRemoteFile($server, $origin_url);

if (! $result['ok']) {
    // ensure the cache dir exists before we drop the marker, otherwise touch()
    // fails silently and negative caching never arms for brand-new paths
    if (! is_dir($loc['dir'])) {
        @mkdir($loc['dir'], 0755, true);
    }

    if ($result['reason'] === 'not_found') {
        @touch($loc['marker_404']);
        return error($request_file, 3);
    }

    // network-class failure (timeout, DNS, TLS, 5xx, connect refused, ...):
    // arm the short-TTL .netfail marker so we stop hammering the origin for
    // NETWORK_ERROR_TTL seconds
    @touch($loc['marker_net']);
    return error($request_file, 5);
}

// ----- 6. fresh bytes from origin: transform + persist + serve --------------
$stream = $result['body'];
$headers = $result['headers'];

// rewrite absolute paths in CSS (handles url(/, url('/, url("/)
if ($extension === 'css') {
    $stream = str_replace(
        ['url(/', "url('/", 'url("/'],
        ['url('.rtrim($server, '/').'/', "url('".rtrim($server, '/').'/', 'url("'.rtrim($server, '/').'/'],
        $stream
    );
}

if (! is_dir($loc['dir'])) {
    @mkdir($loc['dir'], 0755, true);
    if (! is_dir($loc['dir'])) {
        error($request_file, 1);
    }
}

if (! atomicWrite($loc['file'], $stream)) {
    error($request_file, 2);
}

// eager webp generation: the next hit lands on disk and Apache (or the disk
// hit path above) can serve webp without a round-trip
$servedWebp = false;
if ($isRaster && IMAGE_CONVERSION_MODE !== 'none') {
    $conversion = convertImageToWebp($loc['file']);
    if (! $conversion['converted'] && $conversion['error'] !== null) {
        internalError('Image conversion failed: '.$conversion['error']);
    }
    if ($conversion['converted'] && is_file($conversion['path']) && $acceptsWebp) {
        // swap body + headers so this very response carries the webp
        $stream = file_get_contents($conversion['path']);
        $headers['content-type'] = 'image/webp';
        $headers['content-length'] = strlen($stream);
        $servedWebp = true;
    }
}

// a stale marker may have survived if the resource just came back to life
if (is_file($loc['marker_404'])) {
    @unlink($loc['marker_404']);
}
if (is_file($loc['marker_net'])) {
    @unlink($loc['marker_net']);
}

// ----- 7. emit the response -------------------------------------------------
$response = new Response();
$response->setStatusCode($result['response_code']);
$response->headers->add($headers);
$response->setPublic();
$response->setMaxAge(CACHE_EXPIRY);
$response->headers->addCacheControlDirective('must-revalidate', true);
$response->headers->set('x-cache-status', 'miss');
if ($servedWebp) {
    $response->headers->set('vary', 'Accept');
}

$response->setContent($stream);
$response->send();

// honor an origin-expressed "already expired" signal by dropping the cache
if (array_key_exists('expires', $headers) && strtotime($headers['expires']) < time()) {
    @unlink($loc['file']);
}

exit();


/**
 * Streams a file from disk as an HTTP response with fresh headers.
 *
 * Used on the cache-hit path: rebuild all headers from scratch (so we never
 * leak stale origin headers from a previous fetch) and apply the proxy's
 * default Cache-Control. Emits Vary: Accept whenever content negotiation
 * between webp and the source format is in play.
 *
 * @param string $path absolute path to the cache file
 * @param string $servedExtension extension that describes the body we are
 *                                about to send (e.g. 'webp' when we swapped
 *                                in the webp sibling)
 *
 * @return void
 */
function serveFromDisk(string $path, string $servedExtension)
{
    $size = @filesize($path);
    $mime = mimeFromExtension($servedExtension);

    $response = new Response();
    $response->setStatusCode(200);
    $response->headers->set('content-type', $mime);
    if ($size !== false) {
        $response->headers->set('content-length', (string) $size);
    }
    $response->setPublic();
    $response->setMaxAge(CACHE_EXPIRY);
    $response->headers->addCacheControlDirective('must-revalidate', true);
    $response->headers->set('x-cache-status', 'hit');
    $response->headers->set('vary', 'Accept');

    $body = @file_get_contents($path);
    if ($body === false) {
        internalError('Cannot read cached file from disk');
    }
    $response->setContent($body);
    $response->send();
}
