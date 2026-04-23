<?php

/**
 * ------------------------------------------------------------------------
 * PHP Transparent Proxy CDN - helpers
 *
 * All functions target PHP 7.2.5+ (no match, no arrow fn, no str_contains,
 * no union types). Nullable types and void returns are PHP 7.1+.
 * ------------------------------------------------------------------------
 */

/**
 * Creates a static file named ".cached.env.php" on the root project dir,
 * to avoid loading the .env file on every request.
 *
 * @param string $rootDir
 *
 * @return void
 */
function loadConfig(string $rootDir)
{
    $cacheConfigFile = "{$rootDir}.cached.env.php";
    if (file_exists($cacheConfigFile)) {
        require_once $cacheConfigFile;

        return;
    }

    // fail if we have no .env file
    if (! file_exists("{$rootDir}.env")) {
        error(null, -3);
    }

    // load .env file
    try {
        $dotenv = \Dotenv\Dotenv::createUnsafeImmutable($rootDir);
        $dotenv->load();
        $dotenv->required([
            'SOURCE_ORIGIN',
            'CHARSET',
            'TIMEZONE',
            'LOCALE',
            'CACHEABLE_EXTENSIONS',
        ]);
    } catch (\Exception $e) {
        error(null, -4);
    }

    $boolDebug = (array_key_exists('DEBUG', $_ENV) && in_array($_ENV['DEBUG'], [true, 'true', 1, '1'], true)) ? true : false;

    $defineKeys = [
        'DEBUG' => $boolDebug,
        'SOURCE_ORIGIN' => (array_key_exists('SOURCE_ORIGIN', $_ENV) && $_ENV['SOURCE_ORIGIN'] !== 'null') ? rtrim($_ENV['SOURCE_ORIGIN'], '/') : null,
        'SOURCE_ORIGIN_SERVER' => (array_key_exists('SOURCE_ORIGIN_SERVER', $_ENV) && $_ENV['SOURCE_ORIGIN_SERVER'] !== 'null') ? $_ENV['SOURCE_ORIGIN_SERVER'] : null,
        'CHARSET' => (array_key_exists('CHARSET', $_ENV) && $_ENV['CHARSET'] !== 'null') ? $_ENV['CHARSET'] : 'UTF-8',
        'TIMEZONE' => (array_key_exists('TIMEZONE', $_ENV) && $_ENV['TIMEZONE'] !== 'null') ? $_ENV['TIMEZONE'] : date_default_timezone_get(),
        'LOCALE' => (array_key_exists('LOCALE', $_ENV) && $_ENV['LOCALE'] !== 'null') ? $_ENV['LOCALE'] : 'en_US',
        'CACHEABLE_EXTENSIONS' => (array_key_exists('CACHEABLE_EXTENSIONS', $_ENV) && $_ENV['CACHEABLE_EXTENSIONS'] !== 'null') ? $_ENV['CACHEABLE_EXTENSIONS'] : null,
        'CACHE_EXPIRY' => (array_key_exists('CACHE_EXPIRY', $_ENV) && $_ENV['CACHE_EXPIRY'] !== 'null') ? (int) $_ENV['CACHE_EXPIRY'] : 86400,
        'NEGATIVE_CACHE_TTL' => (array_key_exists('NEGATIVE_CACHE_TTL', $_ENV) && $_ENV['NEGATIVE_CACHE_TTL'] !== 'null') ? (int) $_ENV['NEGATIVE_CACHE_TTL'] : 300,
        'NETWORK_ERROR_TTL' => (array_key_exists('NETWORK_ERROR_TTL', $_ENV) && $_ENV['NETWORK_ERROR_TTL'] !== 'null') ? (int) $_ENV['NETWORK_ERROR_TTL'] : 30,
        'UPSTREAM_TIMEOUT' => (array_key_exists('UPSTREAM_TIMEOUT', $_ENV) && $_ENV['UPSTREAM_TIMEOUT'] !== 'null') ? (int) $_ENV['UPSTREAM_TIMEOUT'] : 30,
        'UPSTREAM_CONNECT_TIMEOUT' => (array_key_exists('UPSTREAM_CONNECT_TIMEOUT', $_ENV) && $_ENV['UPSTREAM_CONNECT_TIMEOUT'] !== 'null') ? (int) $_ENV['UPSTREAM_CONNECT_TIMEOUT'] : 5,
        'UPSTREAM_VERIFY_TLS' => (array_key_exists('UPSTREAM_VERIFY_TLS', $_ENV) && in_array($_ENV['UPSTREAM_VERIFY_TLS'], [true, 'true', 1, '1'], true)) ? true : false,
        'UPSTREAM_FOLLOW_REDIRECTS' => (array_key_exists('UPSTREAM_FOLLOW_REDIRECTS', $_ENV) && in_array($_ENV['UPSTREAM_FOLLOW_REDIRECTS'], [true, 'true', 1, '1'], true)) ? true : false,
        'IMAGE_CONVERSION_MODE' => (array_key_exists('IMAGE_CONVERSION_MODE', $_ENV) && $_ENV['IMAGE_CONVERSION_MODE'] !== 'null') ? $_ENV['IMAGE_CONVERSION_MODE'] : 'none',
        'WEBP_API_SERVER' => (array_key_exists('WEBP_API_SERVER', $_ENV) && $_ENV['WEBP_API_SERVER'] !== 'null') ? $_ENV['WEBP_API_SERVER'] : null,
        'WEBP_API_KEY' => (array_key_exists('WEBP_API_KEY', $_ENV) && $_ENV['WEBP_API_KEY'] !== 'null') ? $_ENV['WEBP_API_KEY'] : null,
    ];

    // CACHEABLE_EXTENSIONS is stored as a serialized array so that public/index.php
    // can unserialize() it back into an array of lowercased, trimmed, non-empty tokens.
    if (is_string($defineKeys['CACHEABLE_EXTENSIONS'])) {
        $tokens = array_filter(array_map(function ($v) {
            $v = trim(strtolower((string) $v));
            return $v !== '' ? $v : null;
        }, explode(',', $defineKeys['CACHEABLE_EXTENSIONS'])));
        $defineKeys['CACHEABLE_EXTENSIONS'] = serialize(array_values($tokens));
    }

    // Build the cached config file via var_export() on every value, so that
    // arbitrary characters in the .env (single/double quotes, backslashes,
    // newlines, dollars) cannot break out of the literal and inject PHP code.
    $configLines = [];
    foreach ($defineKeys as $key => $value) {
        $configLines[] = 'define('.var_export($key, true).', '.var_export($value, true).');';
    }

    $configContent = '<?php'.PHP_EOL
        .'// cached config file on '.date('Y-m-d H:i:s').PHP_EOL
        .implode(PHP_EOL, $configLines).PHP_EOL;

    // Atomic write: tmp + rename, so a concurrent worker either sees the
    // previous file (if any) or the fully-written new one, never a partial file.
    if (! atomicWrite($cacheConfigFile, $configContent)) {
        error(null, -2);
    }

    // load the cached config file
    require_once $cacheConfigFile;
}

