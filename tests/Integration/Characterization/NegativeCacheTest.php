<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * The negative cache: an origin 404 is remembered for NEGATIVE_CACHE_TTL
 * seconds in var/negative/, outside the document root.
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class NegativeCacheTest extends CharacterizationTestCase
{
    public function testOriginNotFoundArmsTheMarkerAndAnswers404(): void
    {
        $response = $this->get('/images/missing.png');

        self::assertErrorResponse($response, 404, 'Cannot read remote origin file', '/images/missing.png');
        self::assertCount(1, $this->negativeMarkers());
        self::assertFileDoesNotExist($this->publicPath('images/missing.png.404'));
        self::assertFileDoesNotExist($this->publicPath('images/missing.png'));
        self::assertFileDoesNotExist($this->publicPath('images/missing.png.netfail'));
    }

    public function testFreshMarkerAnswersWithoutContactingTheOrigin(): void
    {
        $this->get('/images/missing.png');
        $response = $this->get('/images/missing.png');

        self::assertErrorResponse($response, 404, 'Cannot read remote origin file', '/images/missing.png');
        self::assertSame(1, $this->origin->countRequestsFor('/images/missing.png'));
    }

    public function testExpiredMarkerIsRetriedAndRemovedOnSuccess(): void
    {
        $this->origin->setRule('/images/gradient.png', 404);
        $this->get('/images/gradient.png');
        $this->origin->removeRule('/images/gradient.png');

        self::assertSame(404, $this->get('/images/gradient.png')->getStatus());
        self::assertSame(1, $this->origin->countRequestsFor('/images/gradient.png'));

        $markers = $this->negativeMarkers();
        self::assertCount(1, $markers);
        self::age($markers[0], 301);
        $response = $this->get('/images/gradient.png');

        self::assertSame(200, $response->getStatus());
        self::assertSame('miss', $response->getHeader('X-Cache-Status'));
        self::assertSame([], $this->negativeMarkers());
        self::assertSame(2, $this->origin->countRequestsFor('/images/gradient.png'));
    }

    public function testMarkerOfAQueryStringRequestIsKeyedOnItsBucket(): void
    {
        $this->origin->setRule('/images/gradient.png', 404);
        $this->get('/images/gradient.png?v=3');
        $this->origin->removeRule('/images/gradient.png');

        self::assertCount(1, $this->negativeMarkers());
        self::assertSame(404, $this->get('/images/gradient.png?v=3')->getStatus());
        self::assertSame(200, $this->get('/images/gradient.png?v=4')->getStatus());
        self::assertDirectoryDoesNotExist(dirname($this->bucketPath('v=3', 'images/gradient.png')));
    }

    /**
     * A path that does not exist creates no directory and no file below
     * public/, and the markers are bounded (finding P-5: 2.x created the
     * directories of every such path, and a ".404" marker in them, so
     * random paths filled the disk).
     *
     * @group regression
     */
    #[Group('regression')]
    public function testNotFoundCreatesNothingBelowTheDocumentRootP5(): void
    {
        $before = self::tree($this->publicPath(''));

        $response = $this->get('/no/such/directory/file.png');
        $this->get('/no/such/other/file.png?v=1');

        self::assertSame(404, $response->getStatus());
        self::assertDirectoryDoesNotExist($this->publicPath('no'));
        self::assertSame($before, self::tree($this->publicPath('')));
        self::assertCount(2, $this->negativeMarkers());
    }

    /**
     * @return list<string>
     */
    private static function tree(string $directory): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        return $paths;
    }
}
