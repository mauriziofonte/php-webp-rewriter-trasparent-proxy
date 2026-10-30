<?php

declare(strict_types=1);

use Mfonte\ProxyCdn\Tests\Support\StrictErrorHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

error_reporting(E_ALL);

// Installed before PHPUnit starts: PHPUnit keeps an error handler that is
// already registered instead of replacing it with its own.
set_error_handler(new StrictErrorHandler(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor'));