/**
 * Sends a 404 error using Symfony's Response, with optional error type and ray ID.
 *
 * @param string|null $request_file
 * @param int $error_type
 * @return void
 */
function error(?string $request_file = null, int $error_type = 0): void
{
    $messages = [
        -4 => 'ENV_FILE_LOAD_ERR',
        -3 => 'ENV_FILE_NOT_FOUND',
        -2 => 'CACHED_CONFIG_FILE_NOT_CREATED',
        -1 => 'Cannot serve this request',
        0  => 'Generic error',
        1  => 'Cannot create cache directory',
        2  => 'Cannot create cache file',
        3  => 'Cannot read remote origin file',
        4  => 'Request cannot be processed',
        5  => 'Upstream temporarily unavailable',
    ];
    $message = isset($messages[$error_type]) ? $messages[$error_type] : 'Unknown error';

    $hash = md5($request_file ?? (string) microtime(true));
    $body = "The request could not be processed.\nError code: {$message}.\nRay ID: {$hash}";

    // 503 on upstream transient unavailability, 404 otherwise
    $status = ($error_type === 5) ? 503 : 404;
    $headers = ['Content-Type' => 'text/plain; charset=UTF-8'];
    if ($status === 503) {
        // suggest a short retry window so well-behaved clients back off
        $headers['Retry-After'] = defined('NETWORK_ERROR_TTL') ? (string) NETWORK_ERROR_TTL : '30';
    }

    (new \Symfony\Component\HttpFoundation\Response($body, $status, $headers))->send();
    exit();
}

