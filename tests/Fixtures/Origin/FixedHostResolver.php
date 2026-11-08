<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Origin;

use Mfonte\ProxyCdn\Origin\HostResolver;

/**
 * Answers host lookups from a fixed table, without DNS.
 */
final class FixedHostResolver implements HostResolver
{
    /**
     * @var array<string, list<string>>
     */
    private $addresses;

    /**
     * @var list<string>
     */
    private $lookups = [];

    /**
     * @param array<string, list<string>> $addresses the addresses of each host name, in lower case
     */
    public function __construct(array $addresses)
    {
        $this->addresses = $addresses;
    }

    public function resolve(string $host): array
    {
        $this->lookups[] = $host;

        return $this->addresses[$host] ?? [];
    }

    /**
     * The host names looked up so far, oldest first.
     *
     * @return list<string>
     */
    public function getLookups(): array
    {
        return $this->lookups;
    }
}
