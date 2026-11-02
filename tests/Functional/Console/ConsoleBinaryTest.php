<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Functional\Console;

use PHPUnit\Framework\Attributes\CoversNothing;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * bin/proxy-cdn registers the operations commands.
 *
 * @coversNothing
 */
#[CoversNothing]
final class ConsoleBinaryTest extends TestCase
{
    public function testTheCacheCommandsAreRegistered(): void
    {
        $root = dirname(__DIR__, 3);
        $process = proc_open(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/proxy-cdn') . ' list --raw',
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output);

        foreach (['check-env', 'cache:purge'] as $command) {
            self::assertMatchesRegularExpression('/^' . preg_quote($command, '/') . '\s/m', $output);
        }
    }
}
