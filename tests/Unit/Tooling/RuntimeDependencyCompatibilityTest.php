<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Tooling;

use Dotenv\Dotenv;
use Mfonte\ProxyCdn\Tests\Fixtures\Console\GreetCommand;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Exercises the API subset of the runtime dependencies that the project
 * relies on (plan section 4, risk L-11). CI runs it against the lowest and
 * the highest installable releases on every PHP version, so a signature or
 * behaviour change in any supported release fails here first.
 *
 * @coversNothing
 */
#[CoversNothing]
final class RuntimeDependencyCompatibilityTest extends TestCase
{
    /**
     * The conversion contract comes from the library installed in vendor/
     * from its git repository, not from a copy inside this project.
     */
    public function testConversionLibraryIsAutoloadedFromVendor(): void
    {
        $vendor = realpath(dirname(__DIR__, 3) . '/vendor');
        self::assertIsString($vendor);
        $file = (new \ReflectionClass(ConversionRequest::class))->getFileName();
        self::assertIsString($file);
        $file = realpath($file);
        self::assertIsString($file);

        self::assertStringStartsWith(
            $vendor . DIRECTORY_SEPARATOR . 'mfonte' . DIRECTORY_SEPARATOR . 'secure-image-converter' . DIRECTORY_SEPARATOR,
            $file
        );

        $request = ConversionRequest::fromParameters(['format' => 'avif', 'width' => '640']);
        self::assertSame('avif', $request->getFormat());
        self::assertSame(640, $request->getWidth());
        self::assertEquals($request, ConversionRequest::fromParameters($request->toParameters()));
    }

    public function testConsoleCommandsUseTheSharedApi(): void
    {
        $command = new GreetCommand();
        $tester = new CommandTester($command);

        self::assertSame('fixture:greet', $command->getName());
        self::assertSame(0, $tester->execute(['who' => 'proxy']));
        self::assertSame("Hello proxy\n", $tester->getDisplay(true));
        self::assertSame(1, $tester->execute(['who' => 'proxy', '--fail' => true]));
    }

    public function testRequestKeepsThePathEncodedAndNormalisesTheQuery(): void
    {
        $request = Request::create(
            'https://cdn.example.test/img/caf%C3%A9.png?v=2&a=1',
            'GET',
            [],
            [],
            [],
            ['HTTP_ACCEPT' => 'image/avif,image/webp,*/*']
        );

        // The path is not decoded (finding P-8: decoding is the proxy's job).
        self::assertSame('/img/caf%C3%A9.png', $request->getPathInfo());
        self::assertSame('cdn.example.test', $request->getHost());
        // getQueryString() reorders parameters; the raw string is what the
        // client sent and what the origin must receive (finding P-9).
        self::assertSame('a=1&v=2', $request->getQueryString());
        self::assertSame('v=2&a=1', $request->server->get('QUERY_STRING'));
        self::assertSame('image/avif,image/webp,*/*', $request->headers->get('accept'));
    }

    public function testResponseHeadersAndCacheDirectives(): void
    {
        $response = new Response('bytes', 200, ['content-type' => 'image/png']);
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->headers->set('X-Cache-Status', 'MISS');

        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('MISS', $response->headers->get('x-cache-status'));
        self::assertSame('max-age=3600, public', $response->headers->get('Cache-Control'));
        self::assertSame('bytes', $response->getContent());
    }

    public function testEnvironmentExampleParses(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/.env.example');
        self::assertIsString($contents);

        $values = Dotenv::parse($contents);

        self::assertNotEmpty($values);
        foreach (array_keys($values) as $name) {
            self::assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]*$/', (string) $name);
        }
    }

    public function testMissingEnvFileIsNotAnErrorForSafeLoad(): void
    {
        $directory = sys_get_temp_dir() . '/proxy-cdn-no-env-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));

        try {
            self::assertSame([], Dotenv::createArrayBacked($directory)->safeLoad());
        } finally {
            rmdir($directory);
        }
    }

    public function testLoggersExtendAbstractLogger(): void
    {
        $logger = new MemoryLogger();

        $logger->info('Fetched {path}', ['path' => '/a.png']);
        $logger->log(LogLevel::ERROR, 'Origin failed');

        self::assertSame(
            [
                ['level' => LogLevel::INFO, 'message' => 'Fetched {path}', 'context' => ['path' => '/a.png']],
                ['level' => LogLevel::ERROR, 'message' => 'Origin failed', 'context' => []],
            ],
            $logger->getRecords()
        );
    }
}
