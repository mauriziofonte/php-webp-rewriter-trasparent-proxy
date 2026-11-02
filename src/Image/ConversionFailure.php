<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image;

use Mfonte\ProxyCdn\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface as LibraryException;

/**
 * A conversion an ImageConverter could not make. The proxy then serves
 * the original (finding P-10: 2.x answered 500), and remembers the failure
 * so that it does not convert again on every hit (ConversionFailures).
 *
 * A failure is either
 * - transient: the converter cannot convert now (the conversion API is
 *   unavailable, its circuit breaker is open, every conversion slot is
 *   busy, the isolation the policy requires is missing); another attempt
 *   may succeed soon, and IMAGE_API_FALLBACK converts locally instead;
 * - or about the image: it cannot be converted as asked (unsupported or
 *   malformed input, a limit exceeded, an output that failed its
 *   verification); trying again would fail the same way.
 *
 * The error code of an exception the conversion library threw in this
 * process (fromLibrary()) is getConversionErrorCode() (an ErrorCode
 * constant); the error code the conversion API answered
 * (concurrency_unavailable, rate_limited, conversion_failed...), or that
 * the proxy gave the failure (unreachable, redirected, not_smaller,
 * output_verification_failed...), is getErrorCode(). The shared log
 * schema keeps them apart: logContext() puts them under
 * "conversion_error_code" and "error_code". Messages never hold a path, a
 * key or a signature.
 */
final class ConversionFailure extends \RuntimeException implements ExceptionInterface
{
    /**
     * Log context key of an error code of the conversion library.
     */
    public const LOG_CONVERSION_ERROR_CODE = 'conversion_error_code';

    /**
     * Log context key of any other error code: of the conversion API, or
     * of the proxy.
     */
    public const LOG_ERROR_CODE = 'error_code';

    /**
     * The library codes that mean the converter cannot convert now.
     */
    private const TRANSIENT_LIBRARY_CODES = [ErrorCode::CONCURRENCY_LIMIT, ErrorCode::ISOLATION_UNAVAILABLE];

    /**
     * @var bool
     */
    private $transient;

    /**
     * @var string
     */
    private $converter;

    /**
     * @var string|null
     */
    private $conversionErrorCode;

    /**
     * @var string|null
     */
    private $errorCode;

    /**
     * @var int
     */
    private $status;

    /**
     * @param string $message without paths, keys or signatures
     */
    private function __construct(bool $transient, string $converter, string $message, ?string $errorCode, ?string $conversionErrorCode, int $status, ?\Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
        $this->transient = $transient;
        $this->converter = $converter;
        $this->errorCode = $errorCode;
        $this->conversionErrorCode = $conversionErrorCode;
        $this->status = $status;
    }

    /**
     * The converter cannot convert now.
     *
     * @param string      $converter ImageConverter::getName()
     * @param string      $message   why, without paths, keys or signatures
     * @param string|null $code      the error code of the API or of the proxy
     * @param int         $status    the HTTP status of the API; 0 without one
     */
    public static function unavailable(string $converter, string $message, ?string $code = null, int $status = 0, ?\Throwable $previous = null): self
    {
        return new self(true, $converter, $message, $code, null, $status, $previous);
    }

    /**
     * The image cannot be converted as asked.
     *
     * @param string      $converter ImageConverter::getName()
     * @param string      $message   why, without paths, keys or signatures
     * @param string|null $code      the error code of the API or of the proxy
     * @param int         $status    the HTTP status of the API; 0 without one
     */
    public static function rejected(string $converter, string $message, ?string $code = null, int $status = 0, ?\Throwable $previous = null): self
    {
        return new self(false, $converter, $message, $code, null, $status, $previous);
    }

    /**
     * The failure of an exception of the conversion library: transient for
     * concurrency_limit and isolation_unavailable, about the image
     * otherwise. Library messages hold no path (ExceptionInterface).
     */
    public static function fromLibrary(string $converter, LibraryException $exception): self
    {
        $code = $exception->getErrorCode();

        return new self(in_array($code, self::TRANSIENT_LIBRARY_CODES, true), $converter, $exception->getMessage(), null, $code, 0, $exception);
    }

    /**
     * Whether another attempt may succeed soon.
     */
    public function isTransient(): bool
    {
        return $this->transient;
    }

    /**
     * The converter that failed: ImageConverter::getName().
     */
    public function getConverter(): string
    {
        return $this->converter;
    }

    /**
     * The error code of the conversion library (an ErrorCode constant);
     * null when the failure has none.
     */
    public function getConversionErrorCode(): ?string
    {
        return $this->conversionErrorCode;
    }

    /**
     * The error code of the conversion API, or of the proxy's client of
     * it; null when the failure has none.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * The HTTP status the conversion API answered; 0 without one.
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * The fields of the failure for a log entry, in the shared log schema.
     *
     * @return array<string, int|string|bool>
     */
    public function logContext(): array
    {
        $context = ['converter' => $this->converter, 'transient' => $this->transient, 'reason' => $this->getMessage()];
        if ($this->conversionErrorCode !== null) {
            $context[self::LOG_CONVERSION_ERROR_CODE] = $this->conversionErrorCode;
        }
        if ($this->errorCode !== null) {
            $context[self::LOG_ERROR_CODE] = $this->errorCode;
        }
        if ($this->status !== 0) {
            $context['status'] = $this->status;
        }

        return $context;
    }
}
