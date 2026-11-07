<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Http;

use Mfonte\ProxyCdn\Cache\FilesystemStorage;
use Mfonte\ProxyCdn\Cache\RequestCoalescer;
use Mfonte\ProxyCdn\Http\ProxyKernel;
use Mfonte\SecureImageConverter\Concurrency\KeyedLock;
use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Psr\Log\LoggerInterface;

/**
 * The seams of the tests on the request coalescing of a kernel built by
 * TestKernel: a coalescer with a clock of the test in place of the
 * kernel's own, and the lock of an entry, held or looked at from the test
 * (a second KeyedLock on the same directory opens its own file
 * description, so flock() sees two holders).
 */
final class CoalescerSeam
{
    /**
     * Gives $kernel a coalescer of the lock and outcome directories of
     * $stateDirectory that reads the time from $clock and waits at most
     * $timeout seconds for a leader.
     *
     * @param string          $stateDirectory the state directory of the kernel (TestKernel::STATE_DIRECTORY)
     * @param callable(): int $clock
     */
    public static function replace(ProxyKernel $kernel, string $stateDirectory, LoggerInterface $logger, callable $clock, float $timeout = 5.0): void
    {
        $coalescer = new RequestCoalescer(self::lock($stateDirectory), $timeout, $logger, new FilesystemStorage(), $stateDirectory . '/coalescing', RequestCoalescer::OUTCOME_WINDOW, null, $clock);
        $replace = \Closure::bind(static function (ProxyKernel $kernel, RequestCoalescer $coalescer): void {
            $kernel->coalescer = $coalescer;
        }, null, ProxyKernel::class);
        $replace($kernel, $coalescer);
    }

    /**
     * The keyed lock of the origin fetches of a kernel.
     */
    public static function lock(string $stateDirectory): KeyedLock
    {
        return new KeyedLock(new LockDirectory($stateDirectory . '/locks'), RequestCoalescer::LOCK_NAME);
    }

    /**
     * Whether a request holds the lock of the entry whose outcome the
     * coalescer recorded (the only one of the test).
     */
    public static function isOutcomeEntryLocked(string $stateDirectory): bool
    {
        foreach (glob($stateDirectory . '/coalescing/*.outcome') ?: [] as $slot) {
            $hash = trim(explode(' ', (string) file_get_contents($slot))[2] ?? '');
            $handle = fopen($stateDirectory . '/locks/keyed.' . RequestCoalescer::LOCK_NAME . '.' . $hash . '.lock', 'c');
            if ($handle === false) {
                continue;
            }
            $held = !flock($handle, LOCK_EX | LOCK_NB);
            fclose($handle);
            if ($held) {
                return true;
            }
        }

        return false;
    }
}
