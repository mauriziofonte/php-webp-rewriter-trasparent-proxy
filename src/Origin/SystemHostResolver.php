<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

/**
 * The resolver of the system: gethostbynamel() for IPv4, which reads the
 * hosts file too, and the AAAA records of dns_get_record() for IPv6. A
 * lookup that stalls is bounded by the system resolver (resolv.conf).
 */
final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];
        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            foreach ($ipv4 as $address) {
                $addresses[] = (string) $address;
            }
        }
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
