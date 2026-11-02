<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cache;

/**
 * What the proxy keeps about an entry that it revalidates with the origin
 * (CacheLocation::managed()): when it was last validated, how long it stays
 * fresh, how long it may be served stale, and the validators the origin
 * sent (finding P-15, opportunity O-D). Immutable.
 *
 * Stored as JSON next to the entry (CacheLocation::getMetadataFile()).
 */
final class EntryMetadata
{
    /**
     * @var int
     */
    private $validatedAt;

    /**
     * @var int
     */
    private $lifetime;

    /**
     * @var int
     */
    private $staleWhileRevalidate;

    /**
     * @var int
     */
    private $staleIfError;

    /**
     * @var string|null
     */
    private $etag;

    /**
     * @var string|null
     */
    private $lastModified;

    /**
     * @param int         $validatedAt          Unix time the origin last confirmed the entry
     * @param int         $lifetime             seconds it stays fresh from then
     * @param int         $staleWhileRevalidate seconds it may then be served while it is revalidated in the background
     * @param int         $staleIfError         seconds it may then be served when the origin fails
     * @param string|null $etag                 the ETag of the origin, for If-None-Match
     * @param string|null $lastModified         the Last-Modified of the origin, for If-Modified-Since
     */
    public function __construct(int $validatedAt, int $lifetime, int $staleWhileRevalidate, int $staleIfError, ?string $etag, ?string $lastModified)
    {
        $this->validatedAt = $validatedAt;
        $this->lifetime = max(0, $lifetime);
        $this->staleWhileRevalidate = max(0, $staleWhileRevalidate);
        $this->staleIfError = max(0, $staleIfError);
        $this->etag = $etag;
        $this->lastModified = $lastModified;
    }

    /**
     * The metadata of an origin response, validated at $now.
     *
     * @param array<string, string> $headers                     the response headers, keyed by lower-case name
     * @param int                   $defaultStaleWhileRevalidate STALE_WHILE_REVALIDATE, when the origin sets none
     * @param int                   $defaultStaleIfError         STALE_IF_ERROR, when the origin sets none
     */
    public static function fromResponse(array $headers, Freshness $freshness, int $now, int $defaultStaleWhileRevalidate, int $defaultStaleIfError): self
    {
        $mustRevalidate = $freshness->mustRevalidate();

        return new self(
            $now,
            (int) $freshness->getLifetime(),
            $mustRevalidate ? 0 : ($freshness->getStaleWhileRevalidate() ?? $defaultStaleWhileRevalidate),
            $mustRevalidate ? 0 : ($freshness->getStaleIfError() ?? $defaultStaleIfError),
            self::validator($headers['etag'] ?? null),
            self::validator($headers['last-modified'] ?? null)
        );
    }

    /**
     * The metadata decoded from JSON; null when it is not valid metadata.
     */
    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_int($data['validated_at'] ?? null) || !is_int($data['lifetime'] ?? null) || !is_int($data['swr'] ?? null) || !is_int($data['sie'] ?? null)) {
            return null;
        }
        $etag = $data['etag'] ?? null;
        $lastModified = $data['last_modified'] ?? null;

        return new self(
            $data['validated_at'],
            $data['lifetime'],
            $data['swr'],
            $data['sie'],
            is_string($etag) ? $etag : null,
            is_string($lastModified) ? $lastModified : null
        );
    }

    public function toJson(): string
    {
        return (string) json_encode([
            'validated_at' => $this->validatedAt,
            'lifetime' => $this->lifetime,
            'swr' => $this->staleWhileRevalidate,
            'sie' => $this->staleIfError,
            'etag' => $this->etag,
            'last_modified' => $this->lastModified,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * The same metadata, validated again at $now by a 304 whose headers may
     * carry a new lifetime and new validators.
     *
     * @param array<string, string> $headers                     the headers of the 304, keyed by lower-case name
     * @param int                   $defaultStaleWhileRevalidate STALE_WHILE_REVALIDATE, when the origin sets none
     * @param int                   $defaultStaleIfError         STALE_IF_ERROR, when the origin sets none
     */
    public function revalidated(array $headers, int $now, int $defaultStaleWhileRevalidate, int $defaultStaleIfError): self
    {
        $freshness = Freshness::fromHeaders($headers, $now);
        if ($freshness->getLifetime() === null) {
            return new self($now, $this->lifetime, $this->staleWhileRevalidate, $this->staleIfError, self::validator($headers['etag'] ?? null) ?? $this->etag, self::validator($headers['last-modified'] ?? null) ?? $this->lastModified);
        }
        $updated = self::fromResponse($headers, $freshness, $now, $defaultStaleWhileRevalidate, $defaultStaleIfError);

        return new self($now, $updated->lifetime, $updated->staleWhileRevalidate, $updated->staleIfError, $updated->etag ?? $this->etag, $updated->lastModified ?? $this->lastModified);
    }

    /**
     * Whether the entry is fresh at $now.
     */
    public function isFresh(int $now): bool
    {
        return $now - $this->validatedAt < $this->lifetime;
    }

    /**
     * Whether the expired entry may be served at $now while it is
     * revalidated in the background.
     */
    public function isWithinStaleWhileRevalidate(int $now): bool
    {
        return $now - $this->validatedAt < $this->lifetime + $this->staleWhileRevalidate;
    }

    /**
     * Whether the expired entry may be served at $now because the origin
     * failed to revalidate it.
     */
    public function isWithinStaleIfError(int $now): bool
    {
        return $now - $this->validatedAt < $this->lifetime + $this->staleIfError;
    }

    /**
     * Seconds the entry stays fresh from $now, 0 once it has expired.
     */
    public function getRemainingLifetime(int $now): int
    {
        return max(0, $this->validatedAt + $this->lifetime - $now);
    }

    public function getValidatedAt(): int
    {
        return $this->validatedAt;
    }

    public function getLifetime(): int
    {
        return $this->lifetime;
    }

    public function getStaleWhileRevalidate(): int
    {
        return $this->staleWhileRevalidate;
    }

    public function getStaleIfError(): int
    {
        return $this->staleIfError;
    }

    public function getEtag(): ?string
    {
        return $this->etag;
    }

    public function getLastModified(): ?string
    {
        return $this->lastModified;
    }

    /**
     * The headers of a conditional request for the entry.
     *
     * @return array<string, string>
     */
    public function conditionalHeaders(): array
    {
        $headers = [];
        if ($this->etag !== null) {
            $headers['If-None-Match'] = $this->etag;
        }
        if ($this->lastModified !== null) {
            $headers['If-Modified-Since'] = $this->lastModified;
        }

        return $headers;
    }

    /**
     * A validator header value fit to be sent back: printable ASCII, at most
     * 256 bytes; null otherwise.
     */
    private static function validator(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value !== '' && strlen($value) <= 256 && preg_match('/\A[\x20-\x7e]+\z/', $value) === 1 ? $value : null;
    }
}
