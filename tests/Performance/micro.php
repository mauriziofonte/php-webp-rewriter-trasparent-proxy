<?php

declare(strict_types=1);

/*
 * Runs MicroBenchmark with vendor/ of the project and prints the median of
 * each case in microseconds, as JSON. run.sh runs it in the PHP-FPM image
 * under test, with OPcache enabled for the CLI and the php.ini of the suite.
 *
 * Usage: php -d opcache.enable_cli=1 micro.php [iterations] [.env file]
 */

use Mfonte\ProxyCdn\Tests\Performance\MicroBenchmark;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
// Loaded by path: the performance job installs no development autoloading.
require_once __DIR__ . '/LatencyReport.php';
require_once __DIR__ . '/MicroBenchmark.php';

$arguments = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
$iterations = isset($arguments[1]) && is_numeric($arguments[1]) ? (int) $arguments[1] : 2000;
$envFile = isset($arguments[2]) && is_string($arguments[2]) ? $arguments[2] : $root . '/tests/Integration/docker/app.env';

$directory = sys_get_temp_dir() . '/proxycdn-micro-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700)) {
    fwrite(STDERR, 'Cannot create ' . $directory . PHP_EOL);
    exit(1);
}

try {
    $results = (new MicroBenchmark($directory, $envFile, $iterations))->run();
} finally {
    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        if ($file instanceof \SplFileInfo) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
    }
    rmdir($directory);
}

$status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
echo json_encode([
    'php' => PHP_VERSION,
    'opcache' => is_array($status) && $status['opcache_enabled'] === true,
    'iterations' => $iterations,
    'medianMicroseconds' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
