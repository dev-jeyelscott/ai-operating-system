<?php

declare(strict_types=1);

namespace App\Application\Shared\Exceptions;

use RuntimeException;

final class ConflictException extends RuntimeException
{
    /**
     * Represent a valid request that conflicts with current application
     * state or an already-applied operation.
     */
    public function __construct(
        string $message = 'The operation conflicts with current state.',
    ) {
        parent::__construct($message);
    }
}
