<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Console;

use Mfonte\ProxyCdn\Cache\CacheLocation;
use Mfonte\ProxyCdn\Exception\InvalidConfigurationException;
use Mfonte\ProxyCdn\Http\RequestId;
use Mfonte\ProxyCdn\Image\FormatNegotiator;
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
 * (deleted when the file they mark has a URL of the cache, left
 * otherwise), and its configuration in .cached.env.php at the project root
 * (deleted). An entry whose URL passes the rules of live traffic
 * (ProxyUrls) is moved to public/_cache/<host>/<path>, where the web
 * server serves it until it is purged, as 2.x did; an X.webp next to
 * exactly one moved raster X.jpg (jpeg, png, gif, bmp) becomes its WEBP
 * variant, any other one is deleted (3.x converts again). An entry 3.x
 * already holds, published or pending, is deleted, and a file that is no
 * cache entry (a path refused, an extension that is not cacheable) is
 * left in place and listed. Links are never followed or moved.
 *
 * When IMAGE_CONVERTER makes variants, a raster entry is moved to the
 * pending tier of PRX-8 instead (CacheLocation::getPendingFile(), as a
 * miss stores it), with its X.webp staged as its WEBP variant when WEBP
 * is enabled (deleted otherwise): the web server does not serve it yet,
 * and the first request PHP answers makes its other variants and
 * publishes it with them. Its time is the time of the migration, from
 * which the entry waits for its variants (ProxyKernel::
 * PENDING_HOLD_SECONDS).
 *
 * Without --force nothing changes: the plan is printed, every path it
 * would move or delete and every path it leaves. --wipe deletes the 2.x
 * cache instead of moving it (the documented alternative: 3.x fills its
 * cache again from the origin): what is taken as 2.x cache only, never a
 * file of public/_cache, its pending tier and staged variants included,
 * that
 * is _qs/, the markers of entries, .cached.env.php, the entries and
 * their X.webp. An entry is told by its name alone: any file with a
 * cacheable extension and a path the rules of live traffic accept
 * (ProxyUrls) is taken as 2.x cache, in both modes, even in a directory
 * of another application; review the dry run, which lists every path.
 * Every other file stays: robots.txt, .well-known/, files with an
 * extension that is not cacheable.
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
        $option = $input->getOption('host');
        if (is_string($option)) {
            $host = $hosts->match($option);
        } else {
            $candidates = array_values(array_filter($hosts->getHosts(), static function (string $candidate) use ($hosts, $config): bool {
                return $hosts->originOf($candidate) === rtrim($config->getSourceOrigin(), '/');
            }));
            $host = count($candidates) === 1 ? $candidates[0] : null;
            if ($host === null && $wipe) {
                // A wipe moves nothing: any proxy host tells which files the rules of live traffic accept.
                $host = $hosts->getHosts()[0] ?? null;
            }
        }
        if ($host === null) {
            $output->writeln('<error>Name the proxy host that receives the 2.x cache with --host (one of PROXY_HOSTS).</error>');

            return 1;
        }

        $staged = FormatNegotiator::fromConfig($config)->getEnabledFormats();
        $plan = $this->plan(new ProxyUrls($config, $this->installation->getCacheDirectory()), $host, $wipe, $staged);
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
     * the reason). With $wipe, what would move is deleted instead.
     *
     * @param list<string> $staged the formats of the variants 3.x makes; a raster entry moves to the pending tier when there is one
     *
     * @return array{move: array<string, string>, variant: array<string, string>, delete: list<string>, leave: array<string, string>}
     */
    private function plan(ProxyUrls $proxyUrls, string $host, bool $wipe, array $staged): array
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
        // The entries of 2.x: the files whose URL the rules of live traffic accept.
        $entries = [];
        foreach (array_keys($files) as $relative) {
            $relative = (string) $relative;
            $file = $public . '/' . $relative;
            $marked = self::markedFile($relative);
            if ($marked !== null) {
                // A marker of 2.x only when the file it marks has a URL of the cache.
                if (self::target($proxyUrls, $host, $marked) !== null) {
                    $plan['delete'][] = $file;
                } else {
                    $plan['leave'][$file] = 'not a marker of a cache entry';
                }
                continue;
            }
            if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'webp') {
                $webps[] = $relative;
                continue;
            }
            $target = self::target($proxyUrls, $host, $relative);
            if ($target === null) {
                $plan['leave'][$file] = 'not a cache entry of 3.x';
                continue;
            }
            $entries[$relative] = true;
            if ($wipe || self::isHeld($target)) {
                $plan['delete'][] = $file;
            } else {
                $plan['move'][$file] = self::isStaged($relative, $staged) ? $target->getPendingFile() : $target->getFile();
            }
        }
        foreach ($webps as $relative) {
            $file = $public . '/' . $relative;
            $base = (string) substr($relative, 0, -strlen('.webp'));
            $rasters = [];
            $moved = [];
            foreach (self::RASTER_EXTENSIONS as $raster) {
                foreach ([$raster, strtoupper($raster)] as $candidate) {
                    if (isset($entries[$base . '.' . $candidate])) {
                        $rasters[] = $base . '.' . $candidate;
                    }
                    if (isset($plan['move'][$public . '/' . $base . '.' . $candidate])) {
                        $moved[] = $base . '.' . $candidate;
                    }
                }
            }
            $target = count($moved) === 1 ? self::target($proxyUrls, $host, $moved[0]) : null;
            if ($target !== null && !self::isStaged($moved[0], $staged)) {
                $plan['variant'][$file] = $target->getVariantFile(CacheLocation::FORMAT_WEBP);
                continue;
            }
            if ($target !== null && in_array(CacheLocation::FORMAT_WEBP, $staged, true)) {
                // Staged with its pending raster, published with it.
                $plan['variant'][$file] = $target->getPendingVariantFile(CacheLocation::FORMAT_WEBP);
                continue;
            }
            if ($rasters !== []) {
                // The WEBP conversion of a 2.x entry that does not move: 3.x converts again.
                $plan['delete'][] = $file;
                continue;
            }
            $own = self::target($proxyUrls, $host, $relative);
            if ($own === null) {
                $plan['leave'][$file] = 'not a cache entry of 3.x';
            } elseif ($wipe || self::isHeld($own)) {
                $plan['delete'][] = $file;
            } else {
                // An X.webp of the origin itself, with no entry raster next to it.
                $plan['move'][$file] = $own->getFile();
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

    /**
     * Whether 3.x holds the entry of $location already: published, or
     * pending (waiting for its variants).
     */
    private static function isHeld(CacheLocation $location): bool
    {
        return CacheTree::stat($location->getFile()) !== null || CacheTree::stat($location->getPendingFile()) !== null;
    }

    /**
     * Whether the 2.x entry public/$relative moves to the pending tier: a
     * raster image, when 3.x makes variants ($staged is not empty).
     *
     * @param list<string> $staged
     */
    private static function isStaged(string $relative, array $staged): bool
    {
        return $staged !== [] && in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), self::RASTER_EXTENSIONS, true);
    }

    /**
     * The file a name with a marker suffix marks (the name without the
     * suffix); null when the name has no marker suffix.
     */
    private static function markedFile(string $relative): ?string
    {
        foreach (self::LEGACY_MARKERS as $marker) {
            if (strlen($relative) > strlen($marker) && substr($relative, -strlen($marker)) === $marker) {
                return (string) substr($relative, 0, -strlen($marker));
            }
        }

        return null;
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
        foreach ($plan['move'] + $plan['variant'] as $file => $target) {
            $output->writeln('  move: ' . OutputFormatter::escape((string) $file) . ' -> ' . OutputFormatter::escape($target));
        }
        foreach ($plan['delete'] as $file) {
            $output->writeln('  delete: ' . OutputFormatter::escape($file));
        }
        foreach ($plan['leave'] as $file => $reason) {
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
                } elseif (strpos($target, DIRECTORY_SEPARATOR . '.pending' . DIRECTORY_SEPARATOR) !== false) {
                    // A pending entry waits for its variants from now.
                    @touch($target);
                }
            }
        }
        foreach ($plan['delete'] as $file) {
            if (CacheTree::stat($file) !== null && !@unlink($file)) {
                ++$failed;
            }
        }
        $public = $this->installation->getPublicDirectory();
        $emptied = [];
        foreach (array_merge(array_keys($plan['move']), array_keys($plan['variant']), $plan['delete']) as $file) {
            $emptied[dirname((string) $file)] = true;
        }
        foreach (array_keys($emptied) as $directory) {
            self::pruneEmpty((string) $directory, $public);
        }

        return $failed;
    }

    /**
     * Removes $directory and then its parents while they are empty, up to
     * $public (excluded): only the directories the migration emptied go,
     * never one through a link.
     */
    private static function pruneEmpty(string $directory, string $public): void
    {
        while (strpos($directory, $public . '/') === 0) {
            $stat = @lstat($directory);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || !@rmdir($directory)) {
                return;
            }
            $directory = dirname($directory);
        }
    }
}
