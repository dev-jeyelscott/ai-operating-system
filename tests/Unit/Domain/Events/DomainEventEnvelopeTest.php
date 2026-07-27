<?php

declare(strict_types=1);

use App\Domain\Events\DomainEventActor;
use App\Domain\Events\DomainEventEnvelope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

it('serializes the complete canonical domain event envelope', function (): void {
    $eventId = (string) Str::ulid();

    $envelope = new DomainEventEnvelope(
        eventId: $eventId,
        eventName: 'workflow.transition_committed',
        aggregateType: 'workflow_instance',
        aggregateId: '42',
        organizationId: 7,
        projectId: 9,
        actor: DomainEventActor::system('workflow-engine'),
        provider: 'simulation',
        occurredAt: CarbonImmutable::parse(
            '2026-07-26 12:30:45',
            'Asia/Manila',
        ),
        correlationId: 'request-01',
        causationId: 'command-01',
        executionId: 'execution-01',
        schemaVersion: 2,
        payload: [
            'transition_name' => 'start',
            'from_state' => 'queued',
            'to_state' => 'running',
        ],
    );

    expect($envelope->toArray())
        ->toBe([
            'event_id' => $eventId,
            'event_name' => 'workflow.transition_committed',
            'aggregate_type' => 'workflow_instance',
            'aggregate_id' => '42',
            'organization_id' => 7,
            'project_id' => 9,
            'actor' => [
                'type' => 'system',
                'id' => 'workflow-engine',
            ],
            'provider' => 'simulation',
            'occurred_at' => '2026-07-26T04:30:45.000000Z',
            'correlation_id' => 'request-01',
            'causation_id' => 'command-01',
            'execution_id' => 'execution-01',
            'schema_version' => 2,
            'payload' => [
                'transition_name' => 'start',
                'from_state' => 'queued',
                'to_state' => 'running',
            ],
        ])
        ->and($envelope->jsonSerialize())
        ->toBe($envelope->toArray());
});

it('rejects event names that are not lowercase dotted names', function (): void {
    expect(
        fn () => new DomainEventEnvelope(
            eventId: (string) Str::ulid(),
            eventName: 'WorkflowTransitionCommitted',
            aggregateType: 'workflow_instance',
            aggregateId: '42',
            organizationId: 7,
            projectId: 9,
            actor: DomainEventActor::system('workflow-engine'),
            provider: null,
            occurredAt: CarbonImmutable::now(),
            correlationId: 'request-01',
            causationId: null,
            executionId: null,
            schemaVersion: 1,
            payload: [],
        ),
    )->toThrow(
        InvalidArgumentException::class,
        'The domain event name must use lowercase dotted notation.',
    );
});

it('rejects non positive schema versions', function (): void {
    expect(
        fn () => new DomainEventEnvelope(
            eventId: (string) Str::ulid(),
            eventName: 'workflow.transition_committed',
            aggregateType: 'workflow_instance',
            aggregateId: '42',
            organizationId: 7,
            projectId: 9,
            actor: DomainEventActor::system('workflow-engine'),
            provider: null,
            occurredAt: CarbonImmutable::now(),
            correlationId: 'request-01',
            causationId: null,
            executionId: null,
            schemaVersion: 0,
            payload: [],
        ),
    )->toThrow(
        InvalidArgumentException::class,
        'The domain event schema version must be positive.',
    );
});

it('rejects payloads that cannot be encoded as json', function (): void {
    expect(
        fn () => new DomainEventEnvelope(
            eventId: (string) Str::ulid(),
            eventName: 'workflow.transition_committed',
            aggregateType: 'workflow_instance',
            aggregateId: '42',
            organizationId: 7,
            projectId: 9,
            actor: DomainEventActor::system('workflow-engine'),
            provider: null,
            occurredAt: CarbonImmutable::now(),
            correlationId: 'request-01',
            causationId: null,
            executionId: null,
            schemaVersion: 1,
            payload: [
                'invalid_utf8' => "\xB1\x31",
            ],
        ),
    )->toThrow(
        InvalidArgumentException::class,
        'The domain event payload must be JSON serializable.',
    );
});

it('rejects payloads with integer keys', function (): void {
    expect(
        fn () => new DomainEventEnvelope(
            eventId: (string) Str::ulid(),
            eventName: 'workflow.transition_committed',
            aggregateType: 'workflow_instance',
            aggregateId: '42',
            organizationId: 7,
            projectId: 9,
            actor: DomainEventActor::system('workflow-engine'),
            provider: null,
            occurredAt: CarbonImmutable::now(),
            correlationId: 'request-01',
            causationId: null,
            executionId: null,
            schemaVersion: 1,
            payload: [
                0 => 'invalid',
            ],
        ),
    )->toThrow(
        InvalidArgumentException::class,
        'The domain event payload must use string keys.',
    );
});
