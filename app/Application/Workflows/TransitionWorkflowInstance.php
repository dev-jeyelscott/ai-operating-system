<?php

declare(strict_types=1);

namespace App\Application\Workflows;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Events\Contracts\DomainEventOutbox;
use App\Application\Events\CreateDomainEventEnvelope;
use App\Application\Shared\Contracts\TransactionManager;
use App\Application\Workflows\Contracts\WorkflowTransitionGuardEvaluator;
use App\Application\Workflows\Data\WorkflowTransitionContext;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Events\DomainEventActor;
use App\Domain\Events\DomainEventActorType;
use App\Domain\Workflows\Events\WorkflowTransitioned;
use App\Domain\Workflows\Exceptions\WorkflowTransitionGuardRejected;
use App\Domain\Workflows\WorkflowStateMachine;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTransition;
use LogicException;

/**
 * Commits one allowed workflow transition with matching event and audit history.
 */
final readonly class TransitionWorkflowInstance
{
    /**
     * Inject transaction, workflow, outbox, and audit services.
     */
    public function __construct(
        private TransactionManager $transactions,
        private WorkflowStateMachine $stateMachine,
        private WorkflowTransitionGuardEvaluator $guards,
        private DomainEventOutbox $outbox,
        private CreateDomainEventEnvelope $eventEnvelopes,
        private RecordAuditEvent $auditEvents,
    ) {}

    /**
     * Validate and commit one fully auditable workflow transition.
     *
     * The workflow row is reloaded under FOR UPDATE. Callers therefore cannot
     * use a stale Eloquent model to overwrite a newer committed state.
     *
     * The legacy guardContext argument remains temporarily supported so the
     * existing phase branch does not require unrelated caller rewrites. New
     * callers should pass guard inputs through WorkflowTransitionContext.
     *
     * @param  array<string, mixed>  $guardContext
     */
    public function handle(
        WorkflowInstance $instance,
        string $transitionName,
        array $guardContext = [],
        ?WorkflowTransitionContext $context = null,
    ): WorkflowInstance {
        if (! $instance->exists) {
            throw new LogicException(
                'A workflow instance must be persisted before transitioning.',
            );
        }

        if ($context !== null && $guardContext !== []) {
            throw new LogicException(
                'Provide workflow guard inputs through WorkflowTransitionContext when a transition context is supplied.',
            );
        }

        $transitionContext = $context
            ?? WorkflowTransitionContext::system(
                actorId: 'workflow-transition-service',
                guardContext: $guardContext,
            );

        return $this->transactions->run(
            function () use (
                $instance,
                $transitionName,
                $transitionContext,
            ): WorkflowInstance {
                /** @var WorkflowInstance $lockedInstance */
                $lockedInstance = WorkflowInstance::query()
                    ->with([
                        'project',
                        'workflowDefinition',
                    ])
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
                        context: $transitionContext->guardContext,
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
                 * Insert the append-only transition history before changing the
                 * materialized current state.
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

                $event = $this->eventEnvelopes->create(
                    event: new WorkflowTransitioned(
                        workflowInstanceId: $lockedInstance->id,
                        workflowDefinitionId: $lockedInstance
                            ->workflow_definition_id,
                        transitionSequence: $nextSequence,
                        transitionName: $transition->name,
                        fromState: $transition->from,
                        toState: $transition->to,
                        guard: $transition->guard,
                    ),
                    aggregateType: 'workflow_instance',
                    aggregateId: (string) $lockedInstance->id,
                    organizationId: $lockedInstance
                        ->project
                        ->organization_id,
                    projectId: $lockedInstance->project_id,
                    actor: new DomainEventActor(
                        type: DomainEventActorType::from(
                            $transitionContext->actorType->value,
                        ),
                        id: $transitionContext->actorId,
                    ),
                    correlationId: $transitionContext->correlationId,
                    causationId: $transitionContext->causationId,
                    executionId: $transitionContext->executionId,
                );

                $this->outbox->append($event);

                $this->auditEvents->record(
                    organizationId: $lockedInstance
                        ->project
                        ->organization_id,
                    projectId: $lockedInstance->project_id,
                    actorType: $transitionContext->actorType,
                    actorId: $transitionContext->actorId,
                    eventType: AuditEventType::WorkflowTransitioned,
                    subjectType: AuditSubjectType::WorkflowInstance,
                    subjectId: (string) $lockedInstance->id,
                    correlationId: $event->correlationId,
                    metadata: $event->payload,
                    causationId: $event->causationId,
                    executionId: $event->executionId,
                    schemaVersion: $event->schemaVersion,
                    deduplicationKey: sprintf(
                        'workflow-transition:%d:%d',
                        $lockedInstance->id,
                        $nextSequence,
                    ),
                );

                $lockedInstance->refresh();
                $lockedInstance->load([
                    'project',
                    'workflowDefinition',
                ]);

                return $lockedInstance;
            },
        );
    }
}
