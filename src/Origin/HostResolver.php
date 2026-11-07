<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * Looks up the addresses of a host name, for the redirect guard
 * (RedirectGuard). Tests replace it to answer without DNS.
 */
interface HostResolver
{
    /**
     * The IPv4 and IPv6 addresses of $host; empty when it does not
     * resolve.
     *
     * @param string $host a host name, not an IP address
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
