<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Config;

/**
 * Outcome of ConfigValidator::validate(): the errors that prevent the proxy
 * from starting and the warnings worth showing to an operator. Immutable.
 */
final class ConfigValidation
{
    /**
     * @var list<string>
     */
    private $errors;

    /**
     * @var list<string>
     */
    private $warnings;

    /**
     * @param list<string> $errors   one sentence per error
     * @param list<string> $warnings one text per warning, starting with
     *                               "WARNING: " or "NOTE: ", possibly on several lines
     */
    public function __construct(array $errors, array $warnings)
    {
        $this->errors = $errors;
        $this->warnings = $warnings;
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * A copy with $error appended to the errors.
     */
    public function withError(string $error): self
    {
        $copy = clone $this;
        $copy->errors[] = $error;

        return $copy;
    }

    /**
     * A copy with $warning appended to the warnings.
     */
    public function withWarning(string $warning): self
    {
        $copy = clone $this;
        $copy->warnings[] = $warning;

        return $copy;
    }
}
