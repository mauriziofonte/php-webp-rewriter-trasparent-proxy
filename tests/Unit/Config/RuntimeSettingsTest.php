<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Config;

use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Config\RuntimeSettings;
use Mfonte\ProxyCdn\Tests\Fixtures\Config\TestConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The settings are process-wide: every test restores them.
 *
 * @covers \Mfonte\ProxyCdn\Config\RuntimeSettings
 */
#[CoversClass(RuntimeSettings::class)]
final class RuntimeSettingsTest extends TestCase
{
    /**
     * @var int
     */
    private $errorReporting;

    /**
     * @var string|false
     */
    private $displayErrors;

    /**
     * @var string|false
     */
    private $defaultCharset;

    /**
     * @var string
     */
    private $timezone;

    /**
     * @var string|false
     */
    private $timeLocale;

    /**
     * @var string|false
     */
    private $monetaryLocale;

    protected function set_up(): void
    {
        parent::set_up();
        $this->errorReporting = error_reporting();
        $this->displayErrors = ini_get('display_errors');
        $this->defaultCharset = ini_get('default_charset');
        $this->timezone = date_default_timezone_get();
        $this->timeLocale = setlocale(LC_TIME, '0');
        $this->monetaryLocale = setlocale(LC_MONETARY, '0');
    }

    protected function tear_down(): void
    {
        error_reporting($this->errorReporting);
        ini_set('display_errors', (string) $this->displayErrors);
        ini_set('default_charset', (string) $this->defaultCharset);
        date_default_timezone_set($this->timezone);
        setlocale(LC_TIME, (string) $this->timeLocale);
        setlocale(LC_MONETARY, (string) $this->monetaryLocale);
        parent::tear_down();
    }

    public function testDebugDisplaysEveryError(): void
    {
        error_reporting(E_ALL & ~E_NOTICE);

        (new RuntimeSettings())->apply(TestConfig::create(['DEBUG' => 'true']));

        self::assertSame(E_ALL, error_reporting());
        self::assertSame('1', ini_get('display_errors'));
    }

    public function testWithoutDebugNoErrorIsDisplayed(): void
    {
        ini_set('display_errors', '1');

        (new RuntimeSettings())->apply(TestConfig::create(['DEBUG' => 'false']));

        self::assertSame('0', ini_get('display_errors'));
        self::assertSame($this->errorReporting, error_reporting(), 'errors are still logged');
    }

    public function testCharsetBecomesTheDefaultCharset(): void
    {
        (new RuntimeSettings())->apply(TestConfig::create(['CHARSET' => 'ISO-8859-1']));

        self::assertSame('ISO-8859-1', ini_get('default_charset'));
    }

    public function testTimezoneBecomesTheDefaultTimezone(): void
    {
        date_default_timezone_set('UTC');

        (new RuntimeSettings())->apply(TestConfig::create(['TIMEZONE' => 'Europe/Rome']));

        self::assertSame('Europe/Rome', date_default_timezone_get());
    }

    public function testWithoutTimezoneTheCurrentOneIsKept(): void
    {
        date_default_timezone_set('Asia/Tokyo');

        $values = TestConfig::create()->toArray();
        $values['TIMEZONE'] = null;

        (new RuntimeSettings())->apply(Config::fromArray($values));

        self::assertSame('Asia/Tokyo', date_default_timezone_get());
    }

    public function testTheCLocaleIsApplied(): void
    {
        (new RuntimeSettings())->apply(TestConfig::create(['LOCALE' => 'C']));

        self::assertSame('C', setlocale(LC_TIME, '0'));
    }
}
