<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Console;

use Mfonte\ProxyCdn\Config\ConfigLoader;
use Mfonte\ProxyCdn\Console\CacheWarmupCommand;
use Mfonte\ProxyCdn\Console\Installation;
use Mfonte\ProxyCdn\Console\ProxyUrls;
use Mfonte\ProxyCdn\Tests\Fixtures\FakeOrigin\FakeOriginServer;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\ProxyServer;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * cache:warmup against the proxy served by PHP's built-in server, in
 * front of the fake origin: the misses are fetched through the proxy, the
 * URLs outside the rules of live traffic are left out.
 *
 * @covers \Mfonte\ProxyCdn\Console\CacheWarmupCommand
 * @covers \Mfonte\ProxyCdn\Console\ProxyUrls
 */
#[CoversClass(CacheWarmupCommand::class)]
#[CoversClass(ProxyUrls::class)]
final class CacheWarmupCommandTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var FakeOriginServer
     */
    private $origin;

    /**
     * @var ProxyServer
     */
    private $proxy;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        mkdir($this->directory->path('origin'));
        $this->origin = FakeOriginServer::start($this->directory->path('origin'), 'public, max-age=7776000');
        $this->proxy = ProxyServer::start($this->directory->path('app'), ['PATH' => '/usr/local/bin:/usr/bin:/bin'], implode("\n", [
            'SOURCE_ORIGIN="' . $this->origin->getBaseUrl() . '"',
            'PROXY_HOSTS="cdn.example.test"',
            'CACHEABLE_EXTENSIONS="css,js,png"',
            'UPSTREAM_VERIFY_TLS=false',
            'UPSTREAM_TIMEOUT=10',
            'LOG_LEVEL=emergency',
            '',
        ]));
    }

    protected function tear_down(): void
    {
        $this->proxy->stop();
        $this->origin->stop();
        $this->directory->remove();
        parent::tear_down();
    }

    /**
     * A sitemap of origin URLs warms their entries on the proxy host; the
     * page, the foreign URL and the duplicate are left out; a second run
     * only hits.
     */
    public function testASitemapWarmsTheCacheThroughTheProxy(): void
    {
        $origin = $this->origin->getBaseUrl();
        $sitemap = $this->directory->write('sitemap.xml', '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'
            . '<url><loc>' . $origin . '/index.html</loc><image:image><image:loc>' . $origin . '/images/gradient.png</image:loc></image:image></url>'
            . '<url><loc>' . $origin . '/css/site.css?v=1&amp;utm_source=x</loc></url>'
            . '<url><loc>' . $origin . '/css/site.css?v=1</loc></url>'
            . '<url><loc>https://cdn.example.test/js/app.js</loc></url>'
            . '<url><loc>https://evil.example.test/js/app.js</loc></url>'
            . '</urlset>');

        list($exitCode, $display) = $this->warmup([$sitemap]);

        self::assertSame(0, $exitCode, $display);
        self::assertStringContainsString('6 URLs read, 3 requests to send, 2 skipped.', $display);
        self::assertStringContainsString('index.html: the extension is not in CACHEABLE_EXTENSIONS', $display);
        self::assertStringContainsString('evil.example.test/js/app.js: neither a proxy host nor an origin', $display);
        self::assertStringContainsString('  200 MISS: 3', $display);
        $cache = $this->proxy->getProjectDirectory() . '/public/_cache/cdn.example.test';
        self::assertFileExists($cache . '/images/gradient.png');
        self::assertFileExists($cache . '/.q/v=1/css/site.css');
        self::assertFileExists($cache . '/js/app.js');
        self::assertSame(1, $this->origin->countRequestsFor('/css/site.css'));

        list(, $again) = $this->warmup([$sitemap]);
        self::assertStringContainsString('  200 HIT: 3', $again);
        self::assertSame(1, $this->origin->countRequestsFor('/css/site.css'));
    }

    /**
     * A list of URLs, with a missing file: the failure sets the exit code.
     */
    public function testAListWithAFailure(): void
    {
        $list = $this->directory->write('urls.txt', "# assets\nhttps://cdn.example.test/js/app.js\n\nhttps://cdn.example.test/js/missing.js\n");

        list($exitCode, $display) = $this->warmup([$list, '--concurrency' => '2']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('2 URLs read, 2 requests to send, 0 skipped.', $display);
        self::assertStringContainsString('✗ http://cdn.example.test/js/missing.js: 404', $display);
    }

    /**
     * A sitemap given by URL must be on an origin (or a proxy host); a
     * sitemap index is followed one level down.
     */
    public function testASitemapIndexOnTheOrigin(): void
    {
        $origin = $this->origin->getBaseUrl();
        $this->origin->setRule('/sitemap.xml', 200, null, '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>' . $origin . '/sitemap-1.xml</loc></sitemap></sitemapindex>', ['Content-Type' => 'application/xml']);
        $this->origin->setRule('/sitemap-1.xml', 200, null, '<urlset><url><loc>' . $origin . '/js/app.js</loc></url></urlset>', ['Content-Type' => 'application/xml']);

        list($exitCode, $display) = $this->warmup([$origin . '/sitemap.xml']);
        list($foreignExitCode, $foreign) = $this->warmup(['http://169.254.169.254/latest/meta-data/']);

        self::assertSame(0, $exitCode, $display);
        self::assertStringContainsString('1 URLs read, 1 requests to send, 0 skipped.', $display);
        self::assertFileExists($this->proxy->getProjectDirectory() . '/public/_cache/cdn.example.test/js/app.js');
        self::assertSame(1, $foreignExitCode);
        self::assertStringContainsString('is neither on a proxy host nor on an origin', $foreign);
    }

    public function testInvalidOptionsAreRefused(): void
    {
        $list = $this->directory->write('urls.txt', "https://cdn.example.test/js/app.js\n");

        self::assertSame(1, $this->warmup([$list, '--concurrency' => '0'])[0]);
        self::assertSame(1, $this->warmup([$list, '--concurrency' => '33'])[0]);
        self::assertSame(1, $this->warmup([$list, '--scheme' => 'ftp'])[0]);
        self::assertSame(1, $this->warmup([$list, '--via' => 'not an address'])[0]);
        self::assertSame(1, $this->warmup([$this->directory->path('missing.txt')])[0]);
        self::assertSame(0, $this->origin->countRequestsFor('/js/app.js'));
    }

    /**
     * @param array<int|string, string> $input the source first, then the options
     *
     * @return array{int, string}
     */
    private function warmup(array $input): array
    {
        $arguments = ['source' => $input[0], '--scheme' => 'http', '--via' => '127.0.0.1:' . $this->proxy->getPort()];
        unset($input[0]);
        $installation = new Installation(new ConfigLoader($this->proxy->getProjectDirectory() . '/.env', null, static function (string $name): ?string {
            return null;
        }), $this->proxy->getProjectDirectory());
        $tester = new CommandTester(new CacheWarmupCommand($installation));
        $exitCode = $tester->execute($input + $arguments);

        return [$exitCode, $tester->getDisplay()];
    }
}
