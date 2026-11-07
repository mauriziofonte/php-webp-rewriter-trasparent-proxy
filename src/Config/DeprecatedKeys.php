<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Config;

/**
 * The configuration keys of earlier releases that 3.x no longer reads,
 * each with the advice check-env prints when one is still set in the
 * environment or in .env (ConfigLoader::readSetKeys()).
 *
 * The list lives here, apart from ConfigLoader::KEYS, so that a release
 * that renames or drops a key adds one line. At 3.0 it holds the 2.x
 * conversion keys, which IMAGE_CONVERTER and the IMAGE_API_* keys replace
 * (ConfigLoader::REMOVED_KEYS names the replacement of each; the advice
 * says how to move the value).
 */
final class DeprecatedKeys
{
    /**
     * Advice keyed by deprecated key name.
     *
     * @var array<string, string>
     */
    public const KEYS = [
        'IMAGE_CONVERSION_MODE' => 'Use IMAGE_CONVERTER instead (see the Image conversion section of the README):'
            . ' "native" becomes "local" (a backend of the conversion library in this host, no cwebp),'
            . ' "api" becomes "remote" (the conversion API v2), "none" stays "none".',
        'WEBP_API_SERVER' => 'Use IMAGE_API_URL instead (see the Image conversion section of the README):'
            . ' the base URL of the conversion API v2, with IMAGE_CONVERTER="remote" and IMAGE_API_CA_FILE for a private CA.',
        'WEBP_API_KEY' => 'Use IMAGE_API_KEY instead (see the Image conversion section of the README):'
            . ' a key of 16 bytes or more of the conversion API v2, sent as "Authorization: Bearer".',
    ];

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
            $warnings[] = 'WARNING: ' . $key . " is deprecated: it was removed in 3.0 and is ignored.\n" . $deprecated[$key];
        }

        return $warnings;
    }
}
