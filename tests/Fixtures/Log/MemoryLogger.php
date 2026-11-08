<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Tests\Fixtures\Log;

use Psr\Log\AbstractLogger;

/**
 * Keeps every log entry in memory, for assertions.
 */
final class MemoryLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<mixed>}>
     */
    private $records = [];

    /**
     * @param mixed              $level
     * @param string|\Stringable $message
     * @param array<mixed>       $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => is_string($level) ? $level : '', 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * The entries with $message, oldest first.
     *
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    public function recordsWithMessage(string $message): array
    {
        $records = [];
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                $records[] = $record;
            }
        }

        return $records;
    }
}
