<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image;

use Mfonte\ProxyCdn\Cache\CacheStorage;

/**
 * Remembers, for a while, that the variant of an entry could not be made,
 * so that the requests PHP serves do not convert it again on every hit
 * (finding P-10: 2.x converted, and failed, on every query-string hit).
 *
 * The markers live in a flat directory of the state directory, outside
 * public/, in a bounded number of slots, as NegativeCache keeps its
 * markers: the first SLOT_HEX_DIGITS hexadecimal digits of the SHA-256 of
 * the variant file name the slot, so at most 4096 marker files exist
 * whatever the clients ask. A slot holds the time the marker expires and
 * the full hash of its variant; two variants that share a slot replace
 * each other's marker, which only costs one more conversion attempt.
 *
 * A marker does not stop the conversion of a newly stored entry: the
 * origin may have sent another image (VariantConverter forgets it then).
 */
final class ConversionFailures
{
    private const SLOT_HEX_DIGITS = 3;

    private const SLOT_SUFFIX = '.failed';

    /**
     * @var CacheStorage
     */
    private $storage;

    /**
     * @var string
     */
    private $directory;

    /**
     * @param string $directory the state directory of the markers, outside the document root
     */
    public function __construct(CacheStorage $storage, string $directory)
    {
        $this->storage = $storage;
        $this->directory = rtrim($directory, '/');
    }

    /**
     * Remembers for $ttl seconds that $variantFile could not be made.
     */
    public function remember(string $variantFile, int $ttl): void
    {
        if ($ttl > 0) {
            $this->storage->write($this->slotOf($variantFile), (time() + $ttl) . ' ' . hash('sha256', $variantFile) . "\n");
        }
    }

    /**
     * Whether a marker of $variantFile is still fresh.
     *
     * @phpstan-impure
     */
    public function isRemembered(string $variantFile): bool
    {
        $expiresAt = $this->expiryOf($variantFile);

        return $expiresAt !== null && $expiresAt > time();
    }

    /**
     * Forgets the marker of $variantFile; the marker of another variant
     * that shares the slot is kept.
     */
    public function forget(string $variantFile): void
    {
        if ($this->expiryOf($variantFile) !== null) {
            $this->storage->delete($this->slotOf($variantFile));
        }
    }

    /**
     * The expiry of the marker the slot holds for $variantFile; null when it
     * holds none, or the marker of another variant.
     *
     * @phpstan-impure
     */
    private function expiryOf(string $variantFile): ?int
    {
        $contents = $this->storage->read($this->slotOf($variantFile));
        if ($contents === null || preg_match('/\A([0-9]{1,12}) ([0-9a-f]{64})\n\z/', $contents, $match) !== 1 || $match[2] !== hash('sha256', $variantFile)) {
            return null;
        }

        return (int) $match[1];
    }

    private function slotOf(string $variantFile): string
    {
        return $this->directory . '/' . substr(hash('sha256', $variantFile), 0, self::SLOT_HEX_DIGITS) . self::SLOT_SUFFIX;
    }
}
