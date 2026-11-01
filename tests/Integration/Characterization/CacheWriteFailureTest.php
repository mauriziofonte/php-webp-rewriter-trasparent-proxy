<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * Failures to store a fetched file. The obstacles are files and directories
 * in the way, so that the tests also fail the write when they run as root.
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class CacheWriteFailureTest extends CharacterizationTestCase
{
    /**
     * An internal error is answered with 500 (finding P-21: 2.x answered
     * 404).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testCacheDirectoryFailureAnswers500P21(): void
    {
        mkdir($this->cachePath(''), 0777, true);
        file_put_contents($this->cachePath('nested'), 'a file where a directory is needed');

        $response = $this->get('/nested/deep.css');

        self::assertErrorResponse($response, 500, 'Cannot create cache directory', '/nested/deep.css');
        self::assertSame(1, $this->origin->countRequestsFor('/nested/deep.css'));
    }

    /**
     * An internal error is answered with 500 (finding P-21: 2.x answered
     * 404), and the temporary file of the atomic write is removed.
     *
     * @group regression
     */
    #[Group('regression')]
    public function testCacheFileFailureAnswers500P21(): void
    {
        mkdir($this->cachePath('css/site.css'), 0777, true);

        $response = $this->get('/css/site.css');

        self::assertErrorResponse($response, 500, 'Cannot create cache file', '/css/site.css');
        self::assertSame(['.', '..', 'site.css'], scandir($this->cachePath('css')));
    }
}
