<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * Where the configuration comes from, and when a change is applied.
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class ConfigurationSourcesTest extends CharacterizationTestCase
{
    /**
     * The configuration compiled on the first request is never refreshed:
     * a change of .env has no effect (finding P-20, resolved by PRX-3).
     */
    public function testChangedEnvFileIsIgnoredP20(): void
    {
        self::assertSame(200, $this->get('/images/gradient.png')->getStatus());

        $this->writeEnvFile(['CACHEABLE_EXTENSIONS' => '"css"'] + $this->configuration());

        self::assertSame(200, $this->get('/images/gradient.png?v=2')->getStatus());
    }

    /**
     * Without .env the proxy refuses to start, and answers with a bare
     * message and status 200 (finding P-20, resolved by PRX-3).
     */
    public function testEnvFileIsMandatoryP20(): void
    {
        unlink($this->proxy->getProjectDirectory() . '/.env');

        $response = $this->get('/images/gradient.png');

        self::assertSame(200, $response->getStatus());
        self::assertSame(
            'Cannot find application .env. Please, read the attached README.md for instructions on how to install this application',
            $response->getBody()
        );
    }

    /**
     * @param array<string, string|null> $values
     */
    private function writeEnvFile(array $values): void
    {
        $file = $this->proxy->getProjectDirectory() . '/.env';
        file_put_contents($file, $this->dotEnv($values) . "# changed\n");
        // A different modification time, as an edit a second later would leave.
        touch($file, time() + 1);
        clearstatcache();
    }
}
