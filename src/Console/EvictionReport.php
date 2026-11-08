<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Console;

/**
 * What a run of CacheEvictor found and removed.
 */
final class EvictionReport
{
    /**
     * @var int
     */
    private $entries;

    /**
     * @var int
     */
    private $bytes;

    /**
     * @var int
     */
    private $links;

    /**
     * @var int
     */
    private $evictedBytes = 0;

    /**
     * @var list<string>
     */
    private $evicted = [];

    /**
     * @var int
     */
    private $kept = 0;

    /**
     * @var int
     */
    private $pendingEntries = 0;

    /**
     * @var int
     */
    private $evictedPending = 0;

    /**
     * @param int $entries the sets found
     * @param int $bytes   their bytes
     * @param int $links   the symbolic links seen and left alone
     */
    public function __construct(int $entries, int $bytes, int $links)
    {
        $this->entries = $entries;
        $this->bytes = $bytes;
        $this->links = $links;
    }

    /**
     * Records a set found that waits for its variants (.pending/).
     */
    public function pending(): void
    {
        ++$this->pendingEntries;
    }

    /**
     * Records a set removed (or that a dry run would remove).
     *
     * @param bool $pending whether the set waited for its variants
     */
    public function evicted(string $key, int $bytes, bool $pending = false): void
    {
        $this->evicted[] = $key;
        $this->evictedBytes += $bytes;
        if ($pending) {
            ++$this->evictedPending;
        }
    }

    /**
     * Records a set kept because it was used during the run.
     */
    public function keep(): void
    {
        ++$this->kept;
    }

    public function getEntries(): int
    {
        return $this->entries;
    }

    public function getBytes(): int
    {
        return $this->bytes;
    }

    public function getLinks(): int
    {
        return $this->links;
    }

    /**
     * The keys of the sets removed, least recently used first.
     *
     * @return list<string>
     */
    public function getEvicted(): array
    {
        return $this->evicted;
    }

    public function getEvictedBytes(): int
    {
        return $this->evictedBytes;
    }

    public function getKept(): int
    {
        return $this->kept;
    }

    public function getRemainingEntries(): int
    {
        return $this->entries - count($this->evicted);
    }

    public function getRemainingBytes(): int
    {
        return $this->bytes - $this->evictedBytes;
    }

    /**
     * The sets left that wait for their variants.
     */
    public function getRemainingPendingEntries(): int
    {
        return $this->pendingEntries - $this->evictedPending;
    }
}
