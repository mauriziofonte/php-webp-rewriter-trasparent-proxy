<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Image\Legacy;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Image\Legacy\ConversionApiConverter;
use Mfonte\ProxyCdn\Tests\Fixtures\FakeOrigin\FakeOriginServer;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Log\MemoryLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Talks to the fake 1.x conversion API of the fake origin.
 *
 * @covers \Mfonte\ProxyCdn\Image\Legacy\ConversionApiConverter
 */
#[CoversClass(ConversionApiConverter::class)]
final class ConversionApiConverterTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var FakeOriginServer
     */
    private $api;

    /**
     * @var MemoryLogger
     */
    private $logger;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        mkdir($this->directory->path('state'));
        $this->api = FakeOriginServer::start($this->directory->path('state'));
        $this->logger = new MemoryLogger();
    }

    protected function tear_down(): void
    {
        $this->api->stop();
        $this->directory->remove();
        parent::tear_down();
    }

    public function testTheReturnedWebpIsWrittenNextToTheSource(): void
    {
        $source = $this->directory->write('cache/images/logo.png', 'PNG');

        $conversion = $this->converter()->convert($source, self::target($source));

        $webp = $this->directory->path('cache/images/logo.webp');
        self::assertTrue($conversion->isConverted());
        self::assertSame($webp, $conversion->getPath());
        self::assertSame('API-WEBP:PNG', file_get_contents($webp));
        self::assertSame([], $this->logger->getRecords());
    }

    public function testThe1xRequestIsSent(): void
    {
        $source = $this->directory->write('cache/logo.png', 'PNG');

        $this->converter()->convert($source, self::target($source));

        $request = $this->api->getLastRequest();
        self::assertSame('POST', $request['method']);
        self::assertSame('/__api/convert', $request['path']);
        self::assertSame('secret-key', $request['headers']['-x-api-key'] ?? null);
        self::assertSame('PHP cUrl connector for MWEBP', $request['headers']['user-agent'] ?? null);
        self::assertSame(['images'], $request['files'] ?? null);
        self::assertSame(['descriptors' => '[{"file_id":"logo.png","filename":"logo.png"}]'], $request['post'] ?? null);
    }

    public function testTheApiMessageIsTheError(): void
    {
        $source = $this->directory->write('cache/logo.png', 'PNG');
        $this->api->setRule('/__api/convert', 422, null, 'Unsupported image');

        $conversion = $this->converter()->convert($source, self::target($source));

        self::assertSame('Unsupported image', $conversion->getError());
        self::assertSame(
            [['level' => 'error', 'message' => 'webp api failed', 'context' => ['source' => $source, 'err' => 'Unsupported image']]],
            $this->logger->getRecords()
        );
    }

    public function testWithoutMessageTheStatusIsTheError(): void
    {
        $source = $this->directory->write('cache/logo.png', 'PNG');
        $this->api->setRule('/__api/convert', 502);

        $conversion = $this->converter()->convert($source, self::target($source));

        self::assertSame('Unknown error with HTTP_RESPONSE_CODE="502"', $conversion->getError());
    }

    public function testAnUnreachableApiFails(): void
    {
        $source = $this->directory->write('cache/logo.png', 'PNG');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $converter = new ConversionApiConverter('http://' . $address . '/__api/convert', 'secret-key', new FilesystemStorage(), $this->logger);

        self::assertSame('Unknown error with HTTP_RESPONSE_CODE="0"', $converter->convert($source, self::target($source))->getError());
    }

    /**
     * @dataProvider provideInvalidResponses
     */
    #[DataProvider('provideInvalidResponses')]
    public function testAnInvalidResponseFails(string $body, string $error): void
    {
        $source = $this->directory->write('cache/logo.png', 'PNG');
        $this->api->setRule('/__api/convert', 200, null, $body);

        $conversion = $this->converter()->convert($source, self::target($source));

        self::assertSame($error, $conversion->getError());
        self::assertFileDoesNotExist($this->directory->path('cache/logo.webp'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideInvalidResponses(): array
    {
        return [
            'empty response list' => ['{"response":[]}', 'Invalid response from API'],
            'no image' => ['{"response":[{"error":"x"}]}', 'Invalid response from API'],
            'not base64' => ['{"response":[{"webp_image_base64":"***"}]}', 'Base64 decode failed'],
            'no response key' => ['{"result":true}', 'Unknown error with HTTP_RESPONSE_CODE="200"'],
            'not JSON' => ['<html></html>', 'Unknown error with HTTP_RESPONSE_CODE="200"'],
        ];
    }

    public function testAnUnwritableSiblingFails(): void
    {
        $source = $this->directory->write('cache/logo.png', 'PNG');
        $this->directory->write('cache/logo.webp/occupied', 'a directory where the sibling goes');

        self::assertSame('Atomic write failed', $this->converter()->convert($source, self::target($source))->getError());
    }

    public function testAMissingSourceFails(): void
    {
        self::assertSame('Source file does not exist', $this->converter()->convert($this->directory->path('missing.png'), self::target($this->directory->path('missing.png')))->getError());
        self::assertSame([], $this->api->getRequests());
    }

    public function testMissingCredentialsFail(): void
    {
        $source = $this->directory->write('cache/logo.png', 'PNG');
        $converter = new ConversionApiConverter(null, 'secret-key', new FilesystemStorage(), $this->logger);

        self::assertSame('WEBP_API_SERVER or WEBP_API_KEY not defined', $converter->convert($source, self::target($source))->getError());
    }

    private function converter(): ConversionApiConverter
    {
        return new ConversionApiConverter($this->api->getBaseUrl() . '/__api/convert', 'secret-key', new FilesystemStorage(), $this->logger);
    }

    /**
     * The path the kernel gives the variant of $source in 2.x layout terms:
     * its directory and name with ".webp".
     */
    private static function target(string $source): string
    {
        return dirname($source) . '/' . pathinfo($source, PATHINFO_FILENAME) . '.webp';
    }
}
