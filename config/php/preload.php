<?php

declare(strict_types=1);

/*
 * OPcache preload script of the proxy, for PHP 7.4 and later (finding S-3,
 * PRX-10). PHP-FPM runs it once when it starts: the classes it loads stay
 * compiled and linked in shared memory, so a request that PHP serves no
 * longer looks them up, includes them or links them.
 *
 * Enable it in the php.ini of the PHP-FPM pool (see config/php/proxy-cdn.ini):
 *
 *   opcache.preload=/srv/proxy-cdn/config/php/preload.php
 *   opcache.preload_user=www-data
 *
 * Preloaded classes are fixed until PHP-FPM restarts: restart it after every
 * deployment or "composer install". One PHP-FPM master preloads for all of
 * its pools, so only preload where no other application of that master uses
 * other versions of the same packages.
 *
 * It is safe when preloading is off: included any other way it only loads
 * classes, and on PHP 7.2 and 7.3, which have no preloading, it does
 * nothing. Nothing in it can stop PHP-FPM from starting: a class that does
 * not load is skipped.
 */

if (PHP_VERSION_ID < 70400) {
    return;
}

(static function (string $projectRoot): void {
    $autoload = $projectRoot . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        return;
    }

    try {
        require_once $autoload;
    } catch (\Throwable $exception) {
        // Composer's platform check: vendor/ was installed for another PHP.
        return;
    }

    // The classes of a request: the proxy (not its console commands), the
    // image library, psr/log (not the test helpers of psr/log 1, which
    // extend PHPUnit), and the core of symfony/http-foundation (not its
    // sessions, rate limiter, request matchers or test helpers).
    $patterns = [
        '/^Mfonte\\\\ProxyCdn\\\\(?!Console\\\\|Tests\\\\)/',
        '/^Mfonte\\\\SecureImageConverter\\\\/',
        '/^Psr\\\\Log\\\\(?!Test\\\\)/',
        '/^Symfony\\\\Component\\\\HttpFoundation\\\\(?:File\\\\|Exception\\\\)?[A-Za-z]+$/',
    ];

    $classes = [];
    $classMapFile = $projectRoot . '/vendor/composer/autoload_classmap.php';
    $classMap = is_file($classMapFile) ? require $classMapFile : [];
    if (is_array($classMap)) {
        foreach (array_keys($classMap) as $class) {
            $classes[] = (string) $class;
        }
    }
    // Without an optimised autoloader the class map lacks src/: list it.
    $source = $projectRoot . '/src';
    if (is_dir($source)) {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $relative = substr($file->getPathname(), strlen($source) + 1, -4);
                $classes[] = 'Mfonte\\ProxyCdn\\' . str_replace('/', '\\', $relative);
            }
        }
    }

    foreach (array_unique($classes) as $class) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $class) !== 1) {
                continue;
            }
            try {
                class_exists($class) || interface_exists($class) || trait_exists($class);
            } catch (\Throwable $exception) {
                // A class whose dependencies are missing is left to the
                // autoloader, as without preloading.
            }
            break;
        }
    }
})(dirname(__DIR__, 2));
