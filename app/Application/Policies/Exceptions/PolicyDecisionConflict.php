<?php

declare(strict_types=1);

namespace App\Application\Policies\Exceptions;

use App\Domain\Projects\Configuration\ReasoningLevel;
use DomainException;

/**
 * Reports an immutable policy-decision replay or execution-context conflict.
 */
final class PolicyDecisionConflict extends DomainException
{
    /**
     * Build a conflict for an execution created with the wrong requested level.
     */
    public static function requestedReasoningMismatch(
        string $executionId,
        ReasoningLevel $executionRequested,
        ReasoningLevel $resolvedRequested,
    ): self {
        return new self(sprintf(
            'Execution [%s] requested reasoning [%s], but policy resolved [%s].',
            $executionId,
            $executionRequested->value,
            $resolvedRequested->value,
        ));
    }

    /**
     * Build a conflict for a replay that changed immutable policy inputs.
     */
    public static function replayedWithDifferentInputs(
        string $executionId,
    ): self {
        return new self(sprintf(
            'Execution [%s] already has a reasoning decision with different inputs.',
            $executionId,
        ));
    }
}
