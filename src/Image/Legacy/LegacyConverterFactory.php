<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image\Legacy;

use Mfonte\ProxyCdn\Cache\CacheStorage;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Image\WebpConverter;
use Psr\Log\LoggerInterface;

/**
 * The 2.x converter of IMAGE_CONVERSION_MODE.
 *
 * @internal removed with the 2.x converters in PRX-8
 */
final class LegacyConverterFactory
{
    /**
     * @return WebpConverter|null null when IMAGE_CONVERSION_MODE is "none"
     */
    public static function fromConfig(Config $config, CacheStorage $storage, LoggerInterface $logger): ?WebpConverter
    {
        switch ($config->getImageConversionMode()) {
            case Config::CONVERSION_NATIVE:
                return new CwebpConverter($logger);
            case Config::CONVERSION_API:
                return new ConversionApiConverter($config->getWebpApiServer(), $config->getWebpApiKey(), $storage, $logger);
            default:
                return null;
        }
    }
}