/**
 * Sends a 500 error using Symfony's Response, with optional message.
 *
 * @param string $reason
 * @return void
 */
function internalError(string $reason): void
{
    $body = "The request could not be processed.\nInternal server error: {$reason}.";
    (new \Symfony\Component\HttpFoundation\Response($body, 500, ['Content-Type' => 'text/plain; charset=UTF-8']))->send();
    exit();
}

/**
 * Appends a single-line entry to ROOT_DIR/logs/proxy.log. Best-effort: never
 * throws, never breaks a request. Honors DEBUG for info-level entries.
 *
 * @param string $level 'error' | 'warn' | 'info'
 * @param string $message
 * @param array $context key=value pairs that get serialized inline
 *
 * @return void
 */
function logLine(string $level, string $message, array $context = [])
{
    // info-level only when DEBUG is on
    if ($level === 'info' && (! defined('DEBUG') || ! DEBUG)) {
        return;
    }
    if (! defined('ROOT_DIR')) {
        return;
    }

    $logDir = ROOT_DIR.'logs';
    if (! is_dir($logDir) && ! @mkdir($logDir, 0755, true) && ! is_dir($logDir)) {
        return;
    }

    $parts = [];
    foreach ($context as $k => $v) {
        if (is_bool($v)) {
            $v = $v ? 'true' : 'false';
        } elseif ($v === null) {
            $v = 'null';
        } elseif (is_array($v) || is_object($v)) {
            $v = json_encode($v, JSON_UNESCAPED_SLASHES);
        }
        $parts[] = $k.'='.$v;
    }

    $line = '['.date('c').'] '
        .strtoupper($level).' '
        .$message
        .(empty($parts) ? '' : ' '.implode(' ', $parts))
        .PHP_EOL;

    @file_put_contents($logDir.DIRECTORY_SEPARATOR.'proxy.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * Atomically writes $contents to $destination by writing to a sibling tmp file
 * first and then rename()-ing it in place. rename() is atomic on POSIX
 * filesystems as long as source and destination live on the same filesystem,
 * which is the case here (same directory).
 *
 * Tmp files are named ".mftpcdn.<random>" so that the public/.htaccess
 * FilesMatch rule on "^\.mftpcdn\." makes them inaccessible via HTTP during
 * the microsecond window between tempnam() and rename().
 *
 * @param string $destination
 * @param string $contents
 *
 * @return bool
 */
function atomicWrite(string $destination, string $contents): bool
{
    $dir = dirname($destination);
    if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
        return false;
    }

    $tmp = @tempnam($dir, '.mftpcdn.');
    if ($tmp === false) {
        return false;
    }

    $written = @file_put_contents($tmp, $contents, LOCK_EX);
    if ($written === false || $written !== strlen($contents)) {
        @unlink($tmp);
        return false;
    }

    // match the default umask-ed perms for regular web content
    @chmod($tmp, 0644);

    if (! @rename($tmp, $destination)) {
        @unlink($tmp);
        return false;
    }

    return is_file($destination);
}

/**
 * Returns a canonical form of a query string, with the proxy's control
 * parameters (purge, purge_all) stripped and the remaining parameters
 * sorted alphabetically. Two semantically-equivalent query strings
 * always produce the same canonical form, which keeps the cache key stable:
 *
 *   ?b=2&a=1 -> a=1&b=2
 *   ?purge=1 -> "" (control-only)
 *   ?v=1&purge=1 -> v=1
 *   (empty input) -> ""
 *
 * @param string $qs raw query string (as in $_SERVER['QUERY_STRING'])
 *
 * @return string canonical query string (empty if there is nothing to key on)
 */
function canonicalQueryString(string $qs): string
{
    if ($qs === '') {
        return '';
    }
    $params = [];
    parse_str($qs, $params);
    unset($params['purge'], $params['purge_all']);
    if (empty($params)) {
        return '';
    }
    ksort($params, SORT_STRING);
    return http_build_query($params);
}

/**
 * Resolves where the cache entry for (request_file, canonical_qs) lives on disk.
 *
 * Rationale: when the request carries a meaningful query string (e.g. the
 * origin uses ?v=123 for cache-busting), two different query strings must
 * map to two different cached files, otherwise the proxy would pin the very
 * first version it saw. We key on sha256(canonical_qs), bucketing under
 * public/_qs/<hash>/ so that the layout stays legible and the hash-space is
 * uniformly distributed. Requests without a meaningful QS keep landing in
 * public/<path> so that Apache can serve them directly on cache hits
 * without invoking PHP at all.
 *
 * @param string $publicDir absolute path to the DocumentRoot (PUBLIC_DIR)
 * @param string $request_file the path component of the request ("/foo/bar.jpg")
 * @param string $canonical_qs canonical query string (output of canonicalQueryString)
 *
 * @return array {
 *   'file'        => string,  absolute path to the cache file,
 *   'dir'         => string,  absolute path to the cache dir (file's parent),
 *   'webp_file'   => string,  absolute path to the sibling .webp file,
 *   'marker_404'  => string,  absolute path to the 404 negative-cache marker,
 *   'marker_net'  => string,  absolute path to the upstream-failure marker,
 *   'is_qs_cache' => bool,    true if the file lives under _qs/<hash>/,
 * }
 */
function resolveCacheLocation(string $publicDir, string $request_file, string $canonical_qs): array
{
    $ds = DIRECTORY_SEPARATOR;
    $rel = ltrim($request_file, '/');

    if ($canonical_qs === '') {
        $base = rtrim($publicDir, $ds).$ds;
    } else {
        $hash = substr(hash('sha256', $canonical_qs), 0, 16);
        $base = rtrim($publicDir, $ds).$ds.'_qs'.$ds.$hash.$ds;
    }

    $cacheFile = $base.$rel;
    $cacheDir = rtrim(dirname($cacheFile), $ds).$ds;

    // sibling .webp: replace the trailing extension (if any) with .webp
    $info = pathinfo($cacheFile);
    $dirname = isset($info['dirname']) ? $info['dirname'] : dirname($cacheFile);
    $filename = isset($info['filename']) ? $info['filename'] : basename($cacheFile);
    $webpFile = $dirname.$ds.$filename.'.webp';

    return [
        'file' => $cacheFile,
        'dir' => $cacheDir,
        'webp_file' => $webpFile,
        'marker_404' => $cacheFile.'.404',
        'marker_net' => $cacheFile.'.netfail',
        'is_qs_cache' => ($canonical_qs !== ''),
    ];
}

/**
 * Purges the cache entry at $cacheFile, plus optional siblings.
 *
 * Non-aggressive: removes $cacheFile and both its markers (.404, .netfail).
 * Aggressive: also removes every sibling representation that shares the same
 * basename (the .webp converted copy for a source raster image, or the
 * raster siblings for a .webp request), including each sibling's markers.
 *
 * @param string $cacheFile absolute path
 * @param bool $aggressive
 *
 * @return void
 */
function purgeCacheFor(string $cacheFile, bool $aggressive = false)
{
    $targets = [
        $cacheFile,
        $cacheFile.'.404',
        $cacheFile.'.netfail',
    ];

    if ($aggressive) {
        $info = pathinfo($cacheFile);
        $dir = isset($info['dirname']) ? $info['dirname'] : dirname($cacheFile);
        $base = isset($info['filename']) ? $info['filename'] : '';

        if ($base !== '') {
            foreach (['webp', 'jpg', 'jpeg', 'png', 'gif', 'bmp'] as $ext) {
                $sibling = $dir.DIRECTORY_SEPARATOR.$base.'.'.$ext;
                $targets[] = $sibling;
                $targets[] = $sibling.'.404';
                $targets[] = $sibling.'.netfail';
            }
        }
    }

    foreach (array_unique($targets) as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * Returns true when the current request's Accept header signals that the
 * client supports image/webp. Conservative: only an explicit "image/webp"
 * token counts (a bare wildcard is intentionally NOT treated as webp
 * support, because older UAs send that and then fail to decode webp).
 *
 * @return bool
 */
function clientAcceptsWebp(): bool
{
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
    return $accept !== '' && stripos($accept, 'image/webp') !== false;
}

/**
 * Maps a file extension to its canonical MIME type. Used when serving a
 * cache hit straight from disk (we cannot trust the cached headers from the
 * origin for QS-keyed entries, since we do not persist them).
 *
 * @param string $ext extension without leading dot, case-insensitive
 *
 * @return string MIME type (falls back to application/octet-stream)
 */
function mimeFromExtension(string $ext): string
{
    $map = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'json'  => 'application/json',
        'svg'   => 'image/svg+xml',
        'webp'  => 'image/webp',
        'ico'   => 'image/x-icon',
        'bmp'   => 'image/bmp',
        'gif'   => 'image/gif',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'png'   => 'image/png',
        'tif'   => 'image/tiff',
        'tiff'  => 'image/tiff',
        'ttf'   => 'application/x-font-ttf',
        'ttc'   => 'application/x-font-ttf',
        'otf'   => 'application/x-font-otf',
        'eot'   => 'application/vnd.ms-fontobject',
        'woff'  => 'application/font-woff',
        'woff2' => 'application/font-woff2',
        'pdf'   => 'application/pdf',
    ];
    $ext = strtolower($ext);
    return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
}

/**
 * Fetches $origin_url with cURL. Returns a result envelope that distinguishes
 * a true 404 (origin says "not here") from a transient network failure
 * (timeout, DNS, TLS, 5xx, ...). The caller uses this to decide whether to
 * arm the 404 marker (long TTL) or the network-failure marker (short TTL).
 *
 * Envelope on success:
 *   ['ok' => true, 'response_code' => 200, 'headers' => [...], 'body' => '...']
 *
 * Envelope on failure:
 *   ['ok' => false, 'reason' => 'not_found'|'server_error'|'timeout'|'dns'|'tls'|'connect'|'other',
 *    'response_code' => int, 'curl_errno' => int, 'curl_error' => string]
 *
 * @param string $cdn_server
 * @param string $origin_url
 *
 * @return array
 */
function getRemoteFile(string $cdn_server, string $origin_url): array
{
    $opts = [];
    $http_headers = [];
    $http_headers[] = 'Expect:';
    $http_headers[] = 'Referer: '.$cdn_server;
    $http_headers[] = 'Pragma: no-cache';
    $http_headers[] = 'Cache-Control: no-cache';
    $http_headers[] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:81.0) Gecko/20100101 Firefox/81.0';

    $connectTimeout = defined('UPSTREAM_CONNECT_TIMEOUT') ? (int) UPSTREAM_CONNECT_TIMEOUT : 5;
    $totalTimeout   = defined('UPSTREAM_TIMEOUT') ? (int) UPSTREAM_TIMEOUT : 30;
    $verifyTls      = defined('UPSTREAM_VERIFY_TLS') ? (bool) UPSTREAM_VERIFY_TLS : false;
    $followRedirect = defined('UPSTREAM_FOLLOW_REDIRECTS') ? (bool) UPSTREAM_FOLLOW_REDIRECTS : false;

    $opts[CURLOPT_URL] = $origin_url;
    $opts[CURLOPT_HTTPHEADER] = $http_headers;
    $opts[CURLOPT_CONNECTTIMEOUT] = $connectTimeout;
    $opts[CURLOPT_TIMEOUT] = $totalTimeout;
    $opts[CURLOPT_HEADER] = true;
    $opts[CURLOPT_VERBOSE] = false;
    $opts[CURLOPT_SSL_VERIFYPEER] = $verifyTls;
    $opts[CURLOPT_SSL_VERIFYHOST] = $verifyTls ? 2 : 0;
    $opts[CURLOPT_RETURNTRANSFER] = true;
    $opts[CURLOPT_FOLLOWLOCATION] = $followRedirect;
    $opts[CURLOPT_MAXREDIRS] = $followRedirect ? 10 : 0;
    $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;

    // worker-pool protection: if the transfer is stuck under 1 KB/s for more
    // than 10 seconds, abort. Without this, a slow/malicious origin can pin
    // every PHP-FPM worker until CURLOPT_TIMEOUT fires.
    $opts[CURLOPT_LOW_SPEED_LIMIT] = 1024;
    $opts[CURLOPT_LOW_SPEED_TIME]  = 10;

    // Force connection to SOURCE_ORIGIN_SERVER
    if (defined('SOURCE_ORIGIN_SERVER') && SOURCE_ORIGIN_SERVER) {
        $host = parse_url($origin_url, PHP_URL_HOST);
        $port = parse_url($origin_url, PHP_URL_PORT) ?: 443;

        $opts[CURLOPT_CONNECT_TO] = [
            "{$host}:{$port}:".SOURCE_ORIGIN_SERVER,
        ];
    }

    $ch = curl_init();
    curl_setopt_array($ch, $opts);
    $response = @curl_exec($ch);
    $header_size = (int) @curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $response_code = (int) @curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    $errstr = curl_error($ch);
    curl_close($ch);

    if ($errno !== 0) {
        $reason = curlErrnoReason($errno);
        logLine('error', 'upstream fetch failed', [
            'url' => $origin_url,
            'reason' => $reason,
            'curl_errno' => $errno,
            'curl_error' => $errstr,
            'response_code' => $response_code,
        ]);
        return [
            'ok' => false,
            'reason' => $reason,
            'response_code' => $response_code,
            'curl_errno' => $errno,
            'curl_error' => $errstr,
        ];
    }

    if ($response_code === 404) {
        logLine('info', 'upstream 404', ['url' => $origin_url]);
        return [
            'ok' => false,
            'reason' => 'not_found',
            'response_code' => 404,
            'curl_errno' => 0,
            'curl_error' => '',
        ];
    }

    if ($response_code !== 200 || $response === false) {
        $reason = ($response_code >= 500) ? 'server_error' : 'other';
        logLine('error', 'upstream non-200', [
            'url' => $origin_url,
            'response_code' => $response_code,
        ]);
        return [
            'ok' => false,
            'reason' => $reason,
            'response_code' => $response_code,
            'curl_errno' => 0,
            'curl_error' => '',
        ];
    }

    $header = substr($response, 0, $header_size);
    $headerLines = array_values(array_filter(
        explode(chr(10), trim(str_replace([chr(13).chr(10), chr(13)], chr(10), $header))),
        function ($h) {
            return strlen($h) && strpos($h, ':') !== false;
        }
    ));
    $body = substr($response, $header_size);

    // Whitelist the headers we propagate from the origin to the client.
    // Using a whitelist (vs a blacklist) prevents leaks like Set-Cookie,
    // Content-Security-Policy, Link: rel=preload from the origin surface.
    $headerKeepKeys = [
        'content-type',
        'content-length',
        'etag',
        'last-modified',
        'vary',
        'accept-ranges',
        'content-disposition',
    ];

    $headers = array_filter(mapassoc(function ($key, $value) use ($headerKeepKeys) {
        list($name, $value) = explode(':', $value, 2);
        $key = strtolower(trim($name));
        $val = trim($value);
        if (in_array($key, $headerKeepKeys, true)) {
            return [$key, $val];
        }
        return null;
    }, $headerLines));

    return [
        'ok' => true,
        'response_code' => 200,
        'headers' => $headers,
        'body' => $body,
    ];
}

/**
 * Maps a cURL errno to one of our coarse-grained "reason" tags so the caller
 * can decide which negative-cache marker to arm (or not) without reading the
 * numeric errno directly.
 *
 * @param int $errno
 *
 * @return string
 */
function curlErrnoReason(int $errno): string
{
    // see https://curl.se/libcurl/c/libcurl-errors.html
    //
    // Some CURLE_* constants are build-dependent (not all PHP/libcurl
    // combinations expose them), so we guard each lookup with defined()
    // and fall back to 'other' rather than crashing at php -l time.
    static $map = null;
    if ($map === null) {
        $map = [];
        $candidates = [
            'CURLE_COULDNT_RESOLVE_HOST'     => 'dns',
            'CURLE_COULDNT_CONNECT'          => 'connect',
            'CURLE_OPERATION_TIMEOUTED'      => 'timeout',
            'CURLE_SSL_CONNECT_ERROR'        => 'tls',
            'CURLE_PEER_FAILED_VERIFICATION' => 'tls',
            'CURLE_SSL_CERTPROBLEM'          => 'tls',
            'CURLE_SSL_CIPHER'               => 'tls',
            'CURLE_SSL_CACERT'               => 'tls',
            'CURLE_GOT_NOTHING'              => 'connect',
            'CURLE_SEND_ERROR'               => 'network',
            'CURLE_RECV_ERROR'               => 'network',
            'CURLE_PARTIAL_FILE'             => 'network',
        ];
        foreach ($candidates as $name => $tag) {
            if (defined($name)) {
                $map[constant($name)] = $tag;
            }
        }
    }
    return isset($map[$errno]) ? $map[$errno] : 'other';
}

/**
 * Associative array map.
 * Similar to array_map, but works on associative arrays
 * Example: mapassoc(function($key, $value) use($something) { return [ $key, $value ]; });.
 *
 * @param callable $func
 * @param array $arr
 *
 * @return array|null
 */
function mapassoc(callable $func, ?array $arr): ?array
{
    if (is_array($arr)) {
        return array_column(array_map($func, array_keys($arr), $arr), 1, 0);
    }

    return null;
}

/**
 * Entry point for image conversion, respecting IMAGE_CONVERSION_MODE.
 *
 * @param string $sourceFile Full path to original image
 * @return array [
 *     'converted' => bool,
 *     'path'      => string|null,
 *     'error'     => string|null,
 * ]
 */
function convertImageToWebp(string $sourceFile): array
{
    $mode = defined('IMAGE_CONVERSION_MODE') ? IMAGE_CONVERSION_MODE : 'none';

    if (!is_file($sourceFile)) {
        return [
            'converted' => false,
            'path' => null,
            'error' => 'Source file does not exist',
        ];
    }

    switch ($mode) {
        case 'none':
            return ['converted' => false, 'path' => null, 'error' => null];
        case 'native':
            return convertImageToWebpNative($sourceFile);
        case 'api':
            return convertImageToWebpApi($sourceFile);
        default:
            return ['converted' => false, 'path' => null, 'error' => "Unsupported mode: {$mode}"];
    }
}

/**
 * Converts an image to webp, using the WEBP_API.
 *
 * @see https://github.com/mauriziofonte/php-image-to-webp-conversion-api
 *
 * @param string $api_url
 * @param string $api_key
 * @param array $images
 *
 * @return array
 */
function convertImageToWebpViaApi(string $api_url, string $api_key, array $images) : array
{
    // Create an array of files to post via cUrl and the file descriptors
    $postData = $descriptors = [];
    foreach ($images as $index => $file_data) {
        if (is_file($file_data['path'])) {
            $realpath = realpath($file_data['path']);
            $mime = mime_content_type($file_data['path']);
            $basename = basename($file_data['path']);

            $postData['images['.$index.']'] = curl_file_create(
                $realpath,
                $mime,
                $basename
            );

            unset($file_data['path']);
            $file_data['filename'] = $basename;
            $descriptors[] = $file_data;
        }
    }

    // append the descriptors json object to POST data
    $postData['descriptors'] = json_encode($descriptors);

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        '-x-api-key: '.$api_key,
        'User-Agent: PHP cUrl connector for MWEBP',
    ]);
    $ret = curl_exec($ch);
    $status_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if (
        ($decoded = @json_decode($ret, true)) !== null &&
        is_array($decoded) &&
        $status_code === 200 &&
        array_key_exists('response', $decoded)
    ) {
        return [
            'status' => true,
            'response' => $decoded['response'],
        ];
    } else {
        if (is_array($decoded) && array_key_exists('message', $decoded)) {
            $error_message = $decoded['message'];
        } else {
            $error_message = 'Unknown error with HTTP_RESPONSE_CODE="'.$status_code.'"';
        }

        return [
            'status' => false,
            'error' => $error_message,
        ];
    }
}

