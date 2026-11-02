<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Config;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Config\CompiledConfigCache;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Config\ConfigLoader;
use Mfonte\ProxyCdn\Exception\InvalidConfigurationException;
use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Config\ConfigLoader
 */
#[CoversClass(ConfigLoader::class)]
final class ConfigLoaderTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var array<string, string>
     */
    private $environment = [];

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->environment = [];
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testValuesAreReadFromTheEnvFile(): void
    {
        $this->writeEnvFile([
            'SOURCE_ORIGIN' => '"https://origin.example.test/"',
            'CACHEABLE_EXTENSIONS' => '"CSS, png"',
            'CACHE_EXPIRY' => '600',
            'DEBUG' => 'TRUE',
            'TIMEZONE' => 'Europe/Rome',
        ]);

        $config = $this->loader()->load();

        self::assertSame('https://origin.example.test', $config->getSourceOrigin());
        self::assertSame(['css', 'png'], $config->getCacheableExtensions());
        self::assertSame(600, $config->getCacheExpiry());
        self::assertTrue($config->isDebug());
        self::assertSame('Europe/Rome', $config->getTimezone());
    }

    public function testEnvironmentWinsOverTheEnvFile(): void
    {
        $this->writeEnvFile([
            'SOURCE_ORIGIN' => 'https://file.example.test',
            'CACHEABLE_EXTENSIONS' => 'css',
            'CACHE_EXPIRY' => '600',
        ]);
        $this->environment = ['PROXY_HOSTS' => 'cdn.example.test', 'SOURCE_ORIGIN' => 'https://environment.example.test', 'CACHE_EXPIRY' => ''];

        $config = $this->loader()->load();

        self::assertSame('https://environment.example.test', $config->getSourceOrigin());
        self::assertSame(['css'], $config->getCacheableExtensions());
        self::assertSame(600, $config->getCacheExpiry(), 'an empty environment variable does not hide .env');
    }

    public function testTheEnvFileIsOptional(): void
    {
        $this->environment = ['PROXY_HOSTS' => 'cdn.example.test', 'SOURCE_ORIGIN' => 'https://environment.example.test', 'CACHEABLE_EXTENSIONS' => 'js'];

        $loader = $this->loader();
        $config = $loader->load();

        self::assertFalse($loader->hasEnvFile());
        self::assertSame('https://environment.example.test', $config->getSourceOrigin());
        self::assertSame(['js'], $config->getCacheableExtensions());
    }

    public function testWithoutEnvFilePathOnlyTheEnvironmentIsRead(): void
    {
        $this->writeEnvFile(['SOURCE_ORIGIN' => 'https://file.example.test', 'CACHEABLE_EXTENSIONS' => 'css']);
        $loader = new ConfigLoader(null, null, $this->environmentReader());

        self::assertFalse($loader->hasEnvFile());
        $this->expectException(InvalidConfigurationException::class);
        $loader->load();
    }

    public function testDefaultsApplyToUnsetKeys(): void
    {
        $this->writeEnvFile([
            'SOURCE_ORIGIN' => 'https://origin.example.test',
            'CACHEABLE_EXTENSIONS' => 'css',
            'CHARSET' => 'null',
            'LOCALE' => '',
            'CACHE_EXPIRY' => 'null',
            'IMAGE_API_KEY' => 'null',
        ]);

        $config = $this->loader()->load();

        self::assertFalse($config->isDebug());
        self::assertNull($config->getSourceOriginServer());
        self::assertSame('UTF-8', $config->getCharset());
        self::assertSame('UTC', $config->getTimezone());
        self::assertSame('en_US', $config->getLocale());
        self::assertSame(86400, $config->getCacheExpiry());
        self::assertSame(300, $config->getNegativeCacheTtl());
        self::assertSame(3600, $config->getCacheRevalidateBelow());
        self::assertSame(60, $config->getStaleWhileRevalidate());
        self::assertSame(86400, $config->getStaleIfError());
        self::assertSame(30, $config->getNetworkErrorTtl());
        self::assertSame(5, $config->getCircuitBreakerThreshold());
        self::assertSame(30, $config->getUpstreamTimeout());
        self::assertSame(5, $config->getUpstreamConnectTimeout());
        self::assertTrue($config->shouldVerifyUpstreamTls(), 'TLS is verified by default (risk L-6)');
        self::assertFalse($config->shouldFollowUpstreamRedirects());
        self::assertSame(HttpClientOptions::DEFAULT_MAX_BODY_SIZE, $config->getUpstreamMaxSize());
        self::assertNull($config->getUpstreamUserAgent());
        self::assertSame(['v', 'ver', 'version', 'id'], $config->getQueryStringAllowlist());
        self::assertSame('ignore', $config->getQueryStringMode());
        self::assertSame([], $config->getTrustedProxies());
        self::assertSame(Config::CONVERSION_NONE, $config->getImageConversionMode());
        self::assertNull($config->getWebpApiServer());
        self::assertNull($config->getWebpApiKey());
        self::assertSame(Config::CONVERTER_NONE, $config->getImageConverter());
        self::assertSame(['avif', 'webp'], $config->getImageFormats());
        self::assertSame(55, $config->getImageConversionTimeout());
        self::assertNull($config->getImageApiUrl());
        self::assertNull($config->getImageApiKey());
        self::assertNull($config->getImageApiCaFile());
        self::assertFalse($config->shouldFallBackToLocalConversion());
        self::assertSame([], $config->getResizeWidths());
        self::assertNull($config->getResizeKey());
    }

    /**
     * The origin certificate is verified unless UPSTREAM_VERIFY_TLS says
     * otherwise; 2.x did not verify by default (risk L-6).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testTlsIsVerifiedByDefaultL6(): void
    {
        $config = TestConfig::loader()->loadFromArray(['PROXY_HOSTS' => 'cdn.example.test', 'SOURCE_ORIGIN' => 'https://origin.example.test', 'CACHEABLE_EXTENSIONS' => 'css']);

        self::assertTrue($config->shouldVerifyUpstreamTls());
        self::assertFalse(TestConfig::create(['UPSTREAM_VERIFY_TLS' => 'false'])->shouldVerifyUpstreamTls());
    }

    public function testTrustedProxiesAreLoaded(): void
    {
        $config = TestConfig::create(['TRUSTED_PROXIES' => '10.1.2.3/8, 2001:DB8::1']);

        self::assertSame(['10.0.0.0/8', '2001:db8::1/128'], array_map('strval', $config->getTrustedProxies()));
    }

    public function testInvalidValuesAreRejectedWithEveryError(): void
    {
        $this->writeEnvFile(['SOURCE_ORIGIN' => 'origin', 'CACHE_EXPIRY' => 'soon']);

        try {
            $this->loader()->load();
            self::fail('Invalid values must be rejected');
        } catch (InvalidConfigurationException $exception) {
            self::assertSame([
                "SOURCE_ORIGIN must be a valid URL (got: 'origin')",
                'CACHEABLE_EXTENSIONS must be set (comma-separated list of file extensions)',
                "CACHE_EXPIRY must be a non-negative integer (got: 'soon')",
            ], $exception->getErrors());
        }
    }

    public function testUnparsableEnvFileIsRejected(): void
    {
        $this->directory->write('.env', "SOURCE_ORIGIN=https://origin.example.test with spaces\nPROXY_HOSTS=cdn.example.test\nCACHEABLE_EXTENSIONS=css\n");

        try {
            $this->loader()->load();
            self::fail('An unparsable .env must be rejected');
        } catch (InvalidConfigurationException $exception) {
            self::assertCount(1, $exception->getErrors());
            self::assertStringStartsWith('The .env file cannot be parsed: ', $exception->getErrors()[0]);
        }
    }

    public function testTheEnvFileNeverChangesTheEnvironment(): void
    {
        $this->writeEnvFile(['SOURCE_ORIGIN' => 'https://origin.example.test', 'CACHEABLE_EXTENSIONS' => 'css']);

        $this->loader()->load();

        self::assertFalse(getenv('CACHEABLE_EXTENSIONS'));
        self::assertArrayNotHasKey('CACHEABLE_EXTENSIONS', $_ENV);
        self::assertArrayNotHasKey('CACHEABLE_EXTENSIONS', $_SERVER);
    }

    public function testReadValuesNormalisesEveryKey(): void
    {
        $this->writeEnvFile(['SOURCE_ORIGIN' => 'https://origin.example.test', 'LOCALE' => 'null', 'UNRELATED' => 'x']);
        $this->environment = ['CHARSET' => 'ISO-8859-1'];

        $values = $this->loader()->readValues();

        self::assertSame(ConfigLoader::KEYS, array_keys($values));
        self::assertSame('https://origin.example.test', $values['SOURCE_ORIGIN']);
        self::assertSame('ISO-8859-1', $values['CHARSET']);
        self::assertNull($values['LOCALE']);
        self::assertNull($values['DEBUG']);
    }

    public function testReadEnvironmentValuesListsTheNonEmptyKeys(): void
    {
        $this->environment = ['SOURCE_ORIGIN' => 'https://origin.example.test', 'DEBUG' => '', 'UNRELATED' => 'x'];

        self::assertSame(['SOURCE_ORIGIN' => 'https://origin.example.test'], $this->loader()->readEnvironmentValues());
    }

    public function testHasEnvFile(): void
    {
        self::assertFalse($this->loader()->hasEnvFile());

        $this->writeEnvFile([]);

        self::assertTrue($this->loader()->hasEnvFile());
    }

    public function testLoadFromArrayNeedsNoSource(): void
    {
        $config = $this->loader()->loadFromArray(TestConfig::rawValues([
            'SOURCE_ORIGIN' => 'https://origin.example.test///',
            'UPSTREAM_FOLLOW_REDIRECTS' => 'True',
            'IMAGE_API_KEY' => '',
        ]));

        self::assertSame('https://origin.example.test', $config->getSourceOrigin());
        self::assertTrue($config->shouldFollowUpstreamRedirects());
        self::assertNull($config->getImageApiKey());
    }

    public function testLoadFromArrayTreatsMissingKeysAsUnset(): void
    {
        $config = $this->loader()->loadFromArray(['PROXY_HOSTS' => 'cdn.example.test', 'SOURCE_ORIGIN' => 'https://origin.example.test', 'CACHEABLE_EXTENSIONS' => 'png']);

        self::assertSame(['png'], $config->getCacheableExtensions());
        self::assertSame(86400, $config->getCacheExpiry());
    }

    public function testTheDefaultEnvironmentReaderUsesGetenv(): void
    {
        putenv('SOURCE_ORIGIN=https://getenv.example.test');

        try {
            $values = (new ConfigLoader(null))->readEnvironmentValues();
        } finally {
            putenv('SOURCE_ORIGIN');
        }

        self::assertSame('https://getenv.example.test', $values['SOURCE_ORIGIN'] ?? null);
    }

    public function testTheCompiledConfigurationIsWrittenAndReused(): void
    {
        $this->writeEnvFile(['SOURCE_ORIGIN' => 'https://origin.example.test', 'CACHEABLE_EXTENSIONS' => 'css']);
        $compiledFile = $this->directory->path('.config.cache.php');

        self::assertSame(['css'], $this->loader(true)->load()->getCacheableExtensions());
        self::assertFileExists($compiledFile);

        // The compiled values are used as long as the sources are unchanged:
        // edit them in place, keeping the fingerprint.
        $compiled = include $compiledFile;
        self::assertIsArray($compiled);
        self::assertIsArray($compiled['values']);
        $compiled['values']['CACHEABLE_EXTENSIONS'] = ['from-compiled-file'];
        file_put_contents($compiledFile, '<?php return ' . var_export($compiled, true) . ';');

        self::assertSame(['from-compiled-file'], $this->loader(true)->load()->getCacheableExtensions());
    }

    /**
     * A changed .env takes effect on the next load (finding P-20, which
     * 2.x shows at the HTTP level in ConfigurationSourcesTest).
     */
    public function testAChangedEnvFileInvalidatesTheCompiledConfigurationP20(): void
    {
        $this->writeEnvFile(['SOURCE_ORIGIN' => 'https://origin.example.test', 'CACHEABLE_EXTENSIONS' => 'css']);
        self::assertSame(['css'], $this->loader(true)->load()->getCacheableExtensions());

        $this->writeEnvFile(['SOURCE_ORIGIN' => 'https://origin.example.test', 'CACHEABLE_EXTENSIONS' => 'css,js']);

        self::assertSame(['css', 'js'], $this->loader(true)->load()->getCacheableExtensions());
    }

    public function testAnEditKeepingSizeAndTimeInvalidatesTheCompiledConfiguration(): void
    {
        $envFile = $this->writeEnvFile(['SOURCE_ORIGIN' => 'https://origin.example.test', 'CACHEABLE_EXTENSIONS' => 'css']);
        touch($envFile, 1700000000);
        self::assertSame(['css'], $this->loader(true)->load()->getCacheableExtensions());

        $this->writeEnvFile(['SOURCE_ORIGIN' => 'https://origin.example.test', 'CACHEABLE_EXTENSIONS' => 'png']);
        touch($envFile, 1700000000);

        self::assertSame(['png'], $this->loader(true)->load()->getCacheableExtensions());
    }

    public function testAChangedEnvironmentInvalidatesTheCompiledConfiguration(): void
    {
        $this->writeEnvFile(['SOURCE_ORIGIN' => 'https://origin.example.test', 'CACHEABLE_EXTENSIONS' => 'css']);
        $this->loader(true)->load();
        $this->tamperWithCompiledExtensions($this->directory->path('.config.cache.php'));

        $this->environment = ['CACHE_EXPIRY' => '60'];
        $config = $this->loader(true)->load();

        self::assertSame(['css'], $config->getCacheableExtensions());
        self::assertSame(60, $config->getCacheExpiry());
    }

    public function testARemovedEnvFileInvalidatesTheCompiledConfiguration(): void
    {
        $envFile = $this->writeEnvFile(['SOURCE_ORIGIN' => 'https://origin.example.test', 'CACHEABLE_EXTENSIONS' => 'css']);
        $this->loader(true)->load();
        self::assertTrue(unlink($envFile));

        $this->expectException(InvalidConfigurationException::class);
        $this->loader(true)->load();
    }

    public function testAnUnwritableCompiledFileIsNotFatal(): void
    {
        $this->writeEnvFile(['SOURCE_ORIGIN' => 'https://origin.example.test', 'CACHEABLE_EXTENSIONS' => 'css']);
        $this->directory->write('blocked', 'a file where a directory is expected');
        $cache = new CompiledConfigCache($this->directory->path('blocked/.config.cache.php'), new FilesystemStorage());
        $loader = new ConfigLoader($this->directory->path('.env'), $cache, $this->environmentReader());

        self::assertSame(['css'], $loader->load()->getCacheableExtensions());
        self::assertSame(['css'], $loader->load()->getCacheableExtensions());
    }

    public function testInvalidValuesAreNeverCompiled(): void
    {
        $this->writeEnvFile(['SOURCE_ORIGIN' => 'https://origin.example.test']);

        try {
            $this->loader(true)->load();
            self::fail('Invalid values must be rejected');
        } catch (InvalidConfigurationException $exception) {
            self::assertFileDoesNotExist($this->directory->path('.config.cache.php'));
        }
    }

    private function loader(bool $compiled = false): ConfigLoader
    {
        $cache = $compiled
            ? new CompiledConfigCache($this->directory->path('.config.cache.php'), new FilesystemStorage())
            : null;

        return new ConfigLoader($this->directory->path('.env'), $cache, $this->environmentReader());
    }

    /**
     * @return callable(string): ?string
     */
    private function environmentReader(): callable
    {
        return function (string $name): ?string {
            return $this->environment[$name] ?? null;
        };
    }

    /**
     * @param array<string, string> $values
     *
     * @return string the path of the file
     */
    private function writeEnvFile(array $values): string
    {
        if (isset($values['SOURCE_ORIGIN']) && !isset($values['PROXY_HOSTS'])) {
            $values['PROXY_HOSTS'] = 'cdn.example.test';
        }
        $lines = '';
        foreach ($values as $name => $value) {
            $lines .= $name . '=' . $value . "\n";
        }
        $file = $this->directory->write('.env', $lines);
        clearstatcache();

        return $file;
    }

    private function tamperWithCompiledExtensions(string $compiledFile): void
    {
        $compiled = include $compiledFile;
        self::assertIsArray($compiled);
        self::assertIsArray($compiled['values']);
        $compiled['values']['CACHEABLE_EXTENSIONS'] = ['tampered'];
        file_put_contents($compiledFile, '<?php return ' . var_export($compiled, true) . ';');
    }
}
