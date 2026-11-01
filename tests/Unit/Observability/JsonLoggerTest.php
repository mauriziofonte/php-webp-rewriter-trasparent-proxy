<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Observability;

use Mfonte\ProxyCdn\Observability\JsonLogger;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionTimeoutException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ProxyCdn\Observability\JsonLogger
 */
#[CoversClass(JsonLogger::class)]
final class JsonLoggerTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var string
     */
    private $file;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->file = $this->directory->path('logs/proxy.log');
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testAnEntryIsOneJsonObjectPerLine(): void
    {
        $logger = new JsonLogger($this->file);

        $logger->error('upstream non-200', ['url' => 'https://origin.example.test/a.png', 'status' => 502]);
        $logger->info('purge', ['file' => '/var/cache/a.png', 'aggressive' => false]);

        $entries = $this->entries();
        self::assertCount(2, $entries);
        self::assertSame(['timestamp', 'level', 'channel', 'message', 'context'], array_keys($entries[0]));
        self::assertSame('error', $entries[0]['level']);
        self::assertSame('proxy-cdn', $entries[0]['channel']);
        self::assertSame('upstream non-200', $entries[0]['message']);
        self::assertSame(['url' => 'https://origin.example.test/a.png', 'status' => 502], $entries[0]['context']);
        self::assertSame('info', $entries[1]['level']);
        self::assertSame(['file' => '/var/cache/a.png', 'aggressive' => false], $entries[1]['context']);
    }

    public function testTheTimestampIsRfc3339WithMicroseconds(): void
    {
        (new JsonLogger($this->file))->warning('message');

        $timestamp = $this->entries()[0]['timestamp'];
        self::assertIsString($timestamp);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}\z/', $timestamp);
    }

    public function testAnEmptyContextIsAnObject(): void
    {
        (new JsonLogger($this->file))->notice('message');

        self::assertStringContainsString('"context":{}', (string) file_get_contents($this->file));
    }

    public function testTheChannelCanBeChosen(): void
    {
        (new JsonLogger($this->file, LogLevel::DEBUG, 'laravel'))->debug('message');

        self::assertSame('laravel', $this->entries()[0]['channel']);
    }

    /**
     * @dataProvider provideMinimumLevels
     *
     * @param list<string> $expected
     */
    #[DataProvider('provideMinimumLevels')]
    public function testEntriesBelowTheMinimumLevelAreDropped(string $minimumLevel, array $expected): void
    {
        $logger = new JsonLogger($this->file, $minimumLevel);
        foreach ([LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY] as $level) {
            $logger->log($level, $level);
        }

        self::assertSame($expected, array_column($this->entries(), 'level'));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function provideMinimumLevels(): array
    {
        return [
            'debug' => [LogLevel::DEBUG, ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']],
            'notice' => [LogLevel::NOTICE, ['notice', 'warning', 'error', 'critical', 'alert', 'emergency']],
            'error' => [LogLevel::ERROR, ['error', 'critical', 'alert', 'emergency']],
            'emergency' => [LogLevel::EMERGENCY, ['emergency']],
        ];
    }

    public function testNothingIsWrittenWhenEveryEntryIsDropped(): void
    {
        (new JsonLogger($this->file, LogLevel::ERROR))->info('purge');

        self::assertDirectoryDoesNotExist(dirname($this->file));
    }

    public function testAnUnknownLevelIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new JsonLogger($this->file))->log('verbose', 'message');
    }

    public function testAnUnknownMinimumLevelIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JsonLogger($this->file, 'verbose');
    }

    public function testPlaceholdersAreReplaced(): void
    {
        (new JsonLogger($this->file))->info('fetched {url} in {ms} ms, cached {cached}, {missing} {array}', [
            'url' => 'https://origin.example.test/a.png',
            'ms' => 12.5,
            'cached' => true,
            'array' => [1],
        ]);

        self::assertSame(
            'fetched https://origin.example.test/a.png in 12.5 ms, cached true, {missing} {array}',
            $this->entries()[0]['message']
        );
    }

    public function testExceptionsAreSerialised(): void
    {
        $previous = new \RuntimeException('inner', 3);
        $exception = new \LogicException('outer', 7, $previous);

        (new JsonLogger($this->file))->error('failed', ['exception' => $exception]);

        $context = $this->entries()[0]['context'];
        self::assertIsArray($context);
        self::assertSame([
            'class' => 'LogicException',
            'message' => 'outer',
            'code' => 7,
            'file' => __FILE__ . ':' . $exception->getLine(),
            'previous' => [
                'class' => 'RuntimeException',
                'message' => 'inner',
                'code' => 3,
                'file' => __FILE__ . ':' . $previous->getLine(),
            ],
        ], $context['exception']);
    }

    /**
     * The error code of a library exception is written with the exception
     * and copied to the context key shared with the conversion API.
     */
    public function testTheErrorCodeOfALibraryExceptionIsLogged(): void
    {
        $exception = new ConversionTimeoutException('took too long');

        (new JsonLogger($this->file))->error('conversion failed', ['exception' => $exception, 'url' => '/a.png']);

        $context = $this->entries()[0]['context'];
        self::assertIsArray($context);
        self::assertSame('conversion_timeout', $context['conversion_error_code'] ?? null);
        self::assertIsArray($context['exception']);
        self::assertSame('conversion_timeout', $context['exception']['error_code'] ?? null);
        self::assertSame(ConversionTimeoutException::class, $context['exception']['class'] ?? null);
        self::assertSame('/a.png', $context['url'] ?? null);
    }

    public function testAGivenConversionErrorCodeIsKept(): void
    {
        (new JsonLogger($this->file))->error('failed', ['exception' => new ConversionFailedException('x'), 'conversion_error_code' => 'set_by_caller']);

        $context = $this->entries()[0]['context'];
        self::assertIsArray($context);
        self::assertSame('set_by_caller', $context[JsonLogger::CONVERSION_ERROR_CODE] ?? null);
    }

    public function testOtherExceptionsGetNoConversionErrorCode(): void
    {
        $exception = new class ('other') extends \RuntimeException {
            public function getErrorCode(): string
            {
                return 'own_code';
            }
        };

        (new JsonLogger($this->file))->error('failed', ['exception' => $exception, 'previous' => new \LogicException('plain')]);

        $context = $this->entries()[0]['context'];
        self::assertIsArray($context);
        self::assertArrayNotHasKey('conversion_error_code', $context);
        self::assertIsArray($context['exception']);
        self::assertSame('own_code', $context['exception']['error_code'] ?? null);
        self::assertIsArray($context['previous']);
        self::assertArrayNotHasKey('error_code', $context['previous']);
    }

    public function testOtherValuesAreMadeJsonFriendly(): void
    {
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);

        (new JsonLogger($this->file))->info('values', [
            'null' => null,
            'date' => new \DateTimeImmutable('2026-09-01T10:00:00+00:00'),
            'object' => new \ArrayObject(),
            'nested' => ['a' => ['b' => 1]],
            'infinite' => INF,
            'resource' => $resource,
        ]);
        fclose($resource);

        self::assertSame([
            'null' => null,
            'date' => '2026-09-01T10:00:00+00:00',
            'object' => '[object ArrayObject]',
            'nested' => ['a' => ['b' => 1]],
            'infinite' => 'INF',
            'resource' => '[resource]',
        ], $this->entries()[0]['context']);
    }

    public function testDeepValuesAreSummarised(): void
    {
        $value = 'leaf';
        for ($i = 0; $i < 12; ++$i) {
            $value = [$value];
        }

        (new JsonLogger($this->file))->info('deep', ['value' => $value]);

        self::assertStringContainsString('"[...]"', (string) file_get_contents($this->file));
    }

    /**
     * Messages and values cannot forge other entries (log injection).
     */
    public function testNewlinesStayInsideTheEntry(): void
    {
        (new JsonLogger($this->file))->error("a\nb", ['url' => "c\r\n{\"level\":\"emergency\"}"]);

        self::assertCount(1, $this->entries());
        self::assertSame("a\nb", $this->entries()[0]['message']);
    }

    public function testInvalidUtf8IsSubstituted(): void
    {
        (new JsonLogger($this->file))->error("bad \xB1 byte", ['url' => "/\xFF.png"]);

        $entry = $this->entries()[0];
        self::assertSame("bad \u{FFFD} byte", $entry['message']);
        self::assertSame(['url' => "/\u{FFFD}.png"], $entry['context']);
    }

    public function testAnUnwritableFileIsIgnored(): void
    {
        $this->directory->write('blocked', 'a file where a directory is expected');
        $logger = new JsonLogger($this->directory->path('blocked/proxy.log'));

        $logger->emergency('nobody hears this');

        self::assertFileDoesNotExist($this->directory->path('blocked/proxy.log'));
    }

    /**
     * @return list<array<mixed>>
     */
    private function entries(): array
    {
        $entries = [];
        foreach (file($this->file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $entry = json_decode($line, true);
            self::assertIsArray($entry, $line);
            $entries[] = $entry;
        }

        return $entries;
    }
}
