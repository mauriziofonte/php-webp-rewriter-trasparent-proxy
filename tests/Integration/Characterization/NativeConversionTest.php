<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * IMAGE_CONVERSION_MODE=native: raster images are converted to a ".webp"
 * sibling with cwebp, here a fake one (see CharacterizationTestCase).
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class NativeConversionTest extends CharacterizationTestCase
{
    private const ACCEPT_WEBP = ['Accept' => 'image/avif,image/webp,*/*'];

    protected function configuration(): array
    {
        return ['IMAGE_CONVERSION_MODE' => '"native"'] + parent::configuration();
    }

    public function testMissForAWebpClientServesTheConvertedImage(): void
    {
        $png = self::fixture('images/gradient.png');

        $response = $this->get('/images/gradient.png', self::ACCEPT_WEBP);

        self::assertSame(200, $response->getStatus());
        self::assertSame('FAKE-WEBP:' . $png, $response->getBody());
        self::assertSame('image/webp', $response->getHeader('Content-Type'));
        self::assertSame((string) strlen('FAKE-WEBP:' . $png), $response->getHeader('Content-Length'));
        self::assertSame('Accept', $response->getHeader('Vary'));
        self::assertSame('miss', $response->getHeader('X-Cache-Status'));
        // The validators of the original travel with the converted body.
        self::assertSame('"' . md5($png) . '"', $response->getHeader('ETag'));
        self::assertStringEqualsFile($this->publicPath('images/gradient.png'), $png);
        self::assertStringEqualsFile($this->publicPath('images/gradient.webp'), 'FAKE-WEBP:' . $png);
    }

    public function testCwebpIsCalledOnAScratchFileThatIsRenamed(): void
    {
        $this->get('/images/gradient.png');

        $calls = $this->cwebpCalls();
        self::assertCount(1, $calls);
        $source = $this->publicPath('images/gradient.png');
        self::assertMatchesRegularExpression(
            '#^-quiet -m 4 ' . preg_quote($source, '#') . ' -o ' . preg_quote($this->publicPath('images/gradient.webp'), '#') . '\.tmp\.[0-9a-f]{8}$#',
            $calls[0]
        );
        self::assertSame(['.', '..', 'gradient.png', 'gradient.webp'], scandir($this->publicPath('images')));
    }

    public function testMissForOtherClientsServesTheOriginalAndConvertsEagerly(): void
    {
        $png = self::fixture('images/gradient.png');

        $response = $this->get('/images/gradient.png');

        self::assertSame($png, $response->getBody());
        self::assertSame('image/png', $response->getHeader('Content-Type'));
        self::assertSame('Origin', $response->getHeader('Vary'));
        self::assertFileExists($this->publicPath('images/gradient.webp'));
    }

    /**
     * Even without a query string, PHP serves the sibling to a webp client.
     */
    public function testExistingSiblingIsServedFromDiskToWebpClients(): void
    {
        $this->get('/images/gradient.png');

        $response = $this->get('/images/gradient.png', self::ACCEPT_WEBP);

        self::assertSame('hit', $response->getHeader('X-Cache-Status'));
        self::assertSame('image/webp', $response->getHeader('Content-Type'));
        self::assertSame('Accept', $response->getHeader('Vary'));
        self::assertSame('FAKE-WEBP:' . self::fixture('images/gradient.png'), $response->getBody());
        self::assertSame(1, $this->origin->countRequestsFor('/images/gradient.png'));
    }

    public function testQueryStringHitConvertsLazilyWhenTheSiblingIsMissing(): void
    {
        $this->get('/images/gradient.png?v=1');
        unlink($this->bucketPath('v=1', 'images/gradient.webp'));

        $response = $this->get('/images/gradient.png?v=1', self::ACCEPT_WEBP);

        self::assertSame('hit', $response->getHeader('X-Cache-Status'));
        self::assertSame('image/webp', $response->getHeader('Content-Type'));
        self::assertFileExists($this->bucketPath('v=1', 'images/gradient.webp'));
        self::assertCount(2, $this->cwebpCalls());
        self::assertSame(1, $this->origin->countRequestsFor('/images/gradient.png'));
    }

    public function testQueryStringHitForOtherClientsStillServesTheOriginal(): void
    {
        $this->get('/images/gradient.png?v=1');

        $response = $this->get('/images/gradient.png?v=1');

        self::assertSame('hit', $response->getHeader('X-Cache-Status'));
        self::assertSame('image/png', $response->getHeader('Content-Type'));
        self::assertSame(self::fixture('images/gradient.png'), $response->getBody());
    }

    /**
     * A failed conversion answers 500 although the original is cached
     * (finding P-10, resolved by PRX-8).
     */
    public function testFailedConversionAnswers500P10(): void
    {
        $response = $this->get('/images/broken.png', self::ACCEPT_WEBP);

        self::assertSame(500, $response->getStatus());
        self::assertSame('text/plain; charset=UTF-8', $response->getHeader('Content-Type'));
        self::assertSame(
            "The request could not be processed.\nInternal server error: Image conversion failed: Conversion failed: output file not created.",
            $response->getBody()
        );
        self::assertFileExists($this->publicPath('images/broken.png'));
        self::assertFileDoesNotExist($this->publicPath('images/broken.webp'));
    }

    /**
     * A query-string hit retries the conversion every time and serves the
     * original when it fails (finding P-10, resolved by PRX-8).
     */
    public function testFailedLazyConversionIsRetriedOnEveryHitP10(): void
    {
        self::assertSame(500, $this->get('/images/broken.png?v=1', self::ACCEPT_WEBP)->getStatus());

        $first = $this->get('/images/broken.png?v=1', self::ACCEPT_WEBP);
        $second = $this->get('/images/broken.png?v=1', self::ACCEPT_WEBP);

        foreach ([$first, $second] as $response) {
            self::assertSame(200, $response->getStatus());
            self::assertSame('hit', $response->getHeader('X-Cache-Status'));
            self::assertSame('image/png', $response->getHeader('Content-Type'));
        }
        self::assertCount(3, $this->cwebpCalls());
    }

    public function testOtherTypesAreNotConverted(): void
    {
        $this->get('/js/app.js');
        $this->get('/images/logo.svg');

        self::assertSame([], $this->cwebpCalls());
    }

    /**
     * @return list<string>
     */
    private function cwebpCalls(): array
    {
        $lines = @file($this->workDirectory . '/cwebp.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? [] : $lines;
    }
}
