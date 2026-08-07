<?php

declare(strict_types=1);

use App\Application\Events\Contracts\DomainEventOutbox;
use App\Application\Workflows\Contracts\WorkflowTransitionGuardEvaluator;
use App\Application\Workflows\CreateWorkflowInstance;
use App\Application\Workflows\Data\WorkflowTransitionContext;
use App\Application\Workflows\RegisterWorkflowDefinition;
use App\Application\Workflows\TransitionWorkflowInstance;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Events\DomainEventEnvelope;
use App\Domain\Workflows\WorkflowDefinitionManifest;
use App\Models\AuditEvent;
use App\Models\OutboxMessage;
use App\Models\Project;
use App\Models\WorkflowInstance;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->app->instance(
        WorkflowTransitionGuardEvaluator::class,
        new class implements WorkflowTransitionGuardEvaluator
        {
            /**
             * Approve only the expected deterministic test guard input.
             *
             * @param  array<string, mixed>  $context
             */
            public function passes(
                string $guard,
                WorkflowInstance $instance,
                array $context,
            ): bool {
                return $guard === 'transition_permitted'
                    && $instance->current_state === 'queued'
                    && ($context['transition_permitted'] ?? false) === true;
            }
        },
    );
});

it('records a workflow transition with matching outbox and audit trace', function (): void {
    $project = Project::factory()->create();

    $definition = app(RegisterWorkflowDefinition::class)->handle(
        auditableWorkflowManifest(),
    );

    $instance = app(CreateWorkflowInstance::class)->handle(
        project: $project,
        definitionKey: 'auditable_project_delivery',
        version: 1,
    );

    /*
     * Outbox trace columns use Laravel ULID columns. Generate valid ULIDs rather
     * than descriptive strings that exceed PostgreSQL CHAR(26).
     */
    $correlationId = (string) Str::ulid();
    $causationId = (string) Str::ulid();
    $executionId = (string) Str::ulid();

    $context = WorkflowTransitionContext::system(
        actorId: 'workflow-transition-test',
        correlationId: $correlationId,
        causationId: $causationId,
        executionId: $executionId,
        guardContext: [
            'transition_permitted' => true,
        ],
    );

    $transitioned = app(TransitionWorkflowInstance::class)->handle(
        instance: $instance,
        transitionName: 'start',
        context: $context,
    );

    $expectedPayload = [
        'workflow_instance_id' => $instance->id,
        'workflow_definition_id' => $definition->id,
        'transition_sequence' => 1,
        'transition_name' => 'start',
        'from_state' => 'queued',
        'to_state' => 'running',
        'guard' => 'transition_permitted',
    ];

    $outbox = OutboxMessage::query()
        ->where('event_name', 'workflow.transitioned')
        ->sole();

    $audit = AuditEvent::query()
        ->where(
            'event_type',
            AuditEventType::WorkflowTransitioned->value,
        )
        ->sole();

    expect($transitioned->current_state)
        ->toBe('running')
        ->and($transitioned->transition_sequence)
        ->toBe(1)
        ->and($outbox->aggregate_type)
        ->toBe('workflow_instance')
        ->and($outbox->aggregate_id)
        ->toBe((string) $instance->id)
        ->and($outbox->correlation_id)
        ->toBe($correlationId)
        ->and($outbox->causation_id)
        ->toBe($causationId)
        ->and($outbox->execution_id)
        ->toBe($executionId)
        ->and($outbox->envelope['payload'])
        ->toEqual($expectedPayload)
        ->and($audit->actor_type)
        ->toBe(AuditActorType::System)
        ->and($audit->actor_id)
        ->toBe($context->actorId)
        ->and($audit->event_type)
        ->toBe(AuditEventType::WorkflowTransitioned)
        ->and($audit->subject_type)
        ->toBe(AuditSubjectType::WorkflowInstance)
        ->and($audit->subject_id)
        ->toBe((string) $instance->id)
        ->and($audit->correlation_id)
        ->toBe($correlationId)
        ->and($audit->causation_id)
        ->toBe($causationId)
        ->and($audit->execution_id)
        ->toBe($executionId)
        ->and($audit->metadata)
        ->toEqual($expectedPayload);
});

it('rolls back transition state and audit history when outbox persistence fails', function (): void {
    $project = Project::factory()->create();

    app(RegisterWorkflowDefinition::class)->handle(
        auditableWorkflowManifest(),
    );

    $instance = app(CreateWorkflowInstance::class)->handle(
        project: $project,
        definitionKey: 'auditable_project_delivery',
        version: 1,
    );

    $this->app->bind(
        DomainEventOutbox::class,
        static fn (): DomainEventOutbox => new class implements DomainEventOutbox
        {
            /**
             * Simulate a durable outbox write failure.
             */
            public function append(DomainEventEnvelope $event): void
            {
                throw new RuntimeException(
                    'Simulated workflow transition outbox failure.',
                );
            }
        },
    );

    expect(
        fn () => app(TransitionWorkflowInstance::class)->handle(
            instance: $instance,
            transitionName: 'start',
            context: WorkflowTransitionContext::system(
                actorId: 'workflow-transition-test',
                correlationId: (string) Str::ulid(),
                causationId: (string) Str::ulid(),
                executionId: (string) Str::ulid(),
                guardContext: [
                    'transition_permitted' => true,
                ],
            ),
        ),
    )->toThrow(
        RuntimeException::class,
        'Simulated workflow transition outbox failure.',
    );

    $instance->refresh();

    expect($instance->current_state)
        ->toBe('queued')
        ->and($instance->transition_sequence)
        ->toBe(0)
        ->and($instance->completed_at)
        ->toBeNull();

    $this->assertDatabaseMissing('workflow_transitions', [
        'workflow_instance_id' => $instance->id,
    ]);

    $this->assertDatabaseMissing('outbox_messages', [
        'event_name' => 'workflow.transitioned',
        'aggregate_id' => (string) $instance->id,
    ]);

    $this->assertDatabaseMissing('audit_events', [
        'event_type' => AuditEventType::WorkflowTransitioned->value,
        'subject_type' => AuditSubjectType::WorkflowInstance->value,
        'subject_id' => (string) $instance->id,
    ]);
});

/**
 * Build the immutable workflow definition used by transition audit tests.
 */
function auditableWorkflowManifest(): WorkflowDefinitionManifest
{
    return new WorkflowDefinitionManifest(
        definitionKey: 'auditable_project_delivery',
        version: 1,
        schemaVersion: 1,
        name: 'Auditable project delivery workflow',
        description: 'Exercises transition outbox and audit atomicity.',
        initialState: 'queued',
        states: [
            'queued',
            'running',
            'completed',
        ],
        terminalStates: [
            'completed',
        ],
        transitions: [
            [
                'name' => 'start',
                'from' => 'queued',
                'to' => 'running',
                'guard' => 'transition_permitted',
            ],
            [
                'name' => 'complete',
                'from' => 'running',
                'to' => 'completed',
                'guard' => null,
            ],
        ],
    );
}
