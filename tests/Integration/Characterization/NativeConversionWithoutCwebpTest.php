<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * IMAGE_CONVERSION_MODE=native on a host without cwebp in PATH.
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class NativeConversionWithoutCwebpTest extends CharacterizationTestCase
{
    protected function configuration(): array
    {
        return ['IMAGE_CONVERSION_MODE' => '"native"'] + parent::configuration();
    }

    protected function executablePath(): string
    {
        return $this->workDirectory . '/empty-bin';
    }

    /**
     * Finding P-10 (resolved by PRX-8): the miss answers 500 although the
     * original is cached.
     */
    public function testMissingBinaryAnswers500P10(): void
    {
        $response = $this->get('/images/gradient.png');

        self::assertSame(500, $response->getStatus());
        self::assertSame(
            "The request could not be processed.\nInternal server error: Image conversion failed: cwebp binary not available in PATH.",
            $response->getBody()
        );
        self::assertFileExists($this->publicPath('images/gradient.png'));
    }
}
