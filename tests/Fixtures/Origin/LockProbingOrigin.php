<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Origin;

use Mfonte\ProxyCdn\Origin\OriginFetcher;
use Mfonte\ProxyCdn\Origin\OriginRequest;
use Mfonte\ProxyCdn\Origin\OriginResponse;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\CoalescerSeam;

/**
 * An origin that notes, for each fetch, whether the request fetching
 * holds the lock of the entry the coalescer recorded an outcome for
 * (CoalescerSeam::isOutcomeEntryLocked()), and then answers as $origin.
 */
final class LockProbingOrigin implements OriginFetcher
{
    /**
     * @var OriginFetcher
     */
    private $origin;

    /**
     * @var string
     */
    private $stateDirectory;

    /**
     * @var list<bool>
     */
    private $locked = [];

    public function __construct(OriginFetcher $origin, string $stateDirectory)
    {
        $this->origin = $origin;
        $this->stateDirectory = $stateDirectory;
    }

    public function fetch(OriginRequest $request): OriginResponse
    {
        $this->locked[] = CoalescerSeam::isOutcomeEntryLocked($this->stateDirectory);

        return $this->origin->fetch($request);
    }

    /**
     * Whether the lock was held, for each fetch so far.
     *
     * @return list<bool>
     */
    public function getLocked(): array
    {
        return $this->locked;
    }
}
