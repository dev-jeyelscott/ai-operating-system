<?php

declare(strict_types=1);

namespace App\Infrastructure\Workflows;

use App\Application\Workflows\Contracts\WorkflowTransitionGuardEvaluator;
use App\Models\WorkflowInstance;

/**
 * Rejects guarded transitions until a deterministic guard is registered.
 *
 * Unguarded transitions never call this evaluator.
 */
final class DenyAllWorkflowTransitionGuardEvaluator implements WorkflowTransitionGuardEvaluator
{
    /**
     * Fail closed for every guard that has no approved implementation.
     *
     * @param  array<string, mixed>  $context
     */
    public function passes(
        string $guard,
        WorkflowInstance $instance,
        array $context,
    ): bool {
        return false;
    }
}
