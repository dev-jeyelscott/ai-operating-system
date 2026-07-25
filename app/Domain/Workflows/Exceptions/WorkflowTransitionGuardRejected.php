<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Exceptions;

use DomainException;

/**
 * Reports a transition rejected by its required deterministic guard.
 */
final class WorkflowTransitionGuardRejected extends DomainException
{
    /**
     * Build an error for one failed transition guard.
     */
    public static function forTransition(
        string $guard,
        string $transition,
        int $workflowInstanceId,
    ): self {
        return new self(sprintf(
            'Workflow guard "%s" rejected transition "%s" for instance %d.',
            $guard,
            $transition,
            $workflowInstanceId,
        ));
    }
}
