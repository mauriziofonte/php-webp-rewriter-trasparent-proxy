<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The anonymous ?purge= and ?purge_all= of 2.x, now refused (risk L-7).
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class PurgeTest extends CharacterizationTestCase
{
    private const SECRET = 'purge-secret-0123456789';

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
     * The anonymous purge of 2.x purges nothing and answers a 401 that
     * explains how to purge (risk L-7: 2.x removed the entry for anyone
     * and fetched it again).
     *
     * @dataProvider providePurgeFlags
     *
     * @group regression
     */
    #[DataProvider('providePurgeFlags')]
    #[Group('regression')]
    public function testAnonymousPurgeIsRefusedWithGuidanceL7(string $flag): void
    {
        $this->get('/images/gradient.png?v=1');
        file_put_contents($this->bucketPath('v=1', 'images/gradient.png'), 'cached');

        $response = $this->get('/images/gradient.png?v=1&' . $flag);

        self::assertSame(401, $response->getStatus());
        self::assertSame('Bearer realm="proxy-cdn purge"', $response->getHeader('WWW-Authenticate'));
        self::assertStringContainsString('PURGE_KEYS', $response->getBody());
        self::assertStringEqualsFile($this->bucketPath('v=1', 'images/gradient.png'), 'cached');
        self::assertSame(1, $this->origin->countRequestsFor('/images/gradient.png'));
    }

    /**
     * The built-in server of PHP answers 501 to the PURGE method before the
     * proxy runs, so PURGE itself is tested on the kernel
     * (ProxyKernelTest); Apache and nginx pass it to index.php.
     */
    public function testTheBuiltInServerDoesNotPassPurge(): void
    {
        $response = $this->send('PURGE', '/images/gradient.png', ['Authorization' => 'Bearer ' . self::SECRET]);

        self::assertSame(501, $response->getStatus());
        self::assertSame([], $this->origin->getRequests());
    }

    protected function configuration(): array
    {
        return array_merge(parent::configuration(), ['PURGE_KEYS' => '"127.0.0.1=' . self::SECRET . '"']);
    }
}
