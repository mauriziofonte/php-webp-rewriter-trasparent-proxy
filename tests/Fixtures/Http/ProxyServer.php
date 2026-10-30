<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Http;

/**
 * A disposable copy of the application served by PHP's built-in web server
 * through its public/index.php, as Apache or nginx do for every request that
 * reaches PHP.
 *
 * The copy holds the front controller and the files it loads from the
 * project root; vendor/ is a symbolic link to this checkout's vendor/, so
 * the classes under src/ are loaded from the checkout. The cache, the
 * compiled configuration and the logs are written inside the copy.
 */
final class ProxyServer
{
    /**
     * Entries copied from the project root when they exist.
     */
    private const COPIED_ENTRIES = ['bin', 'public/index.php', 'public/.htaccess', 'composer.json'];

    /**
     * @var BuiltinServer
     */
    private $server;

    /**
     * @var string
     */
    private $projectDirectory;

    private function __construct(BuiltinServer $server, string $projectDirectory)
    {
        $this->server = $server;
        $this->projectDirectory = $projectDirectory;
    }

    /**
     * Copies the application into $projectDirectory, writes its .env (when
     * $dotEnv is not null) and starts the server.
     *
     * @param string                $projectDirectory directory to create for the copy
     * @param array<string, string> $environment      environment of the server process (PATH included)
     * @param string|null           $dotEnv           contents of the .env file; null for none
     */
    public static function start(string $projectDirectory, array $environment, ?string $dotEnv): self
    {
        $root = dirname(__DIR__, 3);
        if (!mkdir($projectDirectory . '/public', 0777, true) && !is_dir($projectDirectory . '/public')) {
            throw new \RuntimeException('Cannot create ' . $projectDirectory);
        }
        foreach (self::COPIED_ENTRIES as $entry) {
            if (file_exists($root . '/' . $entry)) {
                self::copy($root . '/' . $entry, $projectDirectory . '/' . $entry);
            }
        }
        symlink($root . '/vendor', $projectDirectory . '/vendor');
        if ($dotEnv !== null) {
            file_put_contents($projectDirectory . '/.env', $dotEnv);
        }

        $server = BuiltinServer::start(
            $projectDirectory . '/public',
            __DIR__ . '/front-controller.php',
            $environment,
            dirname($projectDirectory) . '/proxy-server.log'
        );

        return new self($server, $projectDirectory);
    }

    public function stop(): void
    {
        $this->server->stop();
    }

    public function getClient(): RawHttpClient
    {
        return new RawHttpClient($this->server->getPort());
    }

    public function getPort(): int
    {
        return $this->server->getPort();
    }

    public function getProjectDirectory(): string
    {
        return $this->projectDirectory;
    }

    /**
     * Everything the server wrote so far (access log and PHP errors).
     */
    public function getOutput(): string
    {
        return $this->server->getOutput();
    }

    private static function copy(string $source, string $target): void
    {
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        if (is_file($source)) {
            copy($source, $target);
            chmod($target, (int) fileperms($source) & 0777);

            return;
        }
        mkdir($target, 0777, true);
        foreach ((array) scandir($source) as $entry) {
            if (is_string($entry) && $entry !== '.' && $entry !== '..') {
                self::copy($source . '/' . $entry, $target . '/' . $entry);
            }
        }
    }
}
