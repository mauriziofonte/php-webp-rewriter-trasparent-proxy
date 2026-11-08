<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\FakeApi;

use Mfonte\ProxyCdn\Tests\Fixtures\Http\BuiltinServer;

/**
 * A fake conversion API over real HTTP (router.php in PHP's built-in web
 * server): it answers each path as answer() set it, and records every
 * request it receives.
 */
final class FakeApiServer
{
    /**
     * @var BuiltinServer
     */
    private $server;

    /**
     * @var string
     */
    private $stateDirectory;

    private function __construct(BuiltinServer $server, string $stateDirectory)
    {
        $this->server = $server;
        $this->stateDirectory = $stateDirectory;
    }

    /**
     * @param string $stateDirectory existing directory for the answers and the request log
     */
    public static function start(string $stateDirectory): self
    {
        $server = BuiltinServer::start(
            $stateDirectory,
            __DIR__ . '/router.php',
            ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'FAKE_API_STATE' => $stateDirectory],
            $stateDirectory . '/server.log'
        );

        return new self($server, $stateDirectory);
    }

    public function stop(): void
    {
        $this->server->stop();
    }

    public function getBaseUrl(): string
    {
        return $this->server->getBaseUrl();
    }

    /**
     * Sets the answer of $path: its status, its headers (a list of values
     * per name), and its body: $body, or the contents of $file, or $bytes
     * zero bytes sent without Content-Length.
     *
     * @param array<string, string|list<string>> $headers
     */
    public function answer(string $path, int $status, array $headers = [], ?string $body = null, ?string $file = null, ?int $bytes = null): void
    {
        $answers = $this->answers();
        $answers[$path] = ['status' => $status, 'headers' => $headers, 'body' => $body, 'file' => $file, 'bytes' => $bytes];
        file_put_contents($this->stateDirectory . '/answers.json', json_encode($answers));
    }

    /**
     * The requests received so far, oldest first.
     *
     * @return list<array{method: string, target: string, path: string, headers: array<string, string>, body_length: int, body_sha256: string}>
     */
    public function getRequests(): array
    {
        $requests = [];
        $lines = @file($this->stateDirectory . '/requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines === false ? [] : $lines as $line) {
            $request = json_decode($line, true);
            if (is_array($request)) {
                /** @var array{method: string, target: string, path: string, headers: array<string, string>, body_length: int, body_sha256: string} $request */
                $requests[] = $request;
            }
        }

        return $requests;
    }

    /**
     * @return array<mixed>
     */
    private function answers(): array
    {
        $answers = json_decode((string) @file_get_contents($this->stateDirectory . '/answers.json'), true);

        return is_array($answers) ? $answers : [];
    }
}
