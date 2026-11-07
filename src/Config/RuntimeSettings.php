<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Config;

/**
 * Applies the process-wide PHP settings of the configuration, as the 2.x
 * bootstrap did: error display, default charset, time zone and locale.
 *
 * Only the standalone front controller calls it. A host application that
 * embeds ProxyKernel keeps its own settings.
 */
final class RuntimeSettings
{
    public function apply(Config $config): void
    {
        if ($config->isDebug()) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            ini_set('display_errors', '0');
        }

        ini_set('default_charset', $config->getCharset());

        $timezone = $config->getTimezone();
        if ($timezone !== null) {
            date_default_timezone_set($timezone);
        }

        setlocale(LC_TIME, $config->getLocale());
        setlocale(LC_MONETARY, $config->getLocale() . '.' . $config->getCharset());
    }
}
