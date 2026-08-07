<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Application\Workflows\RegisterWorkflowDefinition;
use App\Domain\Workflows\WorkflowDefinitionManifest;
use App\Models\WorkflowDefinition;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\ProcessDatabaseTestCase;

/**
 * Verifies workflow-definition immutability at the PostgreSQL boundary.
 *
 * This test intentionally uses truncation-based database isolation because
 * expected PostgreSQL statement failures would abort a RefreshDatabase
 * transaction and prevent subsequent assertions.
 */
final class WorkflowDefinitionDatabaseImmutabilityTest extends ProcessDatabaseTestCase
{
    /**
     * Ensure direct updates cannot bypass application-level model guards.
     */
    public function test_postgresql_rejects_direct_updates(): void
    {
        $definition = $this->registerDefinition();

        try {
            DB::table('workflow_definitions')
                ->where('id', $definition->id)
                ->update([
                    'name' => 'Database tamper',
                ]);

            self::fail(
                'PostgreSQL unexpectedly allowed a workflow-definition update.',
            );
        } catch (QueryException $exception) {
            self::assertStringContainsString(
                'workflow_definitions is append-only',
                $exception->getMessage(),
            );
        }

        self::assertDatabaseHas('workflow_definitions', [
            'id' => $definition->id,
            'definition_key' => 'project_delivery',
            'version' => 1,
            'name' => 'Project delivery workflow',
        ]);
    }

    /**
     * Ensure direct deletes cannot bypass application-level model guards.
     */
    public function test_postgresql_rejects_direct_deletes(): void
    {
        $definition = $this->registerDefinition();

        try {
            DB::table('workflow_definitions')
                ->where('id', $definition->id)
                ->delete();

            self::fail(
                'PostgreSQL unexpectedly allowed a workflow-definition deletion.',
            );
        } catch (QueryException $exception) {
            self::assertStringContainsString(
                'workflow_definitions is append-only',
                $exception->getMessage(),
            );
        }

        self::assertDatabaseHas('workflow_definitions', [
            'id' => $definition->id,
            'definition_key' => 'project_delivery',
            'version' => 1,
            'name' => 'Project delivery workflow',
        ]);
    }

    /**
     * Register one valid immutable workflow-definition fixture.
     */
    private function registerDefinition(): WorkflowDefinition
    {
        return app(RegisterWorkflowDefinition::class)->handle(
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
    }
}
