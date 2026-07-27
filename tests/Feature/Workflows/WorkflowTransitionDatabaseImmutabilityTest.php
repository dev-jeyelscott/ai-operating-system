<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Application\Workflows\CreateWorkflowInstance;
use App\Application\Workflows\RegisterWorkflowDefinition;
use App\Application\Workflows\TransitionWorkflowInstance;
use App\Domain\Workflows\WorkflowDefinitionManifest;
use App\Models\Project;
use App\Models\WorkflowTransition;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\ProcessDatabaseTestCase;

/**
 * Verifies transition immutability at the PostgreSQL boundary.
 *
 * These tests use process-level database isolation because expected
 * PostgreSQL failures would abort a RefreshDatabase transaction.
 */
final class WorkflowTransitionDatabaseImmutabilityTest extends ProcessDatabaseTestCase
{
    /**
     * Ensure raw updates cannot rewrite committed transition history.
     */
    public function test_postgresql_rejects_direct_updates(): void
    {
        $transition = $this->createTransition();

        try {
            DB::table('workflow_transitions')
                ->where('id', $transition->id)
                ->update([
                    'to_state' => 'failed',
                ]);

            self::fail(
                'PostgreSQL unexpectedly allowed a workflow-transition update.',
            );
        } catch (QueryException $exception) {
            self::assertStringContainsString(
                'workflow_transitions is append-only',
                $exception->getMessage(),
            );
        }

        self::assertDatabaseHas('workflow_transitions', [
            'id' => $transition->id,
            'from_state' => 'queued',
            'to_state' => 'running',
        ]);
    }

    /**
     * Ensure raw deletes cannot remove committed transition history.
     */
    public function test_postgresql_rejects_direct_deletes(): void
    {
        $transition = $this->createTransition();

        try {
            DB::table('workflow_transitions')
                ->where('id', $transition->id)
                ->delete();

            self::fail(
                'PostgreSQL unexpectedly allowed a workflow-transition deletion.',
            );
        } catch (QueryException $exception) {
            self::assertStringContainsString(
                'workflow_transitions is append-only',
                $exception->getMessage(),
            );
        }

        self::assertDatabaseHas('workflow_transitions', [
            'id' => $transition->id,
            'name' => 'start',
        ]);
    }

    /**
     * Create one real transition through the application state machine.
     */
    private function createTransition(): WorkflowTransition
    {
        $project = Project::factory()->create();

        app(RegisterWorkflowDefinition::class)->handle(
            new WorkflowDefinitionManifest(
                definitionKey: 'project_delivery',
                version: 1,
                schemaVersion: 1,
                name: 'Project delivery workflow',
                description: 'Controls deterministic project delivery.',
                initialState: 'queued',
                states: [
                    'queued',
                    'running',
                    'completed',
                    'failed',
                ],
                terminalStates: [
                    'completed',
                    'failed',
                ],
                transitions: [
                    [
                        'name' => 'start',
                        'from' => 'queued',
                        'to' => 'running',
                        'guard' => null,
                    ],
                    [
                        'name' => 'complete',
                        'from' => 'running',
                        'to' => 'completed',
                        'guard' => 'required_outputs_exist',
                    ],
                    [
                        'name' => 'fail',
                        'from' => 'running',
                        'to' => 'failed',
                        'guard' => null,
                    ],
                ],
            ),
        );

        $instance = app(CreateWorkflowInstance::class)->handle(
            project: $project,
            definitionKey: 'project_delivery',
            version: 1,
        );

        app(TransitionWorkflowInstance::class)->handle(
            instance: $instance,
            transitionName: 'start',
        );

        return WorkflowTransition::query()->firstOrFail();
    }
}
