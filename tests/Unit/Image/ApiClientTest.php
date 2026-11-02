<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Image;

use Mfonte\ProxyCdn\Image\ApiClient;
use Mfonte\ProxyCdn\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\ScriptedServer;
use Mfonte\ProxyCdn\Tests\Fixtures\Http\TestCertificate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The transport of the conversion API client: TLS is always verified,
 * redirects are never followed, timeouts and body limits hold.
 *
 * @covers \Mfonte\ProxyCdn\Image\ApiClient
 *
 * @group network
 */
#[CoversClass(ApiClient::class)]
#[Group('network')]
final class ApiClientTest extends TestCase
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

    public function testAnUntrustedCertificateIsRefusedAndATrustedOneAccepted(): void
    {
        if (!TestCertificate::isSupported()) {
            self::markTestSkipped('The openssl extension cannot make a test certificate');
        }
        mkdir($this->directory->path('tls'));
        $certificate = TestCertificate::create($this->directory->path('tls'));
        $server = ScriptedServer::start($this->directory->path('tls'), $certificate->getServerPem());
        try {
            $url = 'https://127.0.0.1:' . $server->getPort() . '/echo';

            $refused = (new ApiClient(2.0, 5.0, null, 'test'))->get($url, [], 65536);
            $accepted = (new ApiClient(2.0, 5.0, $certificate->getCaPem(), 'test'))->get($url, [], 65536);
        } finally {
            $server->stop();
        }

        self::assertSame(0, $refused->getStatus());
        self::assertNotSame(0, $refused->getTransportErrorCode());
        self::assertSame(200, $accepted->getStatus());
    }

    public function testARedirectIsReturnedNotFollowed(): void
    {
        mkdir($this->directory->path('plain'));
        $server = ScriptedServer::start($this->directory->path('plain'));
        try {
            $response = (new ApiClient(2.0, 5.0, null, 'test'))->get($server->getBaseUrl() . '/redirect/2', [], 65536);
            $count = count($server->getRequests());
        } finally {
            $server->stop();
        }

        self::assertSame(302, $response->getStatus());
        self::assertSame('/redirect/1', $response->getHeader('location'));
        self::assertSame(1, $count);
    }

    public function testATimeoutIsATransportError(): void
    {
        mkdir($this->directory->path('plain'));
        $server = ScriptedServer::start($this->directory->path('plain'));
        try {
            $started = microtime(true);
            $response = (new ApiClient(2.0, 1.0, null, 'test'))->get($server->getBaseUrl() . '/bytes/10?header_delay=3000', [], 65536);
            $elapsed = microtime(true) - $started;
        } finally {
            $server->stop();
        }

        self::assertSame(0, $response->getStatus());
        self::assertSame(CURLE_OPERATION_TIMEDOUT, $response->getTransportErrorCode());
        self::assertLessThan(2.5, $elapsed);
    }

    public function testABodyWithoutLengthIsBounded(): void
    {
        mkdir($this->directory->path('plain'));
        $server = ScriptedServer::start($this->directory->path('plain'));
        try {
            $response = (new ApiClient(2.0, 5.0, null, 'test'))->get($server->getBaseUrl() . '/bytes/300000?mode=close', [], 65536);
        } finally {
            $server->stop();
        }

        self::assertTrue($response->isTooLarge());
        self::assertLessThanOrEqual(65536, strlen($response->getBody()));
    }

    public function testOnlyHttpUrlsAreFetched(): void
    {
        $response = (new ApiClient(2.0, 5.0, null, 'test'))->get('file:///etc/passwd', [], 65536);

        self::assertSame(0, $response->getStatus());
        self::assertSame('', $response->getBody());
    }
}
