<?php

declare(strict_types=1);

namespace App\Application\Workflows\Contracts;

use App\Models\WorkflowInstance;

/**
 * Evaluates stable workflow guard identifiers through deterministic policy.
 */
interface WorkflowTransitionGuardEvaluator
{
    /**
     * Determine whether the named guard permits the transition.
     *
     * Implementations must be deterministic and must not perform external
     * side effects. Throwing or returning false rejects the transition and
     * rolls back the transaction.
     *
     * @param  array<string, mixed>  $context
     */
    public function passes(
        string $guard,
        WorkflowInstance $instance,
        array $context,
    ): bool;
}
