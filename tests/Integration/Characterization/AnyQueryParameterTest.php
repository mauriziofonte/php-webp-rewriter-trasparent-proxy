<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Query strings with QUERY_STRING_ALLOWLIST="*", which keeps every
 * parameter as 2.x did.
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class AnyQueryParameterTest extends CharacterizationTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideQueryStrings(): array
    {
        return [
            'dots are kept' => ['a.b=1', 'a.b=1'],
            'encoded spaces are kept' => ['a%20b=1', 'a%20b=1'],
            'parameters are not sorted' => ['z=1&y=2', 'y=2&z=1'],
            'escapes are kept' => ['q=a%2fb', 'q=a%2Fb'],
        ];
    }

    /**
     * The origin receives the query string as the client wrote it; only the
     * cache key is canonical (finding P-9: 2.x parsed it with parse_str()
     * and forwarded "a_b=1" for "a.b=1", sorted and re-encoded).
     *
     * @dataProvider provideQueryStrings
     *
     * @group regression
     */
    #[DataProvider('provideQueryStrings')]
    #[Group('regression')]
    public function testTheOriginalQueryStringReachesTheOriginP9(string $sent, string $canonical): void
    {
        $response = $this->get('/images/gradient.png?' . $sent);
        $hit = $this->get('/images/gradient.png?' . $sent);

        self::assertSame(200, $response->getStatus());
        self::assertSame('/images/gradient.png?' . $sent, $this->origin->getLastRequest()['uri']);
        self::assertFileExists($this->bucketPath($canonical, 'images/gradient.png'));
        self::assertSame('HIT', $hit->getHeader('X-Cache-Status'));
        self::assertSame(1, $this->origin->countRequestsFor('/images/gradient.png'));
    }

    public function testEquivalentQueryStringsShareABucket(): void
    {
        $this->get('/images/gradient.png?b=2&a=1');
        $hit = $this->get('/images/gradient.png?a=1&b=2');

        self::assertSame('HIT', $hit->getHeader('X-Cache-Status'));
        self::assertFileExists($this->bucketPath('a=1&b=2', 'images/gradient.png'));
        self::assertSame(1, $this->origin->countRequestsFor('/images/gradient.png'));
    }

    protected function configuration(): array
    {
        return array_merge(parent::configuration(), ['QUERY_STRING_ALLOWLIST' => '"*"']);
    }
}
