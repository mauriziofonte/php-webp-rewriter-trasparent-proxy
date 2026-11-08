<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image;

use Mfonte\ProxyCdn\Cache\CacheStorage;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface as LibraryException;

/**
 * Remembers what the /healthz of the conversion API said, so that the
 * capabilities are discovered once per TTL rather than on every
 * conversion: the Capabilities of the API for SUCCESS_TTL seconds, or that
 * they could not be read, for the TTL given by RemoteConverter (so that an
 * unreachable API is not asked on every request either).
 *
 * One small file per API URL in a directory of the state directory,
 * written atomically by CacheStorage with mode 0600. A file that cannot
 * be read back, or whose capabilities Capabilities::fromArray() refuses,
 * counts as missing.
 */
final class CapabilityCache
{
    /**
     * Seconds the capabilities of a successful discovery are kept.
     */
    public const SUCCESS_TTL = 300;

    /**
     * @var CacheStorage
     */
    private $storage;

    /**
     * @var string
     */
    private $file;

    /**
     * @param string $directory the directory of the cache files, outside the document root
     * @param string $apiUrl    the base URL of the API, which names the file
     */
    public function __construct(CacheStorage $storage, string $directory, string $apiUrl)
    {
        $this->storage = $storage;
        $this->file = rtrim($directory, '/') . '/capabilities-' . substr(hash('sha256', $apiUrl), 0, 16) . '.json';
    }

    /**
     * What is remembered at $now: the capabilities, false when the last
     * discovery failed, null when nothing is remembered (or no longer).
     *
     * @return Capabilities|false|null
     *
     * @phpstan-impure
     */
    public function load(int $now)
    {
        $contents = $this->storage->read($this->file);
        $data = $contents === null ? null : json_decode($contents, true);
        if (!is_array($data) || !is_int($data['expires_at'] ?? null) || $data['expires_at'] <= $now) {
            return null;
        }
        $capabilities = $data['capabilities'] ?? null;
        if ($capabilities === false) {
            return false;
        }
        if (!is_array($capabilities)) {
            return null;
        }
        try {
            return Capabilities::fromArray($capabilities);
        } catch (LibraryException $exception) {
            return null;
        }
    }

    /**
     * Remembers the capabilities of a successful discovery for SUCCESS_TTL
     * seconds.
     */
    public function storeCapabilities(Capabilities $capabilities, int $now): void
    {
        $this->write(['expires_at' => $now + self::SUCCESS_TTL, 'capabilities' => $capabilities->toArray()]);
    }

    /**
     * Remembers for $ttl seconds that the discovery failed.
     */
    public function storeUnavailable(int $now, int $ttl): void
    {
        if ($ttl > 0) {
            $this->write(['expires_at' => $now + $ttl, 'capabilities' => false]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): void
    {
        $json = json_encode($data);
        if ($json !== false) {
            $this->storage->write($this->file, $json, 0600);
        }
    }
}
