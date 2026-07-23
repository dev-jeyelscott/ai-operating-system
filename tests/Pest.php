<?php

use App\Domain\Projects\ProjectSetupStep;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Advance a project fixture beyond the external Integrations setup step.
 *
 * Command and policy tests are not responsible for testing the Notion API.
 * Their fixtures may therefore arrange persisted wizard progress directly,
 * provided the required Details and Repository steps are already complete.
 */
function completeProjectIntegrationSetupForTesting(Project $project): void
{
    $progress = $project->setupProgress()->firstOrFail();

    /*
     * Protect tests from silently constructing an impossible setup state.
     */
    foreach (
        [
            ProjectSetupStep::Details,
            ProjectSetupStep::Repository,
        ] as $requiredStep
    ) {
        if (! $progress->hasCompleted($requiredStep)) {
            throw new LogicException(sprintf(
                'Complete the "%s" setup step before bypassing Integrations.',
                $requiredStep->value,
            ));
        }
    }

    $completedSteps = $progress->completed_steps;

    if (
        ! in_array(
            ProjectSetupStep::Integrations->value,
            $completedSteps,
            true,
        )
    ) {
        $completedSteps[] = ProjectSetupStep::Integrations->value;
    }

    /*
     * Persist the minimum valid state required by command and policy tests.
     */
    $progress->forceFill([
        'completed_steps' => array_values(array_unique($completedSteps)),
        'current_step' => ProjectSetupStep::Commands,
    ])->save();
}
