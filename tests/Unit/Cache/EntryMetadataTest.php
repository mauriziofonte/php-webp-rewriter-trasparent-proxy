<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Cache;

use Mfonte\ProxyCdn\Cache\EntryMetadata;
use Mfonte\ProxyCdn\Cache\Freshness;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Cache\EntryMetadata
 */
#[CoversClass(EntryMetadata::class)]
final class EntryMetadataTest extends TestCase
{
    private const NOW = 1790000000;

    public function testTheWindowsOfAnEntry(): void
    {
        $metadata = new EntryMetadata(self::NOW, 60, 30, 100, '"v1"', null);

        self::assertTrue($metadata->isFresh(self::NOW + 59));
        self::assertFalse($metadata->isFresh(self::NOW + 60));
        self::assertTrue($metadata->isWithinStaleWhileRevalidate(self::NOW + 89));
        self::assertFalse($metadata->isWithinStaleWhileRevalidate(self::NOW + 90));
        self::assertTrue($metadata->isWithinStaleIfError(self::NOW + 159));
        self::assertFalse($metadata->isWithinStaleIfError(self::NOW + 160));
        self::assertSame(10, $metadata->getRemainingLifetime(self::NOW + 50));
        self::assertSame(0, $metadata->getRemainingLifetime(self::NOW + 500));
        self::assertTrue((new EntryMetadata(self::NOW, 60, 0, null, null, null))->isWithinStaleIfError(self::NOW + 99999), 'no limit');
    }

    public function testFromAResponse(): void
    {
        $headers = ['cache-control' => 'max-age=60, stale-if-error=10', 'etag' => '"v1"', 'last-modified' => 'Tue, 01 Sep 2026 10:00:00 GMT'];

        $metadata = EntryMetadata::fromResponse($headers, Freshness::fromHeaders($headers, self::NOW), self::NOW, 45);

        self::assertSame(self::NOW, $metadata->getValidatedAt());
        self::assertSame(60, $metadata->getLifetime());
        self::assertSame(45, $metadata->getStaleWhileRevalidate(), 'STALE_WHILE_REVALIDATE when the origin sets none');
        self::assertSame(10, $metadata->getStaleIfError());
        self::assertSame(['If-None-Match' => '"v1"', 'If-Modified-Since' => 'Tue, 01 Sep 2026 10:00:00 GMT'], $metadata->conditionalHeaders());
    }

    public function testMustRevalidateServesNothingStale(): void
    {
        $headers = ['cache-control' => 'max-age=60, must-revalidate, stale-while-revalidate=99, stale-if-error=99'];

        $metadata = EntryMetadata::fromResponse($headers, Freshness::fromHeaders($headers, self::NOW), self::NOW, 45);

        self::assertSame(0, $metadata->getStaleWhileRevalidate());
        self::assertSame(0, $metadata->getStaleIfError());
    }

    public function testValidatorsThatCannotBeSentBackAreDropped(): void
    {
        $headers = ['etag' => "\"v1\"\r\nX-Injected: 1", 'last-modified' => str_repeat('a', 300)];

        $metadata = EntryMetadata::fromResponse($headers, Freshness::fromHeaders($headers, self::NOW), self::NOW, 0);

        self::assertSame([], $metadata->conditionalHeaders());
    }

    public function testRevalidationRenewsTheEntryAndKeepsWhatThe304DoesNotSay(): void
    {
        $metadata = new EntryMetadata(self::NOW, 60, 30, null, '"v1"', 'Tue, 01 Sep 2026 10:00:00 GMT');

        $kept = $metadata->revalidated([], self::NOW + 500, 0);
        $changed = $metadata->revalidated(['cache-control' => 'max-age=120', 'etag' => '"v2"'], self::NOW + 500, 0);

        self::assertSame(self::NOW + 500, $kept->getValidatedAt());
        self::assertSame(60, $kept->getLifetime());
        self::assertSame(30, $kept->getStaleWhileRevalidate());
        self::assertSame('"v1"', $kept->getEtag());
        self::assertSame(120, $changed->getLifetime());
        self::assertSame('"v2"', $changed->getEtag());
        self::assertSame('Tue, 01 Sep 2026 10:00:00 GMT', $changed->getLastModified());
    }

    public function testJsonRoundTrip(): void
    {
        $metadata = new EntryMetadata(self::NOW, 60, 30, 5, '"v1"', 'Tue, 01 Sep 2026 10:00:00 GMT');

        self::assertEquals($metadata, EntryMetadata::fromJson($metadata->toJson()));
        self::assertNull(EntryMetadata::fromJson('{"validated_at":"soon"}'));
        self::assertNull(EntryMetadata::fromJson('not json'));
    }
}
