<?php

declare(strict_types=1);

namespace App\Domain\Projects\Exceptions;

use App\Domain\Projects\ProjectStatus;
use DomainException;

/**
 * Raised when code attempts a project lifecycle transition that is not allowed.
 */
final class InvalidProjectStatusTransition extends DomainException
{
    /**
     * Create an exception describing the rejected transition.
     */
    public static function between(
        ProjectStatus $from,
        ProjectStatus $to,
    ): self {
        return new self(
            sprintf(
                'Project status cannot transition from [%s] to [%s].',
                $from->value,
                $to->value,
            ),
        );
    }
}
