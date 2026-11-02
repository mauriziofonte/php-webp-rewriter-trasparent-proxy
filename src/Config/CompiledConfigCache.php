<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Config;

use Mfonte\ProxyCdn\Cache\CacheStorage;
use Mfonte\ProxyCdn\Exception\InvalidConfigurationException;

/**
 * The compiled configuration: a PHP file that returns the normalised values
 * together with the fingerprint of the sources they were read from.
 *
 * ConfigLoader recomputes the fingerprint on every load and uses the file
 * only while it matches, so a change of .env or of the environment takes
 * effect on the next request (finding P-20). The file is written atomically
 * with var_export(), so no value can break out of its literal, and OPcache
 * is told to forget the previous version. It holds the secrets of the
 * configuration, so it is written with mode 0600, from its temporary file
 * on; one that others may read is never used.
 */
final class CompiledConfigCache
{
    /**
     * @var string
     */
    private $file;

    /**
     * @var CacheStorage
     */
    private $storage;

    /**
     * @param string $file absolute path of the compiled file, outside the document root or denied by it
     */
    public function __construct(string $file, CacheStorage $storage)
    {
        $this->file = $file;
        $this->storage = $storage;
    }

    /**
     * The configuration stored for $fingerprint, or null when there is none,
     * it was compiled from other sources, or it cannot be read.
     */
    public function load(string $fingerprint): ?Config
    {
        if (!$this->storage->isFile($this->file) || self::isReadableByOthers($this->file)) {
            return null;
        }
        $compiled = self::includeFile($this->file);
        if (!is_array($compiled) || ($compiled['fingerprint'] ?? null) !== $fingerprint || !is_array($compiled['values'] ?? null)) {
            return null;
        }

        try {
            return Config::fromArray($compiled['values']);
        } catch (InvalidConfigurationException $exception) {
            return null;
        }
    }

    /**
     * Stores $config for $fingerprint.
     *
     * @return bool false when the file cannot be written; the configuration
     *              is then compiled again on the next load
     */
    public function save(string $fingerprint, Config $config): bool
    {
        $contents = "<?php\n\n"
            . "// Compiled configuration of mfonte/proxy-cdn, rewritten whenever .env or the\n"
            . "// environment changes. Do not edit.\n\n"
            . 'return ' . var_export(['fingerprint' => $fingerprint, 'values' => $config->toArray()], true) . ";\n";

        // The values include the secrets (WEBP_API_KEY, PURGE_KEYS), even
        // those that only the environment holds: owner only.
        if (!$this->storage->write($this->file, $contents, 0600)) {
            return false;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->file, true);
        }

        return true;
    }

    /**
     * Whether $file grants any permission to its group or to other users,
     * as the files of earlier versions did (0644): it is then compiled
     * again, and replaced by a file of the owner only.
     */
    private static function isReadableByOthers(string $file): bool
    {
        // Not from the stat cache, which PHP 7 does not clear on chmod().
        clearstatcache(true, $file);
        $permissions = @fileperms($file);

        return DIRECTORY_SEPARATOR === '/' && $permissions !== false && ($permissions & 0077) !== 0;
    }

    /**
     * Includes $file in a scope of its own.
     *
     * @return mixed what the file returns; null when it cannot be compiled or throws
     */
    private static function includeFile(string $file)
    {
        try {
            return (static function (string $compiledFile) {
                return @include $compiledFile;
            })($file);
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
