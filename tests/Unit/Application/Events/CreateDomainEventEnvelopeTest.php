<?php

declare(strict_types=1);

use App\Application\Events\CreateDomainEventEnvelope;
use App\Domain\Events\DomainEvent;
use App\Domain\Events\DomainEventActor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('creates a versioned envelope and defaults correlation to the event id', function (): void {
    CarbonImmutable::setTestNow(
        CarbonImmutable::parse('2026-07-26 10:00:00', 'UTC'),
    );

    $event = new class implements DomainEvent
    {
        /**
         * Return the stable workflow transition event name.
         */
        public static function eventName(): string
        {
            return 'workflow.transition_committed';
        }

        /**
         * Return the initial workflow transition payload version.
         */
        public static function schemaVersion(): int
        {
            return 1;
        }

        /**
         * Return the safe workflow transition payload.
         *
         * @return array<string, mixed>
         */
        public function payload(): array
        {
            return [
                'transition_name' => 'start',
                'from_state' => 'queued',
                'to_state' => 'running',
                'sequence' => 1,
            ];
        }
    };

    $envelope = (new CreateDomainEventEnvelope)->create(
        event: $event,
        aggregateType: 'workflow_instance',
        aggregateId: '100',
        organizationId: 10,
        projectId: 20,
        actor: DomainEventActor::system('workflow-engine'),
        provider: null,
        executionId: 'execution-100',
    );

    expect(Str::isUlid($envelope->eventId))
        ->toBeTrue()
        ->and($envelope->correlationId)
        ->toBe($envelope->eventId)
        ->and($envelope->eventName)
        ->toBe('workflow.transition_committed')
        ->and($envelope->schemaVersion)
        ->toBe(1)
        ->and($envelope->executionId)
        ->toBe('execution-100')
        ->and($envelope->occurredAt->toISOString())
        ->toBe('2026-07-26T10:00:00.000000Z');
});

it('preserves explicit trace provider and schema version values', function (): void {
    $event = new class implements DomainEvent
    {
        /**
         * Return the stable project start event name.
         */
        public static function eventName(): string
        {
            return 'project.start_requested';
        }

        /**
         * Return the second project-start payload version.
         */
        public static function schemaVersion(): int
        {
            return 2;
        }

        /**
         * Return the safe project-start payload.
         *
         * @return array<string, mixed>
         */
        public function payload(): array
        {
            return [
                'context_snapshot_id' => 55,
            ];
        }
    };

    $envelope = (new CreateDomainEventEnvelope)->create(
        event: $event,
        aggregateType: 'project',
        aggregateId: '20',
        organizationId: 10,
        projectId: 20,
        actor: DomainEventActor::user(5),
        provider: 'simulation',
        correlationId: 'request-200',
        causationId: 'command-200',
        executionId: 'execution-200',
    );

    expect($envelope->actor->toArray())
        ->toBe([
            'type' => 'user',
            'id' => '5',
        ])
        ->and($envelope->provider)
        ->toBe('simulation')
        ->and($envelope->correlationId)
        ->toBe('request-200')
        ->and($envelope->causationId)
        ->toBe('command-200')
        ->and($envelope->executionId)
        ->toBe('execution-200')
        ->and($envelope->schemaVersion)
        ->toBe(2);
});
