<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Console;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Cache\NegativeCache;
use Mfonte\ProxyCdn\Cache\Purger;
use Mfonte\ProxyCdn\Cluster\PeerNotifier;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Exception\InvalidConfigurationException;
use Mfonte\ProxyCdn\Http\ProxyKernelFactory;
use Mfonte\ProxyCdn\Http\RequestId;
use Mfonte\ProxyCdn\Security\HostAllowlist;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * "cache:purge": purges URLs of the proxy on this instance, as an
 * authenticated PURGE would (Purger: the entry, its siblings, its
 * pending copy and staged variants, both tiers, every query string of a
 * URL without one, and the outcome the requests of the URL share), then
 * on the peers of
 * CLUSTER_PEERS (PeerNotifier) unless --local. The URLs go through the
 * rules of live traffic (ProxyUrls).
 *
 * --all --host=<host> removes every entry of one host on this instance
 * only: a PURGE names one URL, so the peers are not told; run it on each.
 *
 * Exits with 1 when a URL is refused, 2 when a peer did not confirm.
 */
final class CachePurgeCommand extends Command
{
    public const NAME = 'cache:purge';

    /**
     * @var Installation
     */
    private $installation;

    /**
     * @var (callable(Config, LoggerInterface): ?PeerNotifier)|null
     */
    private $peers;

    /**
     * The optional $peers builds the notifier of the peers, for tests;
     * PeerNotifier::fromConfig() by default.
     *
     * @param (callable(Config, LoggerInterface): ?PeerNotifier)|null $peers
     */
    public function __construct(Installation $installation, ?callable $peers = null)
    {
        $this->installation = $installation;
        $this->peers = $peers;
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Purge URLs from the cache, here and on CLUSTER_PEERS')
            ->addArgument('urls', InputArgument::IS_ARRAY, 'Absolute URLs on a host of PROXY_HOSTS')
            ->addOption('local', null, InputOption::VALUE_NONE, 'Purge on this instance only')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Remove every entry of --host on this instance')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'The host of --all, one of PROXY_HOSTS');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->installation->loadConfig();
        } catch (InvalidConfigurationException $exception) {
            $output->writeln('<error>The configuration is invalid; run check-env.</error>');

            return 1;
        }
        $requestId = RequestId::generate();
        $logger = $this->installation->createLogger($config, $requestId);
        /** @var list<string> $urls */
        $urls = (array) $input->getArgument('urls');

        if ($input->getOption('all') === true) {
            if ($urls !== []) {
                $output->writeln('<error>--all takes no URL.</error>');

                return 1;
            }

            $host = $input->getOption('host');

            return $this->purgeHost(is_string($host) ? $config->getHostAllowlist()->match($host) : null, $output, $logger);
        }
        if ($urls === []) {
            $output->writeln('<error>Give the URLs to purge, or --all --host=<host>.</error>');

            return 1;
        }

        $storage = new FilesystemStorage();
        $state = $this->installation->getStateDirectory();
        $purger = new Purger(
            $storage,
            new NegativeCache($storage, $state . '/negative', $config->getNegativeCacheTtl()),
            ProxyKernelFactory::coalescer($config, $state, $config->getImageConverter() !== Config::CONVERTER_NONE, $logger, $storage)
        );
        $proxyUrls = new ProxyUrls($config, $this->installation->getCacheDirectory());
        $notifier = $input->getOption('local') === true ? null : ($this->peers === null ? PeerNotifier::fromConfig($config, $logger) : ($this->peers)($config, $logger));
        $exitCode = 0;
        foreach ($urls as $url) {
            $located = $proxyUrls->locate($url);
            if (is_string($located)) {
                $output->writeln('<error>✗ ' . OutputFormatter::escape($url) . ': ' . $located . '</error>');
                $exitCode = 1;
                continue;
            }
            list($httpHost, $target, $location) = $located;
            $removed = count($purger->purge($location));
            $logger->notice('purge', ['host' => $httpHost, 'target' => $target, 'removed' => $removed, 'auth' => 'cli']);
            $output->writeln('✓ ' . OutputFormatter::escape($url) . ': ' . $removed . ' files removed');
            if ($notifier === null) {
                continue;
            }
            foreach ($notifier->notify($httpHost, $target, $requestId) as $peer => $confirmed) {
                $output->writeln('  ' . ($confirmed ? '✓' : '<error>✗</error>') . ' ' . OutputFormatter::escape($peer));
                if (!$confirmed && $exitCode === 0) {
                    $exitCode = 2;
                }
            }
        }

        return $exitCode;
    }

    private function purgeHost(?string $host, OutputInterface $output, LoggerInterface $logger): int
    {
        if ($host === null) {
            $output->writeln('<error>--all needs --host=<host>, one of PROXY_HOSTS.</error>');

            return 1;
        }
        $cacheDirectory = $this->installation->getCacheDirectory();
        $hostDirectory = $cacheDirectory . '/' . self::directoryName($host);
        $removed = 0;
        if (is_link($hostDirectory)) {
            // The link alone, never what it points to.
            $removed = CacheTree::remove($hostDirectory);
        } elseif (is_dir($hostDirectory)) {
            // Renamed first, so that the web server stops serving the host at once.
            $trash = $cacheDirectory . '/.purge-' . bin2hex(random_bytes(8));
            $removed = CacheTree::remove(@rename($hostDirectory, $trash) ? $trash : $hostDirectory);
        }
        $logger->notice('purge', ['host' => $host, 'target' => '*', 'removed' => $removed, 'auth' => 'cli']);
        $output->writeln('✓ ' . $host . ': ' . $removed . ' files removed on this instance. Run it on every instance of the cluster.');

        return 0;
    }

    /**
     * The name of the cache directory of a host of the allowlist: a host
     * name (HostAllowlist::HOST_PATTERN) has no separator and no dot
     * segment; anything else is refused.
     *
     * @psalm-taint-escape file
     */
    private static function directoryName(string $host): string
    {
        if (preg_match(HostAllowlist::HOST_PATTERN, $host) !== 1) {
            throw new \UnexpectedValueException('Not a host name of the allowlist');
        }

        return $host;
    }
}
