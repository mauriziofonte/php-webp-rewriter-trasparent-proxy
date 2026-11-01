<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Http;

/**
 * PHP's built-in web server ("php -S") started in the background on a free
 * loopback port, with a router script and an explicit environment.
 *
 * Used by the tests that exercise the proxy over real HTTP: the front
 * controller and the fake origin each run in their own server, so that the
 * proxy fetches from the origin while the test talks to the proxy. The
 * server is started with PHP_BINARY, so it runs the PHP version of the test,
 * with expose_php on whatever php.ini says, so that the tests see the
 * "X-Powered-By: PHP/<version>" header a default installation sends.
 */
final class BuiltinServer
{
    /**
     * @var resource
     */
    private $process;

    /**
     * @var int
     */
    private $port;

    /**
     * @var string
     */
    private $logFile;

    /**
     * @var bool
     */
    private $stopped = false;

    /**
     * @param resource $process
     */
    private function __construct($process, int $port, string $logFile)
    {
        $this->process = $process;
        $this->port = $port;
        $this->logFile = $logFile;
    }

    /**
     * Starts a server and waits until it accepts connections.
     *
     * PHP errors are logged to the log file, never displayed in response
     * bodies, as with display_errors off in production (the built-in server
     * would print them into the body even with display_errors=stderr); the
     * code under test may still change that setting at runtime.
     *
     * @param string                $documentRoot directory given to -t
     * @param string                $router       router script run for every request
     * @param array<string, string> $environment  the whole environment of the server process
     * @param string                $logFile      file that receives the server's output (access log, PHP errors)
     *
     * @throws \RuntimeException when the server cannot be started within 10 seconds
     */
    public static function start(string $documentRoot, string $router, array $environment, string $logFile): self
    {
        $lastError = '';
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $port = self::freePort();
            $command = sprintf(
                '%s -d display_errors=0 -d log_errors=1 -d expose_php=1 -S 127.0.0.1:%d -t %s %s',
                escapeshellarg(PHP_BINARY),
                $port,
                escapeshellarg($documentRoot),
                escapeshellarg($router)
            );
            // exec replaces the shell, so that terminating the process stops the server itself.
            $process = proc_open(
                'exec ' . $command,
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
                $pipes,
                $documentRoot,
                $environment
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('Cannot start the PHP built-in web server');
            }

            $server = new self($process, $port, $logFile);
            if ($server->waitUntilReady()) {
                return $server;
            }
            $lastError = (string) @file_get_contents($logFile);
            $server->stop();
        }

        throw new \RuntimeException('The PHP built-in web server did not start: ' . $lastError);
    }

    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Base URL of the server, without trailing slash.
     */
    public function getBaseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /**
     * Everything the server wrote so far (access log and PHP errors).
     */
    public function getOutput(): string
    {
        return (string) @file_get_contents($this->logFile);
    }

    /**
     * Stops the server and waits for the process to exit. Calling it again
     * does nothing.
     */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        // proc_terminate() is harmless when the server has already exited.
        proc_terminate($this->process);
        proc_close($this->process);
    }

    /**
     * Waits until the port accepts connections, or until the server reports
     * that it cannot listen on it (the port was taken in the meantime).
     */
    private function waitUntilReady(): bool
    {
        $failure = sprintf('Failed to listen on 127.0.0.1:%d', $this->port);
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $errstr, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return true;
            }
            if (strpos($this->getOutput(), $failure) !== false) {
                return false;
            }
            usleep(10000);
        }

        return false;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($socket)) {
            throw new \RuntimeException('Cannot find a free port: ' . $errstr);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
