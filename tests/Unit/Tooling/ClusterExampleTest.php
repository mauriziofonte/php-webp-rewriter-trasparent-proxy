<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Unit\Tooling;

use Mfonte\ProxyCdn\Config\ConfigLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The configuration of the multi-instance example (docker/cluster) is valid
 * for each node, and each node lists the other as its peer.
 *
 * @coversNothing
 */
#[CoversNothing]
final class ClusterExampleTest extends TestCase
{
    public function testEachNodeHasAValidConfigurationWithTheOtherAsPeer(): void
    {
        $root = dirname(__DIR__, 3) . '/docker/cluster';
        $compose = (string) file_get_contents($root . '/compose.yaml');
        self::assertSame(['https://node-b:8443', 'https://node-a:8443'], self::peers($compose));

        foreach (self::peers($compose) as $peer) {
            $loader = new ConfigLoader($root . '/node.env', null, static function (string $name) use ($peer): ?string {
                return $name === 'CLUSTER_PEERS' ? $peer : null;
            });
            $config = $loader->load();

            self::assertSame([$peer], $config->getOperations()->getClusterPeers());
            self::assertSame('/etc/proxy-cdn/ca.pem', $config->getOperations()->getClusterCaFile());
            self::assertNotSame([], $config->getPurgeKeys());
        }
    }

    /**
     * @return list<string>
     */
    private static function peers(string $compose): array
    {
        preg_match_all('/CLUSTER_PEERS: (\S+)/', $compose, $matches);

        return $matches[1];
    }
}
