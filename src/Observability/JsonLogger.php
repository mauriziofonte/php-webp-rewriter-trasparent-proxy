<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Observability;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * A PSR-3 logger that appends one JSON object per line to a file:
 *
 *     {"timestamp":"2026-10-30T10:00:00.123456+00:00","level":"error",
 *      "channel":"proxy-cdn","message":"upstream non-200","context":{...}}
 *
 * Placeholders such as "{url}" in the message are replaced with the context
 * values, as PSR-3 describes. Entries below the minimum level are dropped.
 * The directory of the file is created on the first write. Writing never
 * throws and never prints: a log that cannot be written must not break the
 * response.
 *
 * The signature of log() is compatible with psr/log 1, 2 and 3: the
 * parameters are untyped, which widens those of the interface, and the
 * return type is void.
 */
final class JsonLogger extends AbstractLogger
{
    public const DEFAULT_CHANNEL = 'proxy-cdn';

    /**
     * Severity of each level, most severe first.
     */
    private const SEVERITIES = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT => 1,
        LogLevel::CRITICAL => 2,
        LogLevel::ERROR => 3,
        LogLevel::WARNING => 4,
        LogLevel::NOTICE => 5,
        LogLevel::INFO => 6,
        LogLevel::DEBUG => 7,
    ];

    /**
     * Nesting depth after which context values are summarised.
     */
    private const MAX_DEPTH = 8;

    /**
     * @var string
     */
    private $file;

    /**
     * @var int
     */
    private $minimumSeverity;

    /**
     * @var string
     */
    private $channel;

    /**
     * @param string $file         absolute path of the log file
     * @param string $minimumLevel one of the LogLevel constants: entries less severe are dropped
     * @param string $channel      the "channel" of every entry
     *
     * @throws InvalidArgumentException when $minimumLevel is not a PSR-3 level
     */
    public function __construct(string $file, string $minimumLevel = LogLevel::DEBUG, string $channel = self::DEFAULT_CHANNEL)
    {
        $this->file = $file;
        $this->minimumSeverity = self::severityOf($minimumLevel);
        $this->channel = $channel;
    }

    /**
     * @param mixed              $level   one of the LogLevel constants
     * @param string|\Stringable $message may contain {placeholders} for the context values
     * @param array<mixed>       $context
     *
     * @throws InvalidArgumentException when $level is not a PSR-3 level
     */
    public function log($level, $message, array $context = []): void
    {
        $level = is_string($level) ? $level : '';
        if (self::severityOf($level) > $this->minimumSeverity) {
            return;
        }

        $line = json_encode(
            [
                'timestamp' => (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:s.uP'),
                'level' => $level,
                'channel' => $this->channel,
                'message' => self::interpolate(self::stringOf($message), $context),
                'context' => (object) self::normaliseArray($context, 0),
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        if ($line === false) {
            return;
        }

        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return;
        }
        @file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function severityOf(string $level): int
    {
        if (!isset(self::SEVERITIES[$level])) {
            throw new InvalidArgumentException('Unknown log level: ' . $level);
        }

        return self::SEVERITIES[$level];
    }

    /**
     * @param mixed $message
     */
    private static function stringOf($message): string
    {
        if (is_string($message)) {
            return $message;
        }
        if (is_scalar($message) || (is_object($message) && method_exists($message, '__toString'))) {
            return (string) $message;
        }

        return '';
    }

    /**
     * Replaces each "{key}" with the context value of key, when it can be
     * written as a string.
     *
     * @param array<mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        if (strpos($message, '{') === false) {
            return $message;
        }
        $replacements = [];
        foreach ($context as $key => $value) {
            if ($value === null || is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $replacements['{' . $key . '}'] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            } elseif ($value instanceof \DateTimeInterface) {
                $replacements['{' . $key . '}'] = $value->format(\DateTimeInterface::RFC3339);
            }
        }

        return strtr($message, $replacements);
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private static function normaliseArray(array $values, int $depth): array
    {
        $normalised = [];
        foreach ($values as $key => $value) {
            $normalised[$key] = self::normalise($value, $depth + 1);
        }

        return $normalised;
    }

    /**
     * A JSON-friendly copy of a context value.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private static function normalise($value, int $depth)
    {
        if ($value === null || is_scalar($value)) {
            return is_float($value) && !is_finite($value) ? (string) $value : $value;
        }
        if ($depth > self::MAX_DEPTH) {
            return '[...]';
        }
        if (is_array($value)) {
            return self::normaliseArray($value, $depth);
        }
        if ($value instanceof \Throwable) {
            $exception = [
                'class' => get_class($value),
                'message' => $value->getMessage(),
                'code' => $value->getCode(),
                'file' => $value->getFile() . ':' . $value->getLine(),
            ];
            $previous = $value->getPrevious();
            if ($previous !== null) {
                $exception['previous'] = self::normalise($previous, $depth + 1);
            }

            return $exception;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::RFC3339);
        }
        if ($value instanceof \JsonSerializable) {
            return self::normalise($value->jsonSerialize(), $depth + 1);
        }
        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : '[object ' . get_class($value) . ']';
        }

        return '[' . gettype($value) . ']';
    }
}
