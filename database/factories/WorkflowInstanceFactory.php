<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowInstance>
 */
final class WorkflowInstanceFactory extends Factory
{
    /**
     * Define a workflow instance at its definition's initial state.
     *
     * Prefer CreateWorkflowInstance in feature tests that verify actual
     * state-machine behavior. This factory exists for supporting fixtures.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'workflow_definition_id' => WorkflowDefinition::factory(),
            'current_state' => 'queued',
            'transition_sequence' => 0,
            'completed_at' => null,
        ];
    }
}
