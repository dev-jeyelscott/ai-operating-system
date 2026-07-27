<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Exceptions;

use DomainException;

/**
 * Reports a transition rejected by deterministic workflow rules.
 */
final class InvalidWorkflowTransition extends DomainException
{
    /**
     * Build an error for a persisted state absent from the definition.
     */
    public static function unknownCurrentState(string $state): self
    {
        return new self(sprintf(
            'Workflow current state "%s" is absent from its definition.',
            $state,
        ));
    }

    /**
     * Build an error for an attempted transition from a terminal state.
     */
    public static function fromTerminalState(string $state): self
    {
        return new self(sprintf(
            'Workflow state "%s" is terminal and cannot transition.',
            $state,
        ));
    }

    /**
     * Build an error for a transition name absent from the definition.
     */
    public static function notDefined(string $transition): self
    {
        return new self(sprintf(
            'Workflow transition "%s" is not defined.',
            $transition,
        ));
    }

    /**
     * Build an error when the transition does not start at the current state.
     */
    public static function notAllowedFrom(
        string $transition,
        string $currentState,
        string $expectedState,
    ): self {
        return new self(sprintf(
            'Workflow transition "%s" cannot run from state "%s"; expected "%s".',
            $transition,
            $currentState,
            $expectedState,
        ));
    }
}
