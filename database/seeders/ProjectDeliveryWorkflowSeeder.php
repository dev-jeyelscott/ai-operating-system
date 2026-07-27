<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\Workflows\RegisterWorkflowDefinition;
use App\Domain\Workflows\WorkflowDefinitionManifest;
use Illuminate\Database\Seeder;

/**
 * Registers the first immutable project-delivery workflow definition.
 */
final class ProjectDeliveryWorkflowSeeder extends Seeder
{
    /**
     * Register or safely reuse project_delivery version one.
     */
    public function run(): void
    {
        app(RegisterWorkflowDefinition::class)->handle(
            new WorkflowDefinitionManifest(
                definitionKey: 'project_delivery',
                version: 1,
                schemaVersion: 1,
                name: 'Project Delivery',
                description: 'Coordinates planning, roadmap approval, development, QA, and completion.',
                initialState: 'planning_queued',
                states: [
                    'planning_queued',
                    'planning_running',
                    'awaiting_roadmap_approval',
                    'ready_for_development',
                    'active',
                    'blocked',
                    'completed',
                    'cancelled',
                ],
                terminalStates: [
                    'completed',
                    'cancelled',
                ],
                transitions: [
                    [
                        'name' => 'planning.start',
                        'from' => 'planning_queued',
                        'to' => 'planning_running',
                        'guard' => null,
                    ],
                    [
                        'name' => 'planning.await_approval',
                        'from' => 'planning_running',
                        'to' => 'awaiting_roadmap_approval',
                        'guard' => null,
                    ],
                    [
                        'name' => 'roadmap.approve',
                        'from' => 'awaiting_roadmap_approval',
                        'to' => 'ready_for_development',
                        'guard' => 'roadmap.approved',
                    ],
                    [
                        'name' => 'development.start',
                        'from' => 'ready_for_development',
                        'to' => 'active',
                        'guard' => null,
                    ],
                    [
                        'name' => 'project.block',
                        'from' => 'planning_queued',
                        'to' => 'blocked',
                        'guard' => null,
                    ],
                    [
                        'name' => 'project.complete',
                        'from' => 'active',
                        'to' => 'completed',
                        'guard' => 'project.definition_of_done',
                    ],
                    [
                        'name' => 'project.cancel',
                        'from' => 'planning_queued',
                        'to' => 'cancelled',
                        'guard' => null,
                    ],
                ],
            ),
        );
    }
}
