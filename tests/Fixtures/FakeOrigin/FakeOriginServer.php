<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\FakeOrigin;

use Mfonte\ProxyCdn\Tests\Fixtures\Http\BuiltinServer;

/**
 * Fake origin over real HTTP (router.php in PHP's built-in web server): it
 * serves tests/Fixtures/origin, records every request it receives, and
 * answers with another status for the paths that have a rule.
 */
final class FakeOriginServer
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
     * @param string      $stateDirectory existing directory for the request log and the rules
     * @param string|null $cacheControl   the Cache-Control of the files; null for the default of router.php,
     *                                    "private, max-age=60"
     */
    public static function start(string $stateDirectory, ?string $cacheControl = null): self
    {
        $documentRoot = dirname(__DIR__) . '/origin';
        $environment = [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'FAKE_ORIGIN_STATE' => $stateDirectory,
            'FAKE_ORIGIN_DOCROOT' => $documentRoot,
        ];
        if ($cacheControl !== null) {
            $environment['FAKE_ORIGIN_CACHE_CONTROL'] = $cacheControl;
        }
        $server = BuiltinServer::start(
            $documentRoot,
            __DIR__ . '/router.php',
            $environment,
            $stateDirectory . '/server.log'
        );

        return new self($server, $stateDirectory);
    }

    public function stop(): void
    {
        $this->server->stop();
    }

    public function getPort(): int
    {
        return $this->server->getPort();
    }

    public function getBaseUrl(): string
    {
        return $this->server->getBaseUrl();
    }

    /**
     * Makes every later request for $path (decoded, without query string)
     * answer with $status.
     *
     * @param array<string, string> $headers extra response headers
     */
    public function setRule(string $path, int $status, ?string $location = null, ?string $body = null, array $headers = []): void
    {
        $rules = $this->readJson('rules.json');
        $rules[$path] = ['status' => $status, 'location' => $location, 'body' => $body, 'headers' => $headers];
        file_put_contents($this->stateDirectory . '/rules.json', json_encode($rules));
    }

    public function removeRule(string $path): void
    {
        $rules = $this->readJson('rules.json');
        unset($rules[$path]);
        file_put_contents($this->stateDirectory . '/rules.json', json_encode($rules));
    }

    /**
     * Every request received so far, oldest first.
     *
     * @return list<array{method: string, uri: string, path: string, headers: array<string, string>}>
     */
    public function getRequests(): array
    {
        $requests = [];
        $lines = @file($this->stateDirectory . '/requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines === false ? [] : $lines as $line) {
            /** @var array{method: string, uri: string, path: string, headers: array<string, string>} $request */
            $request = json_decode($line, true);
            $requests[] = $request;
        }

        return $requests;
    }

    /**
     * Number of requests received for a decoded path, any query string.
     */
    public function countRequestsFor(string $path): int
    {
        $count = 0;
        foreach ($this->getRequests() as $request) {
            if ($request['path'] === $path) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The last request received.
     *
     * @return array{method: string, uri: string, path: string, headers: array<string, string>}
     */
    public function getLastRequest(): array
    {
        $requests = $this->getRequests();
        if ($requests === []) {
            throw new \LogicException('The fake origin has received no request');
        }

        return $requests[count($requests) - 1];
    }

    /**
     * @return array<mixed>
     */
    private function readJson(string $file): array
    {
        $decoded = json_decode((string) @file_get_contents($this->stateDirectory . '/' . $file), true);

        return is_array($decoded) ? $decoded : [];
    }
}
