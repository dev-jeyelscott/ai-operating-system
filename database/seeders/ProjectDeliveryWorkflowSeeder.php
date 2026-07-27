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
        $definitions = app(RegisterWorkflowDefinition::class);
        $definitions->handle($this->versionOne());
        $definitions->handle($this->versionTwo());
    }

    private function versionOne(): WorkflowDefinitionManifest
    {
        return new WorkflowDefinitionManifest(
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
        );
    }

    private function versionTwo(): WorkflowDefinitionManifest
    {
        return new WorkflowDefinitionManifest(
            definitionKey: 'project_delivery',
            version: 2,
            schemaVersion: 1,
            name: 'Project Delivery',
            description: 'Coordinates planning, roadmap approval, regeneration, rejection, development, QA, and completion.',
            initialState: 'planning_queued',
            states: [
                'planning_queued',
                'planning_running',
                'awaiting_roadmap_approval',
                'documents_pending',
                'ready_for_development',
                'active',
                'blocked',
                'completed',
                'cancelled',
            ],
            terminalStates: ['completed', 'cancelled'],
            transitions: [
                ['name' => 'planning.start', 'from' => 'planning_queued', 'to' => 'planning_running', 'guard' => null],
                ['name' => 'planning.await_approval', 'from' => 'planning_running', 'to' => 'awaiting_roadmap_approval', 'guard' => null],
                ['name' => 'planning.block', 'from' => 'planning_running', 'to' => 'blocked', 'guard' => null],
                ['name' => 'roadmap.approve', 'from' => 'awaiting_roadmap_approval', 'to' => 'ready_for_development', 'guard' => 'roadmap.approved'],
                ['name' => 'roadmap.reject', 'from' => 'awaiting_roadmap_approval', 'to' => 'documents_pending', 'guard' => 'roadmap.rejected'],
                ['name' => 'roadmap.regenerate', 'from' => 'awaiting_roadmap_approval', 'to' => 'planning_queued', 'guard' => 'roadmap.regeneration_requested'],
                ['name' => 'development.start', 'from' => 'ready_for_development', 'to' => 'active', 'guard' => null],
                ['name' => 'project.block', 'from' => 'planning_queued', 'to' => 'blocked', 'guard' => null],
                ['name' => 'project.complete', 'from' => 'active', 'to' => 'completed', 'guard' => 'project.definition_of_done'],
                ['name' => 'project.cancel', 'from' => 'planning_queued', 'to' => 'cancelled', 'guard' => null],
            ],
        );
    }
}
