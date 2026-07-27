<?php

declare(strict_types=1);

namespace App\Infrastructure\Workflows;

use App\Application\Workflows\Contracts\WorkflowTransitionGuardEvaluator;
use App\Models\WorkflowInstance;

final class RoadmapWorkflowTransitionGuardEvaluator implements WorkflowTransitionGuardEvaluator
{
    public function passes(string $guard, WorkflowInstance $instance, array $context = []): bool
    {
        return match ($guard) {
            'roadmap.approved', 'roadmap.rejected', 'roadmap.regeneration_requested' => ($context[$guard] ?? false) === true,
            default => false,
        };
    }
}
