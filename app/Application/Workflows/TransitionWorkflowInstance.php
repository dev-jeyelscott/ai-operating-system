<?php

declare(strict_types=1);

namespace App\Application\Workflows;

use App\Application\Shared\Contracts\TransactionManager;
use App\Application\Workflows\Contracts\WorkflowTransitionGuardEvaluator;
use App\Domain\Workflows\Exceptions\WorkflowTransitionGuardRejected;
use App\Domain\Workflows\WorkflowStateMachine;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTransition;
use LogicException;

/**
 * Commits one allowed workflow transition atomically.
 */
final readonly class TransitionWorkflowInstance
{
    /**
     * Inject transaction, state-machine, and guard services.
     */
    public function __construct(
        private TransactionManager $transactions,
        private WorkflowStateMachine $stateMachine,
        private WorkflowTransitionGuardEvaluator $guards,
    ) {}

    /**
     * Validate and commit one workflow transition.
     *
     * The workflow row is reloaded under FOR UPDATE. Callers therefore cannot
     * use a stale Eloquent model to overwrite a newer committed state.
     *
     * @param  array<string, mixed>  $guardContext
     */
    public function handle(
        WorkflowInstance $instance,
        string $transitionName,
        array $guardContext = [],
    ): WorkflowInstance {
        if (! $instance->exists) {
            throw new LogicException(
                'A workflow instance must be persisted before transitioning.',
            );
        }

        return $this->transactions->run(
            function () use (
                $instance,
                $transitionName,
                $guardContext,
            ): WorkflowInstance {
                /** @var WorkflowInstance $lockedInstance */
                $lockedInstance = WorkflowInstance::query()
                    ->with('workflowDefinition')
                    ->whereKey($instance->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $transition = $this->stateMachine->resolve(
                    definition: $lockedInstance
                        ->workflowDefinition
                        ->definition,
                    currentState: $lockedInstance->current_state,
                    transitionName: $transitionName,
                );

                if (
                    $transition->guard !== null
                    && ! $this->guards->passes(
                        guard: $transition->guard,
                        instance: $lockedInstance,
                        context: $guardContext,
                    )
                ) {
                    throw WorkflowTransitionGuardRejected::forTransition(
                        guard: $transition->guard,
                        transition: $transition->name,
                        workflowInstanceId: $lockedInstance->id,
                    );
                }

                $nextSequence = $lockedInstance->transition_sequence + 1;

                /*
                 * Insert history before updating the materialized current state.
                 * Both writes remain inside the same transaction and therefore
                 * either commit together or roll back together.
                 */
                $history = new WorkflowTransition;

                $history->forceFill([
                    'workflow_instance_id' => $lockedInstance->id,
                    'sequence' => $nextSequence,
                    'name' => $transition->name,
                    'from_state' => $transition->from,
                    'to_state' => $transition->to,
                    'guard' => $transition->guard,
                    'created_at' => now(),
                ])->save();

                $isTerminal = $this->stateMachine->isTerminal(
                    definition: $lockedInstance
                        ->workflowDefinition
                        ->definition,
                    state: $transition->to,
                );

                /*
                 * State fields are intentionally excluded from mass assignment.
                 * forceFill is permitted only after transition and guard checks.
                 */
                $lockedInstance->forceFill([
                    'current_state' => $transition->to,
                    'transition_sequence' => $nextSequence,
                    'completed_at' => $isTerminal ? now() : null,
                ])->save();

                $lockedInstance->refresh();
                $lockedInstance->load('workflowDefinition');

                return $lockedInstance;
            },
        );
    }
}
