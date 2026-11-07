<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * Picks the transport: CurlTransport when ext-curl is usable, StreamTransport
 * otherwise (ext-curl missing, or its functions in disable_functions).
 */
final class HttpClientFactory
{
    public static function create(HttpClientOptions $options): HttpClient
    {
        if (CurlTransport::isAvailable()) {
            return new CurlTransport($options);
        }

        return new StreamTransport($options);
    }
}
