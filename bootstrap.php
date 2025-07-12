<?php

if (function_exists('get_included_files') && count(get_included_files()) == 1) {
    exit();
}

// check for availability of curl_ functions
if (! function_exists('curl_init')) {
    die('cURL extension is not installed');
}

define('DS', DIRECTORY_SEPARATOR);
define('ROOT_DIR', rtrim(__DIR__, DS).DS);
define('CONF_FILE', ROOT_DIR.'.env');
define('VENDOR_DIR', ROOT_DIR.'vendor'.DS);
define('PUBLIC_DIR', ROOT_DIR.'public'.DS);
define(
    'IS_HTTPS',
    (
        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
        (isset($_SERVER['SERVER_PORT']) && (int) ($_SERVER['SERVER_PORT']) === 443) ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
        (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https')
    ) ? true : false
);
define('FROM_CLI', (php_sapi_name() === 'cli' || defined('STDIN')) ? true : false);

if (! is_file(CONF_FILE)) {
    die('Cannot find application .env. Please, read the attached README.md for instructions on how to install this application');
}
if (! is_file(VENDOR_DIR.'autoload.php')) {
    die('Cannot find application vendor autoload. Please, read the attached README.md for instructions on how to install this application');
}

// require autoload
require_once VENDOR_DIR.'autoload.php';

// load (and eventually cache) the .env config
loadConfig(ROOT_DIR);

// IDE helper
if (!defined('DEBUG')) {
    define('DEBUG', '');
}
if (!defined('SOURCE_ORIGIN')) {
    define('SOURCE_ORIGIN', '');
}
if (!defined('SOURCE_ORIGIN_SERVER')) {
    define('SOURCE_ORIGIN_SERVER', '');
}
if (!defined('CHARSET')) {
    define('CHARSET', '');
}
if (!defined('TIMEZONE')) {
    define('TIMEZONE', '');
}
if (!defined('LOCALE')) {
    define('LOCALE', '');
}
if (!defined('CACHEABLE_EXTENSIONS')) {
    define('CACHEABLE_EXTENSIONS', '');
}
if (!defined('CACHE_EXPIRY')) {
    define('CACHE_EXPIRY', '');
}
if (!defined('IMAGE_CONVERSION_MODE')) {
    define('IMAGE_CONVERSION_MODE', '');
}
if (!defined('WEBP_API_SERVER')) {
    define('WEBP_API_SERVER', '');
}
if (!defined('WEBP_API_KEY')) {
    define('WEBP_API_KEY', '');
}

// debug mode?
if (DEBUG) {
    @error_reporting(E_ALL);
    @ini_set('display_errors', 1);
} else {
    error_reporting(0);
    @ini_set('display_errors', 'Off');
}

// charset, timezone, locale
@ini_set('default_charset', CHARSET);
date_default_timezone_set(TIMEZONE);
setlocale(LC_TIME, LOCALE);
setlocale(LC_MONETARY, implode('.', [LOCALE, CHARSET]));
