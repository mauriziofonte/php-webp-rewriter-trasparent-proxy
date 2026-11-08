<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Http;

/**
 * Reads the Accept and Accept-Encoding request headers (RFC 9110,
 * sections 12.5.1 and 12.5.3) the way the Apache and nginx configurations
 * of the proxy do, so that PHP and the web server pick the same file.
 *
 * A token is accepted when the header lists it by name with a quality above
 * zero. Wildcards ("*\/*", "image/*", "*") never count: old user agents send
 * "*\/*" and cannot decode a WEBP image (2.x made the same choice).
 */
final class AcceptHeader
{
    private function __construct()
    {
    }

    /**
     * Whether $header lists $token ("image/webp", "br"...) with a quality
     * above zero, in any letter case.
     *
     * @param string $header the header value, empty when absent
     */
    public static function accepts(string $header, string $token): bool
    {
        $token = strtolower($token);
        foreach (explode(',', $header) as $element) {
            $parameters = explode(';', $element);
            if (strtolower(trim($parameters[0])) !== $token) {
                continue;
            }
            $quality = 1.0;
            foreach (array_slice($parameters, 1) as $parameter) {
                $pair = explode('=', $parameter, 2);
                if (count($pair) === 2 && strtolower(trim($pair[0])) === 'q') {
                    $quality = preg_match('/\A[01](?:\.[0-9]{0,3})?\z/', trim($pair[1])) === 1 ? (float) trim($pair[1]) : 0.0;
                }
            }

            return $quality > 0.0;
        }

        return false;
    }
}
