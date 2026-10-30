<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Integration\Characterization;

use Mfonte\ProxyCdn\Tests\Fixtures\Http\ProxyServer;
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
     * A change of .env takes effect on the next request (finding P-20,
     * resolved by PRX-3: 2.x never refreshed the compiled configuration).
     */
    public function testChangedEnvFileTakesEffectP20(): void
    {
        self::assertSame(200, $this->get('/images/gradient.png')->getStatus());

        $this->writeEnvFile(['CACHEABLE_EXTENSIONS' => '"css"'] + $this->configuration());

        self::assertErrorResponse($this->get('/images/gradient.png?v=2'), 404, 'Request cannot be processed', '/images/gradient.png');
    }

    /**
     * Without .env and without configuration in the environment every
     * request gets the configuration error (finding P-20, resolved by PRX-3:
     * 2.x answered a bare message with status 200; the 404 is finding P-21).
     */
    public function testMissingConfigurationIsAConfigurationErrorP20(): void
    {
        unlink($this->proxy->getProjectDirectory() . '/.env');

        $response = $this->get('/images/gradient.png');

        self::assertSame(404, $response->getStatus());
        self::assertMatchesRegularExpression(
            '/\AThe request could not be processed.\nError code: ENV_FILE_LOAD_ERR.\nRay ID: [0-9a-f]{32}\z/',
            $response->getBody()
        );
        $log = (string) file_get_contents($this->proxy->getProjectDirectory() . '/logs/proxy.log');
        self::assertStringContainsString('"message":"invalid configuration"', $log);
        self::assertStringContainsString('SOURCE_ORIGIN must be set', $log);
    }

    /**
     * The environment alone configures the proxy, without .env (plan
     * section 4).
     */
    public function testTheEnvironmentAloneConfiguresTheProxy(): void
    {
        $proxy = ProxyServer::start(
            $this->workDirectory . '/app-environment',
            $this->environment($this->configuration()),
            null
        );

        try {
            $response = $proxy->getClient()->request('GET', '/images/gradient.png');
        } finally {
            $proxy->stop();
        }

        self::assertSame(200, $response->getStatus(), $proxy->getOutput());
        self::assertSame(self::fixture('images/gradient.png'), $response->getBody());
        self::assertFileDoesNotExist($this->workDirectory . '/app-environment/.env');
    }

    /**
     * A variable of the environment wins over the same key of .env.
     */
    public function testTheEnvironmentWinsOverTheEnvFile(): void
    {
        $proxy = ProxyServer::start(
            $this->workDirectory . '/app-both',
            $this->environment(['CACHEABLE_EXTENSIONS' => 'png']),
            $this->dotEnv(['CACHEABLE_EXTENSIONS' => '"css"'] + $this->configuration())
        );

        try {
            $png = $proxy->getClient()->request('GET', '/images/gradient.png');
            $css = $proxy->getClient()->request('GET', '/css/site.css');
        } finally {
            $proxy->stop();
        }

        self::assertSame(200, $png->getStatus(), $proxy->getOutput());
        self::assertSame(404, $css->getStatus());
    }

    /**
     * The environment of a proxy process: PATH plus $values, unquoted.
     *
     * @param array<string, string|null> $values .env values
     *
     * @return array<string, string>
     */
    private function environment(array $values): array
    {
        $environment = ['PATH' => $this->executablePath()];
        foreach ($values as $name => $value) {
            if ($value !== null) {
                $environment[$name] = trim($value, '"');
            }
        }

        return $environment;
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
