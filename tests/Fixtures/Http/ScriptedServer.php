<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Http;

/**
 * The scripted HTTP/1.1 server of scripted-server.php (see its header for
 * the endpoints), started in the background with PHP_BINARY on a free port.
 */
final class ScriptedServer
{
    /**
     * Size of the block the payload of /bytes/<n> repeats.
     */
    public const BLOCK_SIZE = 65536;

    /**
     * @var resource
     */
    private $process;

    /**
     * @var string
     */
    private $stateDirectory;

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var int
     */
    private $port;

    /**
     * @var bool
     */
    private $stopped = false;

    /**
     * @param resource $process
     */
    private function __construct($process, string $stateDirectory, string $baseUrl, int $port)
    {
        $this->process = $process;
        $this->stateDirectory = $stateDirectory;
        $this->baseUrl = $baseUrl;
        $this->port = $port;
    }

    /**
     * Starts a server and waits until it listens.
     *
     * @param string      $stateDirectory existing empty directory for the port file, the request log and the output
     * @param string|null $certificate    PEM file with the certificate and its key, to serve TLS; null for plain HTTP
     * @param string      $bind           address to listen on: "127.0.0.1" or "::1"
     *
     * @throws \RuntimeException when the server does not start within 10 seconds
     */
    public static function start(string $stateDirectory, ?string $certificate = null, string $bind = '127.0.0.1'): self
    {
        $command = sprintf(
            'exec %s %s %s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/scripted-server.php'),
            escapeshellarg($stateDirectory),
            escapeshellarg($certificate ?? '-'),
            escapeshellarg($bind)
        );
        $log = $stateDirectory . '/server.log';
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start the scripted server');
        }

        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $port = @file_get_contents($stateDirectory . '/port');
            if (is_string($port) && preg_match('/\A[0-9]+\z/', $port) === 1) {
                $host = strpos($bind, ':') !== false ? '[' . $bind . ']' : $bind;

                return new self($process, $stateDirectory, ($certificate === null ? 'http' : 'https') . '://' . $host . ':' . $port, (int) $port);
            }
            if (is_file($stateDirectory . '/error') || !self::isRunning($process)) {
                break;
            }
            usleep(10000);
        }
        proc_terminate($process);
        proc_close($process);

        throw new \RuntimeException('The scripted server did not start: ' . @file_get_contents($log) . @file_get_contents($stateDirectory . '/error'));
    }

    /**
     * @param resource $process
     */
    private static function isRunning($process): bool
    {
        // An array on PHP 8, array|false before: read it as a list of fields.
        foreach ((array) proc_get_status($process) as $field => $value) {
            if ($field === 'running') {
                return $value === true;
            }
        }

        return false;
    }

    /**
     * Stops the server. Calling it again does nothing.
     */
    public function stop(): void
    {
        if (!$this->stopped) {
            $this->stopped = true;
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    /**
     * "http://127.0.0.1:<port>", "https://..." or "http://[::1]:<port>".
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Every request received so far, oldest first.
     *
     * @return list<array{connection: int, index: int, method: string, target: string, version: string, headers: array<string, string>}>
     */
    public function getRequests(): array
    {
        $requests = [];
        $lines = @file($this->stateDirectory . '/requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines === false ? [] : $lines as $line) {
            /** @var array{connection: int, index: int, method: string, target: string, version: string, headers: array<string, string>} $request */
            $request = json_decode($line, true);
            $requests[] = $request;
        }

        return $requests;
    }

    /**
     * The last request received.
     *
     * @return array{connection: int, index: int, method: string, target: string, version: string, headers: array<string, string>}
     */
    public function getLastRequest(): array
    {
        $requests = $this->getRequests();
        if ($requests === []) {
            throw new \LogicException('The scripted server has received no request');
        }

        return $requests[count($requests) - 1];
    }

    /**
     * The first $size bytes of the payload of /bytes/<n>.
     */
    public static function payload(int $size): string
    {
        $block = self::block();

        return str_repeat($block, intdiv($size, self::BLOCK_SIZE)) . substr($block, 0, $size % self::BLOCK_SIZE);
    }

    /**
     * SHA-256 of payload($size), computed without holding it in memory.
     */
    public static function payloadHash(int $size): string
    {
        $block = self::block();
        $context = hash_init('sha256');
        for ($offset = 0; $offset < $size; $offset += self::BLOCK_SIZE) {
            hash_update($context, $size - $offset >= self::BLOCK_SIZE ? $block : substr($block, 0, $size - $offset));
        }

        return hash_final($context);
    }

    /**
     * The block repeated by the payload, as scripted-server.php builds it.
     */
    private static function block(): string
    {
        $block = '';
        while (strlen($block) < self::BLOCK_SIZE) {
            $block .= hash('sha256', (string) strlen($block));
        }

        return substr($block, 0, self::BLOCK_SIZE);
    }
}