/**
 * Converts an image using local `cwebp` binary.
 *
 * The binary writes to a scratch path first, then we atomically rename it
 * over the destination. Concurrent workers converting the same source image
 * therefore never observe a truncated .webp on the filesystem.
 *
 * @param string $sourceFile
 * @return array same structure as convertImageToWebp()
 */
function convertImageToWebpNative(string $sourceFile): array
{
    if (! binaryExists('cwebp')) {
        logLine('error', 'cwebp binary missing', ['source' => $sourceFile]);
        return [
            'converted' => false,
            'path' => null,
            'error' => 'cwebp binary not available in PATH',
        ];
    }

    $outputFile = pathinfo($sourceFile, PATHINFO_DIRNAME).DS.pathinfo($sourceFile, PATHINFO_FILENAME).'.webp';
    $scratch = $outputFile.'.tmp.'.bin2hex(random_bytes(4));

    $cmd = 'cwebp -quiet -m 4 '.escapeshellarg($sourceFile).' -o '.escapeshellarg($scratch);
    wrapExec($cmd);

    if (! is_file($scratch)) {
        logLine('error', 'cwebp produced no output', ['source' => $sourceFile, 'cmd' => $cmd]);
        return [
            'converted' => false,
            'path' => null,
            'error' => 'Conversion failed: output file not created',
        ];
    }

    if (! @rename($scratch, $outputFile)) {
        @unlink($scratch);
        logLine('error', 'cwebp rename failed', ['source' => $sourceFile, 'target' => $outputFile]);
        return [
            'converted' => false,
            'path' => null,
            'error' => 'Conversion failed: atomic rename failed',
        ];
    }

    @chmod($outputFile, 0644);
    return [
        'converted' => true,
        'path' => $outputFile,
        'error' => null,
    ];
}


