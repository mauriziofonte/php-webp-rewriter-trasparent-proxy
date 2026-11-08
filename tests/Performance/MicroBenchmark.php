<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Performance;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Config\CompiledConfigCache;
use Mfonte\ProxyCdn\Config\ConfigLoader;
use Mfonte\ProxyCdn\Http\EdgeKernelFactory;
use Mfonte\ProxyCdn\Http\ProxyKernelFactory;
use Mfonte\ProxyCdn\Image\ImageConverterFactory;
use Mfonte\ProxyCdn\Observability\Metrics;
use Mfonte\ProxyCdn\Origin\HttpOriginFetcher;
use Psr\Log\NullLogger;

/**
 * Microbenchmarks of the per-request work that precedes EdgeKernel::handle()
 * in public/index.php: loading the configuration (with and without the
 * compiled configuration) and building the kernel, with and without the
 * middleware of the edge kernel (request ID, /healthz, /metrics, miss rate
 * limit), as configured by the .env. They explain the share
 * of these steps in the server-side latency of a hit PHP serves.
 *
 * Every case runs in a scratch directory with the .env of the integration
 * harness; the median of the iterations is reported, in microseconds.
 */
final class MicroBenchmark
{
    /**
     * @var string
     */
    private $directory;

    /**
     * @var int
     */
    private $iterations;

    /**
     * @param string $directory  an empty scratch directory, writable by this process
     * @param string $envFile    the .env to measure with
     * @param int    $iterations iterations of each case
     */
    public function __construct(string $directory, string $envFile, int $iterations)
    {
        $this->directory = rtrim($directory, '/');
        $this->iterations = max(1, $iterations);
        if (!@copy($envFile, $this->directory . '/.env')) {
            throw new \RuntimeException('Cannot copy ' . $envFile . ' to ' . $this->directory);
        }
    }

    /**
     * The median duration of each case, in microseconds.
     *
     * @return array<string, float>
     */
    public function run(): array
    {
        $envFile = $this->directory . '/.env';
        $storage = new FilesystemStorage();
        $compiled = new CompiledConfigCache($this->directory . '/.config.cache.php', $storage);
        $uncachedLoader = new ConfigLoader($envFile);
        $compiledLoader = new ConfigLoader($envFile, $compiled);
        // The compiled file exists and is warm in OPcache before measuring.
        $config = $compiledLoader->load();
        $logger = new NullLogger();
        $public = $this->directory . '/public';
        $state = $this->directory . '/var';
        @mkdir($public, 0755);
        $fetcher = HttpOriginFetcher::fromConfig($config, $logger);

        return [
            'configuration, parsed and validated (no compiled file)' => $this->measure(static function () use ($uncachedLoader): void {
                $uncachedLoader->load();
            }),
            'configuration, compiled (fingerprint and include)' => $this->measure(static function () use ($compiledLoader): void {
                $compiledLoader->load();
            }),
            'configuration, new loader and compiled file, as public/index.php' => $this->measure(static function () use ($envFile, $compiled): void {
                (new ConfigLoader($envFile, $compiled))->load();
            }),
            'origin client (HttpOriginFetcher::fromConfig)' => $this->measure(static function () use ($config, $logger): void {
                HttpOriginFetcher::fromConfig($config, $logger);
            }),
            'image converter (ImageConverterFactory::create)' => $this->measure(static function () use ($config, $state, $logger, $storage): void {
                ImageConverterFactory::create($config, $state, $logger, $storage);
            }),
            'kernel object graph (ProxyKernelFactory::create)' => $this->measure(static function () use ($config, $public, $state, $fetcher, $logger): void {
                ProxyKernelFactory::create($config, $public, $state, $fetcher, null, $logger);
            }),
            'edge kernel object graph with its middleware, as public/index.php (EdgeKernelFactory::create)' => $this->measure(static function () use ($config, $public, $state, $fetcher, $logger, $storage): void {
                EdgeKernelFactory::create(
                    $config,
                    $public,
                    $state,
                    $fetcher,
                    ImageConverterFactory::create($config, $state, $logger, $storage),
                    $logger,
                    Metrics::fromConfig($config, $state)
                );
            }),
        ];
    }

    /**
     * The median duration of $case over the iterations, in microseconds,
     * after one call that is not counted. The stat cache is cleared before
     * each call, as PHP does between two requests.
     */
    private function measure(callable $case): float
    {
        $case();
        $durations = [];
        for ($i = 0; $i < $this->iterations; ++$i) {
            clearstatcache();
            $start = microtime(true);
            $case();
            $durations[] = (microtime(true) - $start) * 1000000;
        }

        return LatencyReport::median($durations);
    }
}
