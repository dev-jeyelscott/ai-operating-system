<?php

declare(strict_types=1);

namespace App\Application\Shared\Exceptions;

use RuntimeException;

final class RetryableOperationException extends RuntimeException
{
    /**
     * Represent a transient failure that the caller may retry after the
     * provided delay.
     */
    public function __construct(
        string $message = 'The operation is temporarily unavailable.',
        public readonly int $retryAfterSeconds = 30,
    ) {
        parent::__construct($message);
    }
}
