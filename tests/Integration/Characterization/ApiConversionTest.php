<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * IMAGE_CONVERSION_MODE=api: raster images are sent to the 1.x conversion
 * API, imitated by the fake origin at /__api/convert.
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class ApiConversionTest extends CharacterizationTestCase
{
    protected function configuration(): array
    {
        return [
            'IMAGE_CONVERSION_MODE' => '"api"',
            'WEBP_API_SERVER' => '"' . $this->origin->getBaseUrl() . '/__api/convert"',
            'WEBP_API_KEY' => '"api-secret"',
        ] + parent::configuration();
    }

    public function testMissForAWebpClientServesTheImageReturnedByTheApi(): void
    {
        $png = self::fixture('images/gradient.png');

        $response = $this->get('/images/gradient.png', ['Accept' => 'image/webp']);

        self::assertSame(200, $response->getStatus());
        self::assertSame('API-WEBP:' . $png, $response->getBody());
        self::assertSame('image/webp', $response->getHeader('Content-Type'));
        self::assertSame('Accept', $response->getHeader('Vary'));
        self::assertStringEqualsFile($this->publicPath('images/gradient.webp'), 'API-WEBP:' . $png);
    }

    /**
     * The API key travels in a header named "-x-api-key" (finding C-1, on
     * the proxy side resolved by PRX-8).
     */
    public function testApiRequestUsesThe1xMultipartShapeC1(): void
    {
        $this->get('/images/gradient.png');

        $request = $this->origin->getLastRequest();
        self::assertSame('POST', $request['method']);
        self::assertSame('/__api/convert', $request['path']);
        self::assertSame('api-secret', $request['headers']['-x-api-key'] ?? null);
        self::assertSame('PHP cUrl connector for MWEBP', $request['headers']['user-agent'] ?? null);
        self::assertSame(['images'], $request['files'] ?? null);
        self::assertSame(
            ['descriptors' => '[{"file_id":"gradient.png","filename":"gradient.png"}]'],
            $request['post'] ?? null
        );
    }

    /**
     * Finding P-10 (resolved by PRX-8): an API error answers 500 although
     * the original is cached.
     */
    public function testApiErrorAnswers500WithItsMessageP10(): void
    {
        $this->origin->setRule('/__api/convert', 500, null, 'Backend exploded');

        $response = $this->get('/images/gradient.png');

        self::assertSame(500, $response->getStatus());
        self::assertSame(
            "The request could not be processed.\nInternal server error: Image conversion failed: Backend exploded.",
            $response->getBody()
        );
        self::assertFileExists($this->publicPath('images/gradient.png'));
        self::assertFileDoesNotExist($this->publicPath('images/gradient.webp'));
    }

    public function testApiErrorWithoutMessageReportsTheStatus(): void
    {
        $this->origin->setRule('/__api/convert', 502);

        $response = $this->get('/images/gradient.png');

        self::assertSame(500, $response->getStatus());
        self::assertSame(
            "The request could not be processed.\nInternal server error: Image conversion failed: Unknown error with HTTP_RESPONSE_CODE=\"502\".",
            $response->getBody()
        );
    }
}
