<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Functional\Console;

use Mfonte\ProxyCdn\Config\ConfigLoader;
use Mfonte\ProxyCdn\Console\CacheMigrate2xCommand;
use Mfonte\ProxyCdn\Console\Installation;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * cache:migrate-2x on the public/ directory 2.x left (risk L-5).
 *
 * @covers \Mfonte\ProxyCdn\Console\CacheMigrate2xCommand
 */
#[CoversClass(CacheMigrate2xCommand::class)]
final class CacheMigrate2xCommandTest extends TestCase
{
    private const ENV = "SOURCE_ORIGIN=\"https://origin.example.test\"\nPROXY_HOSTS=\"cdn.example.test,other.example.test=https://other-origin.example.test\"\nCACHEABLE_EXTENSIONS=\"css,js,jpg,png,webp\"\nUPSTREAM_VERIFY_TLS=true\nLOG_FILE=stderr\nLOG_LEVEL=emergency\n";

    private const HOST = 'public/_cache/cdn.example.test';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->directory->write('.env', self::ENV);
        $this->directory->write('public/index.php', '<?php');
        $this->directory->write('public/.htaccess', '# 3.x');
        $this->directory->write('.cached.env.php', '<?php return [];');
        $this->directory->write('public/css/site.css', 'css');
        $this->directory->write('public/images/hero.jpg', 'jpg');
        $this->directory->write('public/images/hero.webp', 'webp of jpg');
        $this->directory->write('public/images/photo.webp', 'origin webp');
        $this->directory->write('public/images/missing.png.404', '');
        $this->directory->write('public/js/down.js.netfail', '');
        $this->directory->write('public/_qs/0a1b/css/site.css', 'css?v=1');
        $this->directory->write('public/robots.txt', 'User-agent: *');
        $this->directory->write('public/_cache/cdn.example.test/js/app.js', 'new');
        $this->directory->write('public/js/app.js', 'old');
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testWithoutForceNothingChanges(): void
    {
        list($exitCode, $display) = $this->migrate([]);

        self::assertSame(0, $exitCode, $display);
        self::assertStringContainsString('3 entries would be moved, 1 WEBP variants would be kept, 5 files would be deleted, 1 files left in place.', $display);
        self::assertStringContainsString('left: ' . $this->directory->path('public/robots.txt') . ' (not a cache entry of 3.x)', $display);
        self::assertFileExists($this->directory->path('public/css/site.css'));
        self::assertFileExists($this->directory->path('.cached.env.php'));
    }

    /**
     * The entries move to the host of SOURCE_ORIGIN, the X.webp of 2.x
     * becomes the variant of its raster, the rest of 2.x goes, what 3.x
     * already holds wins, and the other files stay.
     */
    public function testForceMovesTheEntries(): void
    {
        list($exitCode, $display) = $this->migrate(['--force' => true]);

        self::assertSame(0, $exitCode, $display);
        self::assertSame('css', file_get_contents($this->directory->path(self::HOST . '/css/site.css')));
        self::assertSame('jpg', file_get_contents($this->directory->path(self::HOST . '/images/hero.jpg')));
        self::assertSame('webp of jpg', file_get_contents($this->directory->path(self::HOST . '/.webp/images/hero.jpg.webp')));
        self::assertSame('origin webp', file_get_contents($this->directory->path(self::HOST . '/images/photo.webp')));
        self::assertSame('new', file_get_contents($this->directory->path(self::HOST . '/js/app.js')));
        self::assertFileExists($this->directory->path('public/robots.txt'));
        self::assertFileExists($this->directory->path('public/index.php'));
        self::assertFileExists($this->directory->path('public/.htaccess'));
        self::assertFileDoesNotExist($this->directory->path('.cached.env.php'));
        self::assertSame(['.htaccess', '_cache', 'index.php', 'robots.txt'], array_values(array_diff((array) scandir($this->directory->path('public')), ['.', '..'])));
    }

    public function testWipeDeletesWithoutMoving(): void
    {
        list($exitCode, $display) = $this->migrate(['--wipe' => true, '--force' => true]);

        self::assertSame(0, $exitCode, $display);
        self::assertFileDoesNotExist($this->directory->path(self::HOST . '/css/site.css'));
        self::assertSame(['.htaccess', '_cache', 'index.php'], array_values(array_diff((array) scandir($this->directory->path('public')), ['.', '..'])));
        self::assertSame('new', file_get_contents($this->directory->path(self::HOST . '/js/app.js')));
    }

    /**
     * A link in public/ is neither followed nor moved.
     */
    public function testLinksAreLeftAlone(): void
    {
        $outside = $this->directory->write('outside/secret.css', 'secret');
        symlink($this->directory->path('outside'), $this->directory->path('public/linked'));
        symlink($outside, $this->directory->path('public/css/link.css'));

        $this->migrate(['--force' => true]);

        self::assertFileExists($outside);
        self::assertTrue(is_link($this->directory->path('public/linked')));
        self::assertTrue(is_link($this->directory->path('public/css/link.css')));
        self::assertFileDoesNotExist($this->directory->path(self::HOST . '/css/link.css'));
    }

    public function testTheHostMustBeKnown(): void
    {
        self::assertSame(1, $this->migrate(['--host' => 'evil.example.test'])[0]);
        $this->directory->write('.env', str_replace('other.example.test=https://other-origin.example.test', 'other.example.test', self::ENV));
        self::assertSame(1, $this->migrate([])[0]);
        self::assertSame(0, $this->migrate(['--host' => 'other.example.test', '--force' => true])[0]);
        self::assertFileExists($this->directory->path('public/_cache/other.example.test/css/site.css'));
    }

    /**
     * @param array<string, string|bool> $input
     *
     * @return array{int, string}
     */
    private function migrate(array $input): array
    {
        $installation = new Installation(new ConfigLoader($this->directory->path('.env'), null, static function (string $name): ?string {
            return null;
        }), $this->directory->path());
        $tester = new CommandTester(new CacheMigrate2xCommand($installation));
        $exitCode = $tester->execute($input);

        return [$exitCode, $tester->getDisplay()];
    }
}
