<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Log;

use Psr\Log\AbstractLogger;

/**
 * Logger written the way plan section 4 prescribes for the project's loggers:
 * it extends AbstractLogger and declares log() so that the same class
 * satisfies psr/log 1, 2 and 3.
 */
final class MemoryLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<mixed>}>
     */
    private $records = [];

    /**
     * @param mixed              $level
     * @param string|\Stringable $message
     * @param array<mixed>       $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<mixed>}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }
}
