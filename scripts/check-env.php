#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

define('STDOUT_ISATTY', function_exists('posix_isatty') && posix_isatty(STDOUT));

function color(string $text, string $ansiCode): string
{
    return STDOUT_ISATTY ? "\033[{$ansiCode}m{$text}\033[0m" : $text;
}

function success(string $text): void
{
    echo color("\xE2\x9C\x93 {$text}", '32') . PHP_EOL;
}

function failure(string $text): void
{
    echo color("\xE2\x9C\x97 {$text}", '31') . PHP_EOL;
}

function listError(string $text): void
{
    echo '    ' . color("\xE2\x80\xA2", '31') . ' ' . $text . PHP_EOL;
}

$envFile = dirname(__DIR__) . '/.env';

if (! is_file($envFile)) {
    failure('Environment check failed: you must first create the .env file with the desired configuration options.');
    exit(1);
}

$dotenv = Dotenv\Dotenv::createUnsafeImmutable(dirname(__DIR__));
$dotenv->load();

$errors = [];

/* ------------------------------------------------------------------ *
 * Required values
 * ------------------------------------------------------------------ */

$origin = getenv('SOURCE_ORIGIN');
if (! $origin) {
    $errors[] = 'SOURCE_ORIGIN must be set';
} elseif (! filter_var($origin, FILTER_VALIDATE_URL)) {
    $errors[] = 'SOURCE_ORIGIN must be a valid URL (got: ' . var_export($origin, true) . ')';
} elseif (! preg_match('#^https?://#i', $origin)) {
    $errors[] = 'SOURCE_ORIGIN must use http:// or https:// scheme (got: ' . var_export($origin, true) . ')';
}

$cacheableExtensions = getenv('CACHEABLE_EXTENSIONS');
if (! $cacheableExtensions) {
    $errors[] = 'CACHEABLE_EXTENSIONS must be set (comma-separated list of file extensions)';
} else {
    $tokens = array_filter(array_map('trim', explode(',', $cacheableExtensions)));
    $sensitive = array_intersect(['html', 'htm', 'php', 'xml', 'json'], array_map('strtolower', $tokens));
    if (! empty($sensitive)) {
        echo color('! ', '33') . 'WARNING: CACHEABLE_EXTENSIONS includes session/auth-sensitive types: '
            . implode(', ', $sensitive) . '.' . PHP_EOL;
        echo '    If the origin ever serves authenticated content with these extensions,' . PHP_EOL;
        echo '    the proxy will cache and re-serve it to unrelated clients.' . PHP_EOL;
    }
}

/* ------------------------------------------------------------------ *
 * Image conversion
 * ------------------------------------------------------------------ */

$mode = getenv('IMAGE_CONVERSION_MODE') ?: 'none';

if (! in_array($mode, ['native', 'api', 'none'], true)) {
    $errors[] = 'IMAGE_CONVERSION_MODE must be one of: native, api, none (got: ' . var_export($mode, true) . ')';
}

if ($mode === 'native') {
    if (! binaryExists('cwebp')) {
        $errors[] = 'cwebp binary not found in PATH (required when IMAGE_CONVERSION_MODE = "native")';
    }
}

if ($mode === 'api') {
    if (! getenv('WEBP_API_SERVER')) {
        $errors[] = 'WEBP_API_SERVER must be set for API mode';
    }
    if (! getenv('WEBP_API_KEY')) {
        $errors[] = 'WEBP_API_KEY must be set for API mode';
    }
}

/* ------------------------------------------------------------------ *
 * Numeric values: validated so typos do not silently resolve to 0
 * ------------------------------------------------------------------ */

$numericVars = [
    'CACHE_EXPIRY'              => ['min' => 0,    'max' => PHP_INT_MAX],
    'NEGATIVE_CACHE_TTL'        => ['min' => 0,    'max' => 86400],
    'NETWORK_ERROR_TTL'         => ['min' => 0,    'max' => 3600],
    'UPSTREAM_TIMEOUT'          => ['min' => 1,    'max' => 300],
    'UPSTREAM_CONNECT_TIMEOUT'  => ['min' => 1,    'max' => 60],
];

foreach ($numericVars as $var => $range) {
    $raw = getenv($var);
    if ($raw === false || $raw === '' || $raw === 'null') {
        continue; // defaults will apply
    }
    if (! ctype_digit($raw)) {
        $errors[] = "{$var} must be a non-negative integer (got: " . var_export($raw, true) . ')';
        continue;
    }
    $val = (int) $raw;
    if ($val < $range['min'] || $val > $range['max']) {
        $errors[] = "{$var} must be between {$range['min']} and {$range['max']} (got: {$val})";
    }
}

/* ------------------------------------------------------------------ *
 * Boolean-ish values
 * ------------------------------------------------------------------ */

$boolVars = ['DEBUG', 'UPSTREAM_VERIFY_TLS', 'UPSTREAM_FOLLOW_REDIRECTS'];
foreach ($boolVars as $var) {
    $raw = getenv($var);
    if ($raw === false || $raw === '' || $raw === 'null') {
        continue;
    }
    if (! in_array(strtolower($raw), ['true', 'false', '1', '0'], true)) {
        $errors[] = "{$var} must be one of: true, false, 1, 0 (got: " . var_export($raw, true) . ')';
    }
}

/* ------------------------------------------------------------------ *
 * Soft warnings
 * ------------------------------------------------------------------ */

if (strtolower((string) getenv('DEBUG')) === 'true' || getenv('DEBUG') === '1') {
    echo color('! ', '33') . 'WARNING: DEBUG is enabled. MUST be disabled in production,' . PHP_EOL;
    echo '    or PHP warnings will poison asset bodies streamed to clients.' . PHP_EOL;
}

if (getenv('SOURCE_ORIGIN_SERVER')) {
    echo color('! ', '33') . 'NOTE: SOURCE_ORIGIN_SERVER is set. cURL will bypass DNS and' . PHP_EOL;
    echo '    talk directly to the pinned IP:port. This intentionally bypasses any' . PHP_EOL;
    echo '    CDN / WAF that may sit in front of the origin hostname.' . PHP_EOL;
}

$verifyTls = strtolower((string) getenv('UPSTREAM_VERIFY_TLS'));
if ($verifyTls !== 'true' && $verifyTls !== '1') {
    echo color('! ', '33') . 'NOTE: UPSTREAM_VERIFY_TLS is off. The origin TLS certificate' . PHP_EOL;
    echo '    will NOT be validated. Acceptable on trusted networks only.' . PHP_EOL;
}

/* ------------------------------------------------------------------ *
 * Final report
 * ------------------------------------------------------------------ */

if (! empty($errors)) {
    failure('Environment check failed:');
    foreach ($errors as $error) {
        listError($error);
    }
    exit(1);
}

success('Environment check passed.');
exit(0);
