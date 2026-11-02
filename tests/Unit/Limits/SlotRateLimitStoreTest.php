<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Limits;

use Mfonte\ProxyCdn\Exception\InvalidArgumentException;
use Mfonte\ProxyCdn\Limits\SlotRateLimitStore;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Limits\SlotRateLimitStore
 */
#[CoversClass(SlotRateLimitStore::class)]
final class SlotRateLimitStoreTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testValuesAreSharedThroughTheFiles(): void
    {
        $key = 'abc' . str_repeat('0', 29);
        self::assertTrue($this->store()->update($key, static function (?int $current): ?int {
            return $current === null ? 7 : null;
        }));

        $seen = 'unset';
        self::assertTrue($this->store()->update($key, static function (?int $current) use (&$seen): ?int {
            $seen = $current;

            return null;
        }));

        self::assertSame(7, $seen);
        self::assertSame(['slot-abc.state'], $this->files());
        self::assertSame(0700, fileperms($this->directory->path('ratelimit')) & 0777);
    }

    /**
     * However many keys arrive, a slot keeps ENTRIES_PER_SLOT of them,
     * dropping the one with the smallest value (the longest idle client).
     */
    public function testASlotKeepsABoundedNumberOfKeys(): void
    {
        $store = $this->store();
        for ($i = 0; $i < 100; ++$i) {
            $key = 'abc' . sprintf('%029x', $i);
            $value = $i === 0 ? 1000000 : $i;
            $store->update($key, static function (?int $current) use ($value): int {
                return $value;
            });
        }

        $lines = file($this->directory->path('ratelimit/slot-abc.state'), FILE_IGNORE_NEW_LINES) ?: [];
        self::assertCount(SlotRateLimitStore::ENTRIES_PER_SLOT, $lines);
        self::assertContains('abc' . sprintf('%029x', 0) . ' 1000000', $lines, 'the busiest key is kept');
        self::assertContains('abc' . sprintf('%029x', 99) . ' 99', $lines, 'the key just written is kept');
        self::assertSame(['slot-abc.state'], $this->files());
    }

    public function testKeysSpreadOverAtMostSlotsFiles(): void
    {
        $store = $this->store();
        for ($i = 0; $i < 300; ++$i) {
            $store->update(substr(hash('sha256', (string) $i), 0, 32), static function (?int $current): int {
                return 1;
            });
        }

        $files = $this->files();
        self::assertLessThanOrEqual(SlotRateLimitStore::SLOTS, count($files));
        foreach ($files as $file) {
            self::assertMatchesRegularExpression('/\Aslot-[0-9a-f]{3}\.state\z/', $file);
        }
    }

    public function testAnInvalidKeyIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->store()->update('../../etc/passwd', static function (?int $current): int {
            return 1;
        });
    }

    public function testADirectoryOthersCanWriteIsRefused(): void
    {
        mkdir($this->directory->path('ratelimit'), 0700);
        chmod($this->directory->path('ratelimit'), 0777);

        self::assertFalse($this->store()->update(str_repeat('a', 32), static function (?int $current): int {
            return 1;
        }));
    }

    public function testASymbolicLinkIsNotFollowed(): void
    {
        $this->directory->write('target', 'kept');
        mkdir($this->directory->path('ratelimit'), 0700);
        symlink($this->directory->path('target'), $this->directory->path('ratelimit/slot-aaa.state'));

        self::assertFalse($this->store()->update(str_repeat('a', 32), static function (?int $current): int {
            return 1;
        }));
        self::assertSame('kept', file_get_contents($this->directory->path('target')));
    }

    private function store(): SlotRateLimitStore
    {
        return new SlotRateLimitStore($this->directory->path('ratelimit'));
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = array_values(array_diff(scandir($this->directory->path('ratelimit')) ?: [], ['.', '..']));
        sort($files);

        return $files;
    }
}