/**
 * Converts an image using an external API defined in ENV. Writes the
 * resulting webp through atomicWrite() to avoid torn reads.
 *
 * @param string $sourceFile
 * @return array same structure as convertImageToWebp()
 */
function convertImageToWebpApi(string $sourceFile): array
{
    if (! defined('WEBP_API_SERVER') || ! defined('WEBP_API_KEY')) {
        return [
            'converted' => false,
            'path' => null,
            'error' => 'WEBP_API_SERVER or WEBP_API_KEY not defined',
        ];
    }

    $images = [['path' => $sourceFile, 'file_id' => basename($sourceFile)]];
    $apiResponse = convertImageToWebpViaApi(WEBP_API_SERVER, WEBP_API_KEY, $images);

    if (! $apiResponse['status']) {
        logLine('error', 'webp api failed', ['source' => $sourceFile, 'err' => $apiResponse['error'] ?? 'unknown']);
        return [
            'converted' => false,
            'path' => null,
            'error' => isset($apiResponse['error']) ? $apiResponse['error'] : 'Unknown API error',
        ];
    }

    $data = isset($apiResponse['response'][0]) ? $apiResponse['response'][0] : null;

    if (! is_array($data) || ! isset($data['webp_image_base64'])) {
        return [
            'converted' => false,
            'path' => null,
            'error' => 'Invalid response from API',
        ];
    }

    $decoded = base64_decode($data['webp_image_base64'], true);
    if ($decoded === false) {
        return [
            'converted' => false,
            'path' => null,
            'error' => 'Base64 decode failed',
        ];
    }

    $outputFile = pathinfo($sourceFile, PATHINFO_DIRNAME).DS.pathinfo($sourceFile, PATHINFO_FILENAME).'.webp';
    if (! atomicWrite($outputFile, $decoded)) {
        return [
            'converted' => false,
            'path' => null,
            'error' => 'Atomic write failed',
        ];
    }

    return [
        'converted' => true,
        'path' => $outputFile,
        'error' => null,
    ];
}

