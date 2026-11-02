<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Console;

use Mfonte\ProxyCdn\Cache\CacheLocation;
use Mfonte\ProxyCdn\Exception\InvalidConfigurationException;
use Mfonte\ProxyCdn\Http\RequestId;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * "cache:migrate-2x" (risk L-5): moves the cache 2.x left in public/ to
 * the layout of 3.x, which never reads or serves it.
 *
 * 2.x stored the entries of its single origin at public/<path>, their
 * WEBP conversions next to them as X.webp, the query-string entries in
 * public/_qs/<hash>/ (a hash of the query string, which cannot be mapped
 * back: deleted), the .404 and .netfail markers next to the files
 * (deleted), and its configuration in .cached.env.php at the project root
 * (deleted). An entry whose URL passes the rules of live traffic
 * (ProxyUrls) is moved to public/_cache/<host>/<path>, where the web
 * server serves it until it is purged, as 2.x did; an X.webp next to
 * exactly one moved raster X.jpg (jpeg, png, gif, bmp) becomes its WEBP
 * variant, any other one is deleted (3.x converts again). An entry 3.x
 * already holds is deleted, and a file that is no cache entry (a path
 * refused, an extension that is not cacheable) is left in place and
 * listed. Links are never followed or moved.
 *
 * Without --force nothing changes: the plan is printed. --wipe deletes
 * the 2.x cache instead of moving it (the documented alternative: 3.x
 * fills its cache again from the origin).
 */
final class CacheMigrate2xCommand extends Command
{
    public const NAME = 'cache:migrate-2x';

    /**
     * The query-string directory of 2.x, in public/.
     */
    public const LEGACY_QUERY_DIRECTORY = '_qs';

    /**
     * The negative-cache markers of 2.x.
     */
    public const LEGACY_MARKERS = ['.404', '.netfail'];

