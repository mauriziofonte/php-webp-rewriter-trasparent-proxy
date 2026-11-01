<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The anonymous ?purge= and ?purge_all= query parameters (removed by PRX-5,
 * risk L-7).
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class PurgeTest extends CharacterizationTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function providePurgeFlags(): array
    {
        return [
            'purge=1' => ['purge=1'],
            'purge=true' => ['purge=true'],
            'purge=TRUE' => ['purge=TRUE'],
            'purge_all=1' => ['purge_all=1'],
        ];
    }

    /**
     * Anyone can purge (finding L-7, resolved by PRX-5): the entry is
     * removed, fetched again without the flag and stored again.
     *
     * @dataProvider providePurgeFlags
     */
    #[DataProvider('providePurgeFlags')]
    public function testAnonymousPurgeRefetchesTheEntry(string $flag): void
    {
        $this->get('/images/gradient.png?v=1');
        file_put_contents($this->bucketPath('v=1', 'images/gradient.png'), 'stale');

        $response = $this->get('/images/gradient.png?v=1&' . $flag);

        self::assertSame(200, $response->getStatus());
        self::assertSame('miss', $response->getHeader('X-Cache-Status'));
        self::assertSame(self::fixture('images/gradient.png'), $response->getBody());
        self::assertStringEqualsFile($this->bucketPath('v=1', 'images/gradient.png'), self::fixture('images/gradient.png'));
        self::assertSame('/images/gradient.png?v=1', $this->origin->getLastRequest()['uri']);
        self::assertSame(2, $this->origin->countRequestsFor('/images/gradient.png'));
    }

    public function testPurgeOnlyQueryStringKeysOnThePathWithoutBucket(): void
    {
        $response = $this->get('/images/gradient.png?purge=1');

        self::assertSame(200, $response->getStatus());
        self::assertSame('/images/gradient.png', $this->origin->getLastRequest()['uri']);
        self::assertFileExists($this->publicPath('images/gradient.png'));
        self::assertDirectoryDoesNotExist($this->publicPath('_qs'));
    }

    public function testPurgeClearsBothMarkers(): void
    {
        $this->origin->setRule('/images/gradient.png', 404);
        $this->get('/images/gradient.png');
        $this->origin->removeRule('/images/gradient.png');
        self::assertCount(1, $this->negativeMarkers());
        mkdir($this->publicPath('images'));
        touch($this->publicPath('images/gradient.png.netfail'));

        $response = $this->get('/images/gradient.png?purge=1');

        self::assertSame(200, $response->getStatus());
        self::assertSame([], $this->negativeMarkers());
        self::assertFileDoesNotExist($this->publicPath('images/gradient.png.netfail'));
    }

    public function testPurgeKeepsSiblingRepresentations(): void
    {
        $siblings = $this->createSiblings();

        $this->get('/images/gradient.png?purge=1');

        foreach ($siblings as $sibling) {
            self::assertFileExists($sibling);
        }
    }

    public function testPurgeAllRemovesSiblingRepresentationsAndTheirMarkers(): void
    {
        $siblings = $this->createSiblings();

        $this->get('/images/gradient.png?purge_all=1');

        foreach ($siblings as $sibling) {
            self::assertFileDoesNotExist($sibling);
        }
        self::assertFileExists($this->publicPath('images/gradient.css'));
    }

    /**
     * A purge applies to its own bucket only (finding P-16, resolved by
     * PRX-7): the entries cached for other query strings stay.
     */
    public function testPurgeIgnoresQueryStringVariantsP16(): void
    {
        $this->get('/images/gradient.png?v=1');

        $this->get('/images/gradient.png?purge_all=1');

        self::assertFileExists($this->bucketPath('v=1', 'images/gradient.png'));
        self::assertSame('hit', $this->get('/images/gradient.png?v=1')->getHeader('X-Cache-Status'));
    }

    /**
     * @return list<string>
     */
    private function createSiblings(): array
    {
        mkdir($this->publicPath('images'));
        $siblings = [];
        foreach (['webp', 'jpg', 'jpeg', 'gif', 'bmp'] as $extension) {
            $siblings[] = $this->publicPath('images/gradient.' . $extension);
            $siblings[] = $this->publicPath('images/gradient.' . $extension . '.netfail');
        }
        foreach ($siblings as $sibling) {
            file_put_contents($sibling, 'sibling');
        }
        file_put_contents($this->publicPath('images/gradient.css'), 'not a sibling');

        return $siblings;
    }
}
