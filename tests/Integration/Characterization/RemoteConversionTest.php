<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use Mfonte\ProxyCdn\Tests\Fixtures\Image\FakeImageConverter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * IMAGE_CONVERTER=remote with an API that fails: the fake origin answers
 * /healthz and /v2/convert as the conversion API v2 would. The contract
 * tests (tests/Contract) run the real API.
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class RemoteConversionTest extends CharacterizationTestCase
{
    private const API_KEY = 'api-secret-0123456789';

    protected function configuration(): array
    {
        return [
            'IMAGE_CONVERTER' => '"remote"',
            'IMAGE_API_URL' => '"' . $this->origin->getBaseUrl() . '"',
            'IMAGE_API_KEY' => '"' . self::API_KEY . '"',
        ] + parent::configuration();
    }

    /**
     * Finding P-10 (resolved by PRX-8): an API that cannot convert leaves
     * the original to be served, never a 5xx.
     */
    public function testAnUnavailableApiServesTheOriginalP10(): void
    {
        $this->origin->setRule('/healthz', 503, null, '{"status":"unavailable","error":{"code":"internal_error","message":"down"}}');
        $png = self::fixture('images/gradient.png');

        $response = $this->get('/images/gradient.png', ['Accept' => 'image/avif,image/webp,*/*']);

        self::assertSame(200, $response->getStatus());
        self::assertSame($png, $response->getBody());
        self::assertSame('image/png', $response->getHeader('Content-Type'));
        self::assertSame('Accept', $response->getHeader('Vary'));
        self::assertStringEqualsFile($this->cachePath('images/gradient.png'), $png);
        self::assertFileDoesNotExist($this->cachePath('.webp/images/gradient.png.webp'));
        self::assertFileDoesNotExist($this->cachePath('.avif/images/gradient.png.avif'));
    }

    /**
     * Findings P-10 and C-1 (resolved by PRX-8): a conversion error serves
     * the original; the image is posted to /v2/convert with the key in
     * "Authorization: Bearer", not in the "-x-api-key" header of 2.x.
     */
    public function testAConversionErrorServesTheOriginalP10C1(): void
    {
        $this->origin->setRule('/healthz', 200, null, self::healthz(), ['Content-Type' => 'application/json']);
        $this->origin->setRule('/v2/convert', 500, null, '{"error":{"code":"internal_error","message":"Backend exploded"}}');
        $png = self::fixture('images/gradient.png');

        $response = $this->get('/images/gradient.png', ['Accept' => 'image/webp']);

        self::assertSame(200, $response->getStatus());
        self::assertSame($png, $response->getBody());
        $request = $this->origin->getLastRequest();
        self::assertSame('POST', $request['method']);
        self::assertSame('/v2/convert', $request['path']);
        self::assertSame('Bearer ' . self::API_KEY, $request['headers']['authorization'] ?? null);
        self::assertArrayNotHasKey('-x-api-key', $request['headers']);
        self::assertFileDoesNotExist($this->cachePath('.webp/images/gradient.png.webp'));
    }

    /**
     * The /healthz answer of an API that accepts the key.
     */
    private static function healthz(): string
    {
        return (string) json_encode(['status' => 'ok', 'capabilities' => FakeImageConverter::capabilities(['webp', 'avif'])->toArray()]);
    }
}
