<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * logs/proxy.log with DEBUG enabled: informational events (origin 404s)
 * are logged next to the errors. Only the presence of the events is
 * pinned; their format is the logger's.
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class DebugLoggingTest extends CharacterizationTestCase
{
    protected function configuration(): array
    {
        return ['DEBUG' => 'true'] + parent::configuration();
    }

    public function testInformationalEventsAreLogged(): void
    {
        $this->get('/images/missing.png');
        $this->origin->setRule('/js/app.js', 500);
        $this->get('/js/app.js');

        $log = $this->log();
        self::assertStringContainsString('upstream 404', $log);
        self::assertStringContainsString($this->origin->getBaseUrl() . '/images/missing.png', $log);
        self::assertStringContainsString('upstream non-200', $log);
        self::assertStringContainsString($this->origin->getBaseUrl() . '/js/app.js', $log);
    }

    private function log(): string
    {
        $log = @file_get_contents($this->proxy->getProjectDirectory() . '/logs/proxy.log');

        return $log === false ? '' : $log;
    }
}
