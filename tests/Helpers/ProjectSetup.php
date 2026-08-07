<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectSetupStep;
use App\Models\Project;
use App\Models\ProjectSetupProgress;

/**
 * Complete the external-integration prerequisite for tests whose subject is a
 * later project setup step.
 *
 * This helper intentionally updates only wizard progress. It does not fabricate
 * credentials, provider responses, integration evidence, audit records, or
 * configuration revisions because those concerns are outside these tests.
 */
function completeProjectIntegrationSetupForTesting(Project $project): void
{
    $progress = ProjectSetupProgress::query()
        ->where('project_id', $project->id)
        ->firstOrFail();

    /*
     * Preserve the same prerequisite ordering enforced by the production
     * project setup workflow.
     */
    if (! $progress->hasCompleted(ProjectSetupStep::Repository)) {
        throw new LogicException(
            'Complete the Repository setup fixture before the Integrations fixture.',
        );
    }

    $completedSteps = $progress->completed_steps;

    /*
     * Make repeated fixture arrangement idempotent.
     */
    if (
        ! in_array(
            ProjectSetupStep::Integrations->value,
            $completedSteps,
            true,
        )
    ) {
        $completedSteps[] = ProjectSetupStep::Integrations->value;
    }

    $currentStep = $progress->current_step;

    /*
     * Advance to Commands without moving an already-later fixture backward.
     */
    if (
        $currentStep->position()
        < ProjectSetupStep::Commands->position()
    ) {
        $currentStep = ProjectSetupStep::Commands;
    }

    $progress->forceFill([
        'current_step' => $currentStep,
        'completed_steps' => array_values(
            array_unique($completedSteps),
        ),
    ])->save();
}
