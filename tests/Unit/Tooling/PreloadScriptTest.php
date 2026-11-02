<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * config/php/preload.php, the OPcache preload script of PRX-10: on PHP 7.4
 * and later it preloads the classes of a request without a warning; with
 * preloading off it only loads classes; on PHP 7.2 and 7.3 it does nothing.
 *
 * Each case runs PHP_BINARY in a child process with OPcache enabled for the
 * CLI, so that a warning PHP prints while preloading is seen on its stderr.
 *
 * @coversNothing
 */
#[CoversNothing]
final class PreloadScriptTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../config/php/preload.php';

    /**
     * Prints the classes OPcache preloaded, as JSON.
     */
    private const PRELOADED_CLASSES = '$status = function_exists("opcache_get_status") ? opcache_get_status(false) : false;'
        . ' echo json_encode(is_array($status) && isset($status["preload_statistics"]["classes"]) ? $status["preload_statistics"]["classes"] : []);';

    public function testItPreloadsTheClassesOfARequestWithoutWarnings(): void
    {
        if (PHP_VERSION_ID < 70400) {
            self::markTestSkipped('Preloading needs PHP 7.4');
        }
        $options = $this->opcacheOptions();
        $options[] = '-d';
        $options[] = 'opcache.preload=' . realpath(self::SCRIPT);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            // PHP refuses to preload as root without a preload user.
            $user = posix_getpwuid(0);
            $options[] = '-d';
            $options[] = 'opcache.preload_user=' . (is_array($user) ? $user['name'] : 'root');
        }

        list($exitCode, $output, $errors) = $this->runPhp($options, self::PRELOADED_CLASSES);

        self::assertSame(0, $exitCode, $errors);
        self::assertSame('', $errors);
        $classes = json_decode($output, true);
        self::assertIsArray($classes, $output);
        foreach ([
            'Mfonte\\ProxyCdn\\Http\\ProxyKernel',
            'Mfonte\\ProxyCdn\\Config\\ConfigLoader',
            'Mfonte\\ProxyCdn\\Origin\\CurlTransport',
            'Mfonte\\SecureImageConverter\\Concurrency\\KeyedLock',
            'Psr\\Log\\AbstractLogger',
            'Symfony\\Component\\HttpFoundation\\Request',
            'Symfony\\Component\\HttpFoundation\\BinaryFileResponse',
        ] as $class) {
            self::assertContains($class, $classes);
        }
        self::assertNotContains('Mfonte\\ProxyCdn\\Console\\CheckEnvCommand', $classes);
    }

    /**
     * Included without preloading, as by mistake in a request, it loads
     * classes and nothing else; on PHP 7.2 and 7.3 it loads none.
     */
    public function testWithoutPreloadingItOnlyLoadsClasses(): void
    {
        $code = 'require ' . var_export(realpath(self::SCRIPT), true) . ';'
            . ' echo class_exists("Mfonte\\\\ProxyCdn\\\\Http\\\\ProxyKernel", false) ? "loaded" : "not loaded";';

        list($exitCode, $output, $errors) = $this->runPhp([], $code);

        self::assertSame(0, $exitCode, $errors);
        self::assertSame('', $errors);
        self::assertSame(PHP_VERSION_ID < 70400 ? 'not loaded' : 'loaded', $output);
    }

    /**
     * The -d options that enable OPcache in a child process; skips the test
     * when OPcache cannot be loaded.
     *
     * @return list<string>
     */
    private function opcacheOptions(): array
    {
        $options = ['-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1'];
        if (!extension_loaded('Zend OPcache')) {
            $options = array_merge(['-d', 'zend_extension=opcache'], $options);
        }
        list(, $output) = $this->runPhp($options, 'echo extension_loaded("Zend OPcache") ? "yes" : "no";');
        if ($output !== 'yes') {
            self::markTestSkipped('OPcache cannot be loaded by ' . PHP_BINARY);
        }

        return $options;
    }

    /**
     * Runs PHP_BINARY with $options and the code $code.
     *
     * @param list<string> $options
     *
     * @return array{int, string, string} the exit code, stdout and stderr
     */
    private function runPhp(array $options, string $code): array
    {
        $command = escapeshellarg(PHP_BINARY);
        foreach ($options as $option) {
            $command .= ' ' . escapeshellarg($option);
        }
        $command .= ' -r ' . escapeshellarg($code);

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, is_string($output) ? $output : '', is_string($errors) ? $errors : ''];
    }
}
