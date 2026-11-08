<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image;

use Mfonte\ProxyCdn\Cache\CacheLocation;
use Mfonte\ProxyCdn\Cache\CacheStorage;

/**
 * Remembers, for a while, that the variant of an entry could not be made,
 * so that the requests PHP serves do not convert it again on every hit
 * (finding P-10: 2.x converted, and failed, on every query-string hit).
 *
 * The markers live in a flat directory of the state directory, outside
 * public/, in a bounded number of slots, as NegativeCache keeps its
 * markers: the first SLOT_HEX_DIGITS hexadecimal digits of the SHA-256 of
 * the variant file name the slot, within the format of the variant, so
 * at most 4096 marker files per format exist whatever the clients ask.
 * A slot holds the time the marker expires and the full hash of its
 * variant, and whether the failure is final (the image or the converter
 * will fail the same way whenever asked: see isFinal()); two variants
 * that share a slot replace each other's marker, which only costs one
 * more conversion attempt. The variants of one entry never share one:
 * an entry is settled (VariantConverter::isSettled()) once every one of
 * them is made or failed for good, which two variants replacing each
 * other's final marker would never let it be, keeping it pending for
 * ProxyKernel::PENDING_HOLD_SECONDS and converting it again on every hit.
 *
 * A marker does not stop the conversion of a newly stored entry: the
 * origin may have sent another image (VariantConverter forgets it then).
 */
final class ConversionFailures
{
    private const SLOT_HEX_DIGITS = 3;

    private const SLOT_SUFFIX = '.failed';

    private const FINAL = 'final';

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
     * Remembers for $ttl seconds that $variantFile could not be made;
     * $final when asking again would fail the same way (a refusal of the
     * image, a format the converter does not make), not because the
     * converter could not convert now.
     */
    public function remember(string $variantFile, int $ttl, bool $final = false): void
    {
        if ($ttl > 0) {
            $this->storage->write($this->slotOf($variantFile), (time() + $ttl) . ' ' . hash('sha256', $variantFile) . ($final ? ' ' . self::FINAL : '') . "\n");
        }
    }

    /**
     * Whether a marker of $variantFile is still fresh.
     *
     * @phpstan-impure
     */
    public function isRemembered(string $variantFile): bool
    {
        $marker = $this->markerOf($variantFile);

        return $marker !== null && $marker[0] > time();
    }

    /**
     * Whether a fresh marker of $variantFile says that the failure is
     * final (see remember()).
     *
     * @phpstan-impure
     */
    public function isFinal(string $variantFile): bool
    {
        $marker = $this->markerOf($variantFile);

        return $marker !== null && $marker[0] > time() && $marker[1];
    }

    /**
     * Forgets the marker of $variantFile; the marker of another variant
     * that shares the slot is kept.
     */
    public function forget(string $variantFile): void
    {
        if ($this->markerOf($variantFile) !== null) {
            $this->storage->delete($this->slotOf($variantFile));
        }
    }

    /**
     * The expiry of the marker the slot holds for $variantFile, and
     * whether it is final; null when it holds none, or the marker of
     * another variant.
     *
     * @return array{int, bool}|null
     *
     * @phpstan-impure
     */
    private function markerOf(string $variantFile): ?array
    {
        $contents = $this->storage->read($this->slotOf($variantFile));
        if ($contents === null || preg_match('/\A([0-9]{1,12}) ([0-9a-f]{64})( ' . self::FINAL . ')?\n\z/', $contents, $match) !== 1 || $match[2] !== hash('sha256', $variantFile)) {
            return null;
        }

        return [(int) $match[1], isset($match[3])];
    }

    /**
     * The slot of $variantFile: one of the slots of its format (the
     * extension of a variant file, CacheLocation::getVariantFile()).
     */
    private function slotOf(string $variantFile): string
    {
        $extension = pathinfo($variantFile, PATHINFO_EXTENSION);
        $format = in_array($extension, CacheLocation::FORMATS, true) ? '.' . $extension : '';

        return $this->directory . '/' . substr(hash('sha256', $variantFile), 0, self::SLOT_HEX_DIGITS) . $format . self::SLOT_SUFFIX;
    }
}
