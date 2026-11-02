<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Console;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Config\ConfigLoader;
use Mfonte\ProxyCdn\Observability\JsonLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * The installation the cache commands work on: its configuration (the
 * environment and .env, as check-env reads them), its public/ directory
 * with the cache root public/_cache, its state directory var/, and the
 * log of the proxy (LOG_FILE, else logs/proxy.log).
 */
final class Installation
{
    /**
     * @var ConfigLoader
     */
    private $loader;

    /**
     * @var string
     */
    private $root;

    /**
     * @param string $root the project root, which holds public/, var/ and logs/
     */
    public function __construct(ConfigLoader $loader, string $root)
    {
        $this->loader = $loader;
        $this->root = rtrim($root, '/');
    }

    /**
     * @throws \Mfonte\ProxyCdn\Exception\InvalidConfigurationException
     */
    public function loadConfig(): Config
    {
        return $this->loader->load();
    }

    public function getRoot(): string
    {
        return $this->root;
    }

    public function getPublicDirectory(): string
    {
        return $this->root . '/public';
    }

    public function getCacheDirectory(): string
    {
        return $this->getPublicDirectory() . '/' . CacheKeyResolver::CACHE_DIRECTORY;
    }

    public function getStateDirectory(): string
    {
        return $this->root . '/var';
    }

    /**
     * The logger of the proxy, its entries marked with $requestId (a
     * RequestId::generate() of the run).
     */
    public function createLogger(Config $config, string $requestId): LoggerInterface
    {
        $operations = $config->getOperations();

        return new JsonLogger(
            $operations->getLogFile() ?? $this->root . '/logs/proxy.log',
            $operations->getLogLevel() ?? ($config->isDebug() ? LogLevel::DEBUG : LogLevel::NOTICE),
            JsonLogger::DEFAULT_CHANNEL,
            $requestId
        );
    }
}
