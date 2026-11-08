<?php

declare(strict_types=1);

/*
 * Router script of ProxyServer. PHP's built-in web server reports the
 * requested path as SCRIPT_NAME when a router script is used; Apache and
 * PHP-FPM report the front controller instead. This script restores the
 * values the proxy sees in production, then runs public/index.php of the
 * application copy (the server's document root).
 */

$documentRoot = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '';
$frontController = rtrim($documentRoot, '/') . '/index.php';

$_SERVER['SCRIPT_FILENAME'] = $frontController;
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require $frontController;
