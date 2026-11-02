<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Config;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Config\CompiledConfigCache;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Config\CompiledConfigCache
 */
#[CoversClass(CompiledConfigCache::class)]
final class CompiledConfigCacheTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var string
     */
    private $file;

    /**
     * @var CompiledConfigCache
     */
    private $cache;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->file = $this->directory->path('.config.cache.php');
        $this->cache = new CompiledConfigCache($this->file, new FilesystemStorage());
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testNothingIsLoadedBeforeTheFirstSave(): void
    {
        self::assertNull($this->cache->load('fingerprint'));
    }

    public function testASavedConfigurationIsLoadedForItsFingerprint(): void
    {
        $config = TestConfig::create(['SOURCE_ORIGIN_SERVER' => '192.0.2.10:443', 'DEBUG' => 'true']);

        self::assertTrue($this->cache->save('fingerprint', $config));
        $loaded = $this->cache->load('fingerprint');

        self::assertNotNull($loaded);
        self::assertSame($config->toArray(), $loaded->toArray());
    }

    /**
     * The file holds WEBP_API_KEY and PURGE_KEYS, even when they come only
     * from the environment: only its owner may read it.
     */
    public function testTheFileIsReadableByItsOwnerOnly(): void
    {
        $config = TestConfig::create(['PURGE_KEYS' => '*=' . str_repeat('k', 32)]);

        self::assertTrue($this->cache->save('fingerprint', $config));

        self::assertSame(0600, fileperms($this->file) & 0777);
        self::assertStringContainsString(str_repeat('k', 32), (string) file_get_contents($this->file));
    }

    /**
     * A file others may read (written by an earlier version, 0644) is not
     * used, so that it is compiled again and replaced with mode 0600.
     */
    public function testAFileReadableByOthersIsNotLoaded(): void
    {
        $config = TestConfig::create();
        $this->cache->save('fingerprint', $config);
        chmod($this->file, 0644);

        self::assertNull($this->cache->load('fingerprint'));

        self::assertTrue($this->cache->save('fingerprint', $config));
        self::assertSame(0600, fileperms($this->file) & 0777);
        self::assertNotNull($this->cache->load('fingerprint'));
    }

    public function testAnotherFingerprintIsNotLoaded(): void
    {
        $this->cache->save('fingerprint', TestConfig::create());

        self::assertNull($this->cache->load('other'));
    }

    public function testTheFileIsPlainPhpThatReturnsTheValues(): void
    {
        $this->cache->save('fingerprint', TestConfig::create());

        $contents = (string) file_get_contents($this->file);
        self::assertStringStartsWith("<?php\n", $contents);
        $compiled = include $this->file;
        self::assertIsArray($compiled);
        self::assertSame('fingerprint', $compiled['fingerprint']);
        self::assertSame(TestConfig::create()->toArray(), $compiled['values']);
    }

    /**
     * var_export() keeps every value inside its literal: nothing written in
     * .env can become code.
     */
    public function testValuesCannotBreakOutOfTheFile(): void
    {
        $config = TestConfig::create(['WEBP_API_KEY' => "x'; echo 'owned'; //\n?><?php echo 1;"]);

        $this->cache->save('fingerprint', $config);
        $loaded = $this->cache->load('fingerprint');

        self::assertNotNull($loaded);
        self::assertSame("x'; echo 'owned'; //\n?><?php echo 1;", $loaded->getWebpApiKey());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideBrokenFiles(): array
    {
        return [
            'syntax error' => ['<?php return [;'],
            'no array' => ['<?php return 42;'],
            'no values' => ["<?php return ['fingerprint' => 'fingerprint'];"],
            'invalid values' => ["<?php return ['fingerprint' => 'fingerprint', 'values' => ['DEBUG' => true]];"],
            'throws' => ["<?php throw new \\RuntimeException('broken');"],
        ];
    }

    /**
     * @dataProvider provideBrokenFiles
     */
    #[DataProvider('provideBrokenFiles')]
    public function testABrokenFileIsIgnored(string $contents): void
    {
        file_put_contents($this->file, $contents);

        self::assertNull($this->cache->load('fingerprint'));
    }

    public function testSaveReportsAnUnwritableFile(): void
    {
        $this->directory->write('blocked', 'a file where a directory is expected');
        $cache = new CompiledConfigCache($this->directory->path('blocked/.config.cache.php'), new FilesystemStorage());

        self::assertFalse($cache->save('fingerprint', TestConfig::create()));
        self::assertNull($cache->load('fingerprint'));
    }
}
