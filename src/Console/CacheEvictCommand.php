<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Console;

use Mfonte\ProxyCdn\Cache\CacheUsage;
use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Config\OperationsConfig;
use Mfonte\ProxyCdn\Exception\InvalidConfigurationException;
use Mfonte\ProxyCdn\Http\RequestId;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * "cache:evict": brings public/_cache down to CACHE_MAX_SIZE (or
 * --max-size), least recently used entry first, each entry with all its
 * siblings (CacheEvictor), and writes the measured size to
 * var/cache-usage.json for /metrics. Without a size cap it only measures.
 * Meant for cron; see CacheEvictor for the order and the symlink rules.
 */
final class CacheEvictCommand extends Command
{
    public const NAME = 'cache:evict';

    /**
     * The evicted entries a dry run lists at most.
     */
    public const MAX_LISTED = 50;

    /**
     * @var Installation
     */
    private $installation;

    public function __construct(Installation $installation)
    {
        $this->installation = $installation;
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Evict the least recently used cache entries down to a size cap')
            ->addOption('max-size', null, InputOption::VALUE_REQUIRED, 'The size cap, in bytes or with a K, M, G or T suffix (default: CACHE_MAX_SIZE; 0 only measures)')
            ->addOption('by', null, InputOption::VALUE_REQUIRED, 'Order by "atime" (last access, the default) or "mtime" (last write, for noatime mounts)', CacheEvictor::BY_ATIME)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be evicted, remove nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->installation->loadConfig();
        } catch (InvalidConfigurationException $exception) {
            $output->writeln('<error>The configuration is invalid; run check-env.</error>');

            return 1;
        }
        $maxSize = $config->getOperations()->getCacheMaxSize();
        $option = $input->getOption('max-size');
        if (is_string($option)) {
            $parsed = OperationsConfig::parseSize($option);
            if ($parsed === null) {
                $output->writeln('<error>--max-size must be a size in bytes, or with a K, M, G or T suffix.</error>');

                return 1;
            }
            $maxSize = $parsed;
        }
        $by = $input->getOption('by');
        if (!is_string($by) || ($by !== CacheEvictor::BY_ATIME && $by !== CacheEvictor::BY_MTIME)) {
            $output->writeln('<error>--by must be "atime" or "mtime".</error>');

            return 1;
        }
        $dryRun = $input->getOption('dry-run') === true;

        $report = (new CacheEvictor($this->installation->getCacheDirectory(), $by))->evict($maxSize, $dryRun);

        $output->writeln(sprintf('Cache: %d entries, %d bytes.', $report->getEntries(), $report->getBytes()));
        if ($report->getLinks() > 0) {
            $output->writeln(sprintf('<comment>%d symbolic links in the cache were left alone.</comment>', $report->getLinks()));
        }
        if ($maxSize <= 0) {
            $output->writeln('No size cap (CACHE_MAX_SIZE or --max-size): nothing to evict.');
        } elseif ($dryRun) {
            $output->writeln(sprintf('Would evict %d entries, %d bytes, down to %d bytes:', count($report->getEvicted()), $report->getEvictedBytes(), $report->getRemainingBytes()));
            foreach (array_slice($report->getEvicted(), 0, self::MAX_LISTED) as $key) {
                $output->writeln('  ' . OutputFormatter::escape($key));
            }
        } else {
            $output->writeln(sprintf('Evicted %d entries, %d bytes; %d bytes left.', count($report->getEvicted()), $report->getEvictedBytes(), $report->getRemainingBytes()));
            if ($report->getKept() > 0) {
                $output->writeln(sprintf('%d entries used during the run were kept.', $report->getKept()));
            }
            $this->installation->createLogger($config, RequestId::generate())->notice('cache evicted', [
                'evicted' => count($report->getEvicted()),
                'evicted_bytes' => $report->getEvictedBytes(),
                'bytes' => $report->getRemainingBytes(),
                'by' => $by,
            ]);
        }

        if (!$dryRun) {
            $usage = new CacheUsage($report->getRemainingBytes(), $report->getRemainingEntries(), time(), $report->getRemainingPendingEntries());
            if (!$usage->write(new FilesystemStorage(), $this->installation->getStateDirectory() . '/' . CacheUsage::FILE_NAME)) {
                $output->writeln('<comment>The cache size could not be written to var/ for /metrics.</comment>');
            }
        }

        return 0;
    }
}