    /**
     * The raster extensions an X.webp of 2.x was converted from.
     */
    public const RASTER_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'bmp'];

    private const MAX_LISTED = 20;

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
            ->setDescription('Move the 2.x cache in public/ to the 3.x layout, or delete it')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'The proxy host whose cache receives the entries (default: the only host of SOURCE_ORIGIN)')
            ->addOption('wipe', null, InputOption::VALUE_NONE, 'Delete the 2.x cache instead of moving it')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Apply the plan; without it nothing changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->installation->loadConfig();
        } catch (InvalidConfigurationException $exception) {
            $output->writeln('<error>The configuration is invalid; run check-env.</error>');

            return 1;
        }
        $wipe = $input->getOption('wipe') === true;
        $force = $input->getOption('force') === true;
        $hosts = $config->getHostAllowlist();
        $host = null;
        if (!$wipe) {
            $option = $input->getOption('host');
            if (is_string($option)) {
                $host = $hosts->match($option);
            } else {
                $candidates = array_values(array_filter($hosts->getHosts(), static function (string $candidate) use ($hosts, $config): bool {
                    return $hosts->originOf($candidate) === rtrim($config->getSourceOrigin(), '/');
                }));
                $host = count($candidates) === 1 ? $candidates[0] : null;
            }
            if ($host === null) {
                $output->writeln('<error>Name the proxy host that receives the 2.x cache with --host (one of PROXY_HOSTS), or use --wipe.</error>');

                return 1;
            }
        }

        $plan = $this->plan($host === null ? null : new ProxyUrls($config, $this->installation->getCacheDirectory()), $host);
        $this->report($plan, $output, $force);
        if (!$force) {
            $output->writeln('Nothing changed. Run again with --force to apply.');

            return 0;
        }

        $failed = $this->apply($plan);
        $this->installation->createLogger($config, RequestId::generate())->notice('2.x cache migrated', [
            'moved' => count($plan['move']) + count($plan['variant']),
            'deleted' => count($plan['delete']),
            'left' => count($plan['leave']),
            'failed' => $failed,
        ]);
        if ($failed > 0) {
            $output->writeln('<error>' . $failed . ' files could not be moved or deleted.</error>');

            return 1;
        }
        $output->writeln('<info>Done.</info>');

        return 0;
    }

    /**
     * What to do with each file of the 2.x cache: move (to a target),
     * variant (to the WEBP variant of a moved entry), delete, leave (with
     * the reason).
     *
     * @return array{move: array<string, string>, variant: array<string, string>, delete: list<string>, leave: array<string, string>}
     */
    private function plan(?ProxyUrls $proxyUrls, ?string $host): array
    {
        $root = $this->installation->getRoot();
        $public = $this->installation->getPublicDirectory();
        $plan = ['move' => [], 'variant' => [], 'delete' => [], 'leave' => []];
        if (CacheTree::stat($root . '/' . OperationsCheck::LEGACY_CONFIG_CACHE) !== null) {
            $plan['delete'][] = $root . '/' . OperationsCheck::LEGACY_CONFIG_CACHE;
        }

        $enter = static function (string $relative, int $depth): bool {
            return $depth > 1 || !in_array($relative, OperationsCheck::PUBLIC_ENTRIES, true);
        };
        $webps = [];
        $files = [];
        foreach (CacheTree::walk($public, $enter) as $relative => $stat) {
            $relative = (string) $relative;
            if (strpos($relative, '/') === false && in_array($relative, OperationsCheck::PUBLIC_ENTRIES, true)) {
                continue;
            }
            if (strpos($relative, self::LEGACY_QUERY_DIRECTORY . '/') === 0) {
                $plan['delete'][] = $public . '/' . $relative;
                continue;
            }
            if ($stat[0] !== CacheTree::TYPE_FILE) {
                $plan['leave'][$public . '/' . $relative] = 'not a regular file';
                continue;
            }
            $files[$relative] = true;
        }
        foreach (array_keys($files) as $relative) {
            $relative = (string) $relative;
            $file = $public . '/' . $relative;
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            if (self::isMarker($relative) || $proxyUrls === null || $host === null) {
                $plan['delete'][] = $file;
                continue;
            }
            if ($extension === 'webp') {
                $webps[] = $relative;
                continue;
            }
            $target = self::target($proxyUrls, $host, $relative);
            if ($target === null) {
                $plan['leave'][$file] = 'not a cache entry of 3.x';
            } elseif (CacheTree::stat($target->getFile()) !== null) {
                $plan['delete'][] = $file;
            } else {
                $plan['move'][$file] = $target->getFile();
            }
        }
        foreach ($webps as $relative) {
            $file = $public . '/' . $relative;
            $base = (string) substr($relative, 0, -strlen('.webp'));
            $rasters = [];
            foreach (self::RASTER_EXTENSIONS as $raster) {
                foreach ([$raster, strtoupper($raster)] as $candidate) {
                    if (isset($plan['move'][$public . '/' . $base . '.' . $candidate])) {
                        $rasters[] = $base . '.' . $candidate;
                    }
                }
            }
            $hasRaster = false;
            foreach (self::RASTER_EXTENSIONS as $raster) {
                $hasRaster = $hasRaster || isset($files[$base . '.' . $raster]) || isset($files[$base . '.' . strtoupper($raster)]);
            }
            $target = count($rasters) === 1 && $proxyUrls !== null && $host !== null ? self::target($proxyUrls, $host, $rasters[0]) : null;
            if ($target !== null) {
                $plan['variant'][$file] = $target->getVariantFile(CacheLocation::FORMAT_WEBP);
                continue;
            }
            $own = $proxyUrls !== null && $host !== null && !$hasRaster ? self::target($proxyUrls, $host, $relative) : null;
            if ($own !== null && CacheTree::stat($own->getFile()) === null) {
                // An X.webp of the origin itself, with no raster next to it.
                $plan['move'][$file] = $own->getFile();
            } else {
                $plan['delete'][] = $file;
            }
        }

        return $plan;
    }

    /**
     * The 3.x location of the 2.x entry public/$relative; null when its URL
     * is refused.
     */
    private static function target(ProxyUrls $proxyUrls, string $host, string $relative): ?CacheLocation
    {
        $path = '/' . implode('/', array_map('rawurlencode', explode('/', $relative)));
        $located = $proxyUrls->locate('https://' . $host . $path);

        return is_string($located) ? null : $located[2];
    }

    private static function isMarker(string $relative): bool
    {
        foreach (self::LEGACY_MARKERS as $marker) {
            if (substr($relative, -strlen($marker)) === $marker) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{move: array<string, string>, variant: array<string, string>, delete: list<string>, leave: array<string, string>} $plan
     */
    private function report(array $plan, OutputInterface $output, bool $force): void
    {
        $verb = $force ? '' : 'would be ';
        $output->writeln(sprintf(
            '%d entries %smoved, %d WEBP variants %skept, %d files %sdeleted, %d files left in place.',
            count($plan['move']),
            $verb,
            count($plan['variant']),
            $verb,
            count($plan['delete']),
            $verb,
            count($plan['leave'])
        ));
        foreach (array_slice($plan['leave'], 0, self::MAX_LISTED, true) as $file => $reason) {
            $output->writeln('  left: ' . OutputFormatter::escape((string) $file) . ' (' . $reason . ')');
        }
    }

    /**
     * Applies the plan; returns the number of failures.
     *
     * @param array{move: array<string, string>, variant: array<string, string>, delete: list<string>, leave: array<string, string>} $plan
     */
    private function apply(array $plan): int
    {
        $failed = 0;
        foreach ([$plan['move'], $plan['variant']] as $moves) {
            foreach ($moves as $source => $target) {
                $directory = dirname($target);
                if ((!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) || !@rename($source, $target)) {
                    ++$failed;
                }
            }
        }
        foreach ($plan['delete'] as $file) {
            if (CacheTree::stat($file) !== null && !@unlink($file)) {
                ++$failed;
            }
        }
        $public = $this->installation->getPublicDirectory();
        foreach ((array) @scandir($public) as $name) {
            if (is_string($name) && $name !== '.' && $name !== '..' && !in_array($name, OperationsCheck::PUBLIC_ENTRIES, true)) {
                self::pruneEmpty($public . '/' . $name);
            }
        }

        return $failed;
    }

    /**
     * Removes the empty directories of a tree, never through a link.
     */
    private static function pruneEmpty(string $directory): void
    {
        $stat = @lstat($directory);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
            return;
        }
        foreach ((array) @scandir($directory) as $name) {
            if (is_string($name) && $name !== '.' && $name !== '..') {
                self::pruneEmpty($directory . '/' . $name);
            }
        }
        @rmdir($directory);
    }
}
