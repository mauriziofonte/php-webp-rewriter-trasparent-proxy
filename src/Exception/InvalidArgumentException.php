<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Exception;

/**
 * A value given to a constructor or a with*() method is invalid: a
 * programming or integration error, never a runtime condition of a request.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements ExceptionInterface
{
}
