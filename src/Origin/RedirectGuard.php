<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

use Mfonte\ProxyCdn\Net\IpRange;

/**
 * Keeps followed redirects away from private networks (SSRF): a redirect
 * to another origin than the one of the first request is followed only
 * when every address of its host is public, and the connection is then
 * pinned to the address that was checked, so that a second DNS answer
 * cannot swap in a private one (DNS rebinding).
 *
 * The origin of the first request (scheme, host and port) is trusted as
 * configured: it may live on a private network, and its redirects to
 * itself are followed. Every other target is refused when one of its
 * addresses is in NON_PUBLIC_RANGES (loopback, private, link-local,
 * carrier-grade NAT, documentation, benchmarking, multicast, reserved,
 * and the IPv6 forms that embed an IPv4 address of their own), or when
 * its host does not resolve. HttpClientOptions::withPrivateRedirectTargets()
 * turns the check off, for tests on the loopback interface.
 *
 * @internal used by the transports of this namespace
 */
final class RedirectGuard
{
    /**
     * The ranges no followed redirect may lead to.
     */
    public const NON_PUBLIC_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32',
        '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /**
     * Whether the redirect from the first request $firstUrl to $targetUrl
     * may be followed; the address the connection must use, when the
     * target is another origin.
     *
     * The address is the checked IP to connect to, null for the origin of
     * the first request; outcome, code and error describe a refusal.
     *
     * @return array{allowed: bool, address: string|null, outcome: string, code: int, error: string}
     */
    public static function check(HttpClientOptions $options, string $firstUrl, string $targetUrl): array
    {
        $allowed = ['allowed' => true, 'address' => null, 'outcome' => '', 'code' => 0, 'error' => ''];
        if ($options->allowsPrivateRedirectTargets() || RedirectTarget::isSameOrigin($firstUrl, $targetUrl)) {
            return $allowed;
        }

        $host = strtolower(trim((string) parse_url($targetUrl, PHP_URL_HOST), '[]'));
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $options->getHostResolver()->resolve($host);
        if ($addresses === []) {
            return [
                'allowed' => false,
                'address' => null,
                'outcome' => HttpResponse::OUTCOME_DNS,
                'code' => StreamTransport::ERROR_DNS,
                'error' => 'Could not resolve the redirect host ' . $host,
            ];
        }
        $ranges = IpRange::listFromString(implode(',', self::NON_PUBLIC_RANGES));
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false || IpRange::anyContains($ranges, $address)) {
                return [
                    'allowed' => false,
                    'address' => null,
                    'outcome' => HttpResponse::OUTCOME_REDIRECT,
                    'code' => 0,
                    'error' => 'Redirect to a non-public address refused: ' . $host,
                ];
            }
        }
        $allowed['address'] = $addresses[0];

        return $allowed;
    }
}
