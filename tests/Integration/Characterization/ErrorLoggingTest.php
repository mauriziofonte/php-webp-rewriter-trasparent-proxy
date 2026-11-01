<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * logs/proxy.log with DEBUG disabled: errors only. Only the presence of the
 * events is pinned; their format is the logger's.
 *
 * @coversNothing
 *
 * @group characterization
 */
#[CoversNothing]
#[Group('characterization')]
final class ErrorLoggingTest extends CharacterizationTestCase
{
    public function testOriginFailuresAreLoggedAndInformationalEventsAreNot(): void
    {
        $this->get('/images/missing.png');
        $this->get('/images/gradient.png?purge=1');
        self::assertFileDoesNotExist($this->logFile());

        $this->origin->setRule('/js/app.js', 500);
        $this->get('/js/app.js');
        $this->origin->stop();
        $this->get('/css/site.css');

        $log = (string) file_get_contents($this->logFile());
        self::assertStringContainsString('upstream non-200', $log);
        self::assertStringContainsString($this->origin->getBaseUrl() . '/js/app.js', $log);
        self::assertStringContainsString('upstream fetch failed', $log);
        self::assertStringContainsString($this->origin->getBaseUrl() . '/css/site.css', $log);
        self::assertStringContainsString('connect', $log);
        self::assertStringNotContainsString('upstream 404', $log);
    }

    /**
     * "Host: bad host" makes Symfony throw: the front controller answered
     * 500 and logged an error for what is the client's mistake.
     */
    public function testAnInvalidHostIsABadRequestThatLogsNothing(): void
    {
        $response = $this->get('/images/gradient.png', ['Host' => 'bad host']);

        self::assertSame(400, $response->getStatus());
        self::assertStringContainsString('Error code: Invalid Host header.', $response->getBody());
        self::assertSame(0, $this->origin->countRequestsFor('/images/gradient.png'));
        self::assertFileDoesNotExist($this->logFile());
    }

    private function logFile(): string
    {
        return $this->proxy->getProjectDirectory() . '/logs/proxy.log';
    }
}
