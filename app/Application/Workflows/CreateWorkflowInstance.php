<?php

declare(strict_types=1);

namespace App\Application\Workflows;

use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Workflows\WorkflowStateMachine;
use App\Models\Project;
use App\Models\WorkflowInstance;
use LogicException;

/**
 * Creates one workflow instance bound to one immutable definition version.
 */
final readonly class CreateWorkflowInstance
{
    /**
     * Inject definition resolution, state rules, and transaction handling.
     */
    public function __construct(
        private TransactionManager $transactions,
        private ResolveWorkflowDefinition $definitions,
        private WorkflowStateMachine $stateMachine,
    ) {}

    /**
     * Create an instance at the selected definition's initial state.
     *
     * When version is null, the latest version is resolved exactly once.
     * The resulting workflow_definition_id remains immutable afterward.
     */
    public function handle(
        Project $project,
        string $definitionKey,
        ?int $version = null,
    ): WorkflowInstance {
        if (! $project->exists) {
            throw new LogicException(
                'A project must be persisted before creating a workflow instance.',
            );
        }

        $definition = $version === null
            ? $this->definitions->latest($definitionKey)
            : $this->definitions->exact($definitionKey, $version);

        $initialState = $this->stateMachine->initialState(
            $definition->definition,
        );

        return $this->transactions->run(
            function () use (
                $project,
                $definition,
                $initialState,
            ): WorkflowInstance {
                $instance = new WorkflowInstance;

                /*
                 * State fields are intentionally excluded from mass assignment.
                 * Only this deterministic creation path initializes them.
                 */
                $instance->forceFill([
                    'project_id' => $project->id,
                    'workflow_definition_id' => $definition->id,
                    'current_state' => $initialState,
                    'transition_sequence' => 0,
                    'completed_at' => null,
                ])->save();

                return $instance->load('workflowDefinition');
            },
        );
    }
}
