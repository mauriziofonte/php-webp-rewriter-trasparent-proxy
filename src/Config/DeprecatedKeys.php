<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Config;

/**
 * The configuration keys of earlier releases that 3.x no longer reads,
 * each with the advice check-env prints when one is still set in the
 * environment or in .env (ConfigLoader::readSetKeys()).
 *
 * The list lives here, apart from ConfigLoader::KEYS, so that a release
 * that renames or drops a key adds one line: the image conversion keys
 * belong to PRX-8, which adds its renamed keys here. At 3.0.0 every 2.x
 * key is still read, so the list is empty.
 */
final class DeprecatedKeys
{
    /**
     * Advice keyed by deprecated key name.
     *
     * @var array<string, string>
     */
    public const KEYS = [];

    /**
     * The warning of each key of $deprecated that $loader finds set.
     *
     * @param array<string, string> $deprecated advice keyed by key name; KEYS by default
     *
     * @throws \Mfonte\ProxyCdn\Exception\InvalidConfigurationException when .env cannot be parsed
     *
     * @return list<string>
     */
    public static function warnings(ConfigLoader $loader, array $deprecated = self::KEYS): array
    {
        $warnings = [];
        foreach ($loader->readSetKeys(array_map('strval', array_keys($deprecated))) as $key) {
            $warnings[] = 'WARNING: ' . $key . " is no longer read and has no effect.\n" . $deprecated[$key];
        }

        return $warnings;
    }
}
