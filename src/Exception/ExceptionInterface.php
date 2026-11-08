<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Exception;

/**
 * Implemented by every exception thrown by this package, so that a host
 * application embedding the proxy can catch all of them at once.
 */
interface ExceptionInterface extends \Throwable
{
}