/**
 * Executes a shell command and returns the string output.
 * Uses exec(), shell_exec(), passthru(), or system() in order of preference.
 *
 * @param string $command - Attention! *YOU* are in charge of escaping the command.
 * @param string|null $runtimeExceptionMessage
 *
 * @throws \RuntimeException if no execution method is available or command fails and message is provided
 * @return string
 */
function wrapExec(string $command, ?string $runtimeExceptionMessage = null): string
{
    $output = '';
    $returnCode = 0;

    if (function_exists('exec')) {
        $out = [];
        exec("{$command} 2>/dev/null", $out, $returnCode);
        $output = implode(PHP_EOL, array_map('trim', $out));
    } elseif (function_exists('shell_exec')) {
        $output = trim(shell_exec("{$command} 2>/dev/null"));
        $returnCode = ($output !== '') ? 0 : 1;
    } elseif (function_exists('passthru')) {
        ob_start();
        passthru("{$command} 2>/dev/null", $returnCode);
        $output = trim(ob_get_clean());
    } elseif (function_exists('system')) {
        ob_start();
        system("{$command} 2>/dev/null", $returnCode);
        $output = trim(ob_get_clean());
    } else {
        throw new RuntimeException('No available method (exec, shell_exec, passthru, system) to execute shell commands.');
    }

    if ($runtimeExceptionMessage && $returnCode !== 0) {
        throw new RuntimeException("Exec of \"{$command}\" failed: {$runtimeExceptionMessage}. Raw output: {$output}");
    }

    return ($returnCode === 0) ? $output : '';
}

/**
 * Checks if a binary exists in the system using wrap_exec().
 *
 * @param string $binary The binary to check.
 *
 * @return bool True if the binary exists and is executable, false otherwise.
 */
function binaryExists(string $binary): bool
{
    try {
        $binary = escapeshellarg($binary);
        $output = wrapExec("command -v {$binary}");
        return !empty($output);
    } catch (\RuntimeException $e) {
        return false;
    }
}
