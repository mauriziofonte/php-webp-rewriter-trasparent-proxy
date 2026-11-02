<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image;

use Mfonte\ProxyCdn\Cache\CacheLocation;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Http\AcceptHeader;
use Mfonte\ProxyCdn\Http\MediaTypes;

/**
 * Chooses the AVIF or WEBP variant of a raster entry from the Accept
 * header of the client (opportunity O-A), the way the web server
 * configurations choose it on a hit (public/.htaccess,
 * config/nginx/proxy-cdn-maps.conf), so that PHP and the web server answer
 * a client alike:
 *
 * - a format is accepted when Accept lists its media type ("image/avif",
 *   "image/webp") with a quality other than 0 (AcceptHeader); wildcards
 *   such as "image/*" and "*\/*" do not count, since a client that sends
 *   them may not decode the format;
 * - AVIF is preferred to WEBP whatever the qualities, as the web server
 *   cannot weigh them;
 * - a variant is served only when it exists on disk; the original
 *   otherwise.
 *
 * It also tells which formats are made of each raster entry
 * (IMAGE_FORMATS): the set is bounded (risk L-14), and every made variant
 * lives where the web server looks for it (CacheLocation). A variant that
 * exists but is no longer made (IMAGE_FORMATS changed) is still served,
 * since the web server serves it too.
 */
final class FormatNegotiator
{
    /**
     * @var list<string>
     */
    private $enabled;

    /**
     * @param list<string> $enabled the formats made of each raster entry, among CacheLocation::FORMATS
     */
    public function __construct(array $enabled)
    {
        $this->enabled = array_values(array_intersect(CacheLocation::FORMATS, $enabled));
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->getImageConverter() === Config::CONVERTER_NONE ? [] : $config->getImageFormats());
    }

    /**
     * The variant formats the client accepts, most preferred first.
     *
     * @param string $accept the Accept header of the request, empty when absent
     *
     * @return list<string> among CacheLocation::FORMATS
     */
    public function acceptedFormats(string $accept): array
    {
        $formats = [];
        foreach (CacheLocation::FORMATS as $format) {
            if (AcceptHeader::accepts($accept, MediaTypes::of($format))) {
                $formats[] = $format;
            }
        }

        return $formats;
    }

    /**
     * The formats made of each raster entry, most preferred first; empty
     * when no converter is configured.
     *
     * @return list<string>
     */
    public function getEnabledFormats(): array
    {
        return $this->enabled;
    }

    /**
     * Whether variants in $format are made.
     */
    public function isEnabled(string $format): bool
    {
        return in_array($format, $this->enabled, true);
    }
}
