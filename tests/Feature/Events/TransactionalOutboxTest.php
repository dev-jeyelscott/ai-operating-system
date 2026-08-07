<?php

declare(strict_types=1);

use App\Application\Events\Contracts\DomainEventOutbox;
use App\Application\Events\CreateDomainEventEnvelope;
use App\Application\Events\TransactionalOutbox;
use App\Domain\Events\DomainEvent;
use App\Domain\Events\DomainEventActor;
use App\Domain\Events\DomainEventEnvelope;
use App\Models\Organization;
use App\Models\OutboxMessage;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Build one canonical test envelope through the production AIOS-049 factory.
 */
function makeTransactionalOutboxEnvelope(
    Project $project,
): DomainEventEnvelope {
    $domainEvent = new class($project->id) implements DomainEvent
    {
        /**
         * Create the test event with its authoritative project identifier.
         */
        public function __construct(
            private readonly int $projectId,
        ) {}

        /**
         * Return the stable lowercase dotted event name.
         */
        public static function eventName(): string
        {
            return 'project.transactional_outbox_tested';
        }

        /**
         * Return the current schema version for this test event.
         */
        public static function schemaVersion(): int
        {
            return 1;
        }

        /**
         * Return the JSON-serializable event payload.
         *
         * @return array<string, mixed>
         */
        public function payload(): array
        {
            return [
                'project_id' => $this->projectId,
                'resulting_description' => 'after',
            ];
        }
    };

    return app(CreateDomainEventEnvelope::class)->create(
        event: $domainEvent,
        aggregateType: 'project',
        aggregateId: (string) $project->id,
        organizationId: $project->organization_id,
        projectId: $project->id,
        actor: DomainEventActor::system('transactional-outbox-test'),
        provider: null,
        correlationId: (string) Str::ulid(),
        causationId: null,
        executionId: null,
    );
}

test('business state and its domain event commit atomically', function () {
    $organization = Organization::factory()->create();

    $project = Project::factory()
        ->for($organization)
        ->create([
            'description' => 'before',
        ]);

    $envelope = makeTransactionalOutboxEnvelope($project);

    app(TransactionalOutbox::class)->run(
        function (DomainEventOutbox $outbox) use (
            $project,
            $envelope,
        ): void {
            /*
             * This represents an authoritative aggregate state mutation.
             */
            $project->forceFill([
                'description' => 'after',
            ])->save();

            /*
             * This append occurs before the same transaction commits.
             */
            $outbox->append($envelope);
        },
    );

    $persisted = OutboxMessage::query()->sole();

    expect($project->refresh()->description)
        ->toBe('after')
        ->and($persisted->event_id)
        ->toBe($envelope->eventId)
        ->and($persisted->event_name)
        ->toBe($envelope->eventName)
        ->and($persisted->aggregate_type)
        ->toBe($envelope->aggregateType)
        ->and($persisted->aggregate_id)
        ->toBe($envelope->aggregateId)
        ->and($persisted->organization_id)
        ->toBe($organization->id)
        ->and($persisted->project_id)
        ->toBe($project->id)
        ->and($persisted->correlation_id)
        ->toBe($envelope->correlationId)
        ->and($persisted->schema_version)
        ->toBe($envelope->schemaVersion)
        ->and($persisted->envelope)
        ->toEqual($envelope->toArray())
        ->and($persisted->published_at)
        ->toBeNull();
});

test('failed outbox persistence rolls back the business mutation', function () {
    $organization = Organization::factory()->create();

    $project = Project::factory()
        ->for($organization)
        ->create([
            'description' => 'before',
        ]);

    $envelope = makeTransactionalOutboxEnvelope($project);

    /*
     * Replace only the application contract to simulate unavailable
     * outbox persistence without changing production infrastructure.
     */
    $this->app->bind(
        DomainEventOutbox::class,
        static fn (): DomainEventOutbox => new class implements DomainEventOutbox
        {
            /**
             * Simulate an outbox persistence failure.
             */
            public function append(DomainEventEnvelope $event): void
            {
                throw new RuntimeException(
                    'Simulated outbox persistence failure.',
                );
            }
        },
    );

    $transactionalOutbox = app(TransactionalOutbox::class);

    expect(
        fn () => $transactionalOutbox->run(
            function (DomainEventOutbox $outbox) use (
                $project,
                $envelope,
            ): void {
                $project->forceFill([
                    'description' => 'after',
                ])->save();

                $outbox->append($envelope);
            },
        ),
    )->toThrow(
        RuntimeException::class,
        'Simulated outbox persistence failure.',
    );

    expect($project->refresh()->description)
        ->toBe('before');

    $this->assertDatabaseCount('outbox_messages', 0);
});

test('failed business operation rolls back an already appended event', function () {
    $organization = Organization::factory()->create();

    $project = Project::factory()
        ->for($organization)
        ->create([
            'description' => 'before',
        ]);

    $envelope = makeTransactionalOutboxEnvelope($project);

    expect(
        fn () => app(TransactionalOutbox::class)->run(
            function (DomainEventOutbox $outbox) use (
                $project,
                $envelope,
            ): void {
                $project->forceFill([
                    'description' => 'after',
                ])->save();

                $outbox->append($envelope);

                throw new RuntimeException(
                    'Simulated business-operation failure.',
                );
            },
        ),
    )->toThrow(
        RuntimeException::class,
        'Simulated business-operation failure.',
    );

    expect($project->refresh()->description)
        ->toBe('before');

    $this->assertDatabaseCount('outbox_messages', 0);
});

test('duplicate event identifiers cannot create duplicate outbox messages', function () {
    $organization = Organization::factory()->create();

    $project = Project::factory()
        ->for($organization)
        ->create();

    $envelope = makeTransactionalOutboxEnvelope($project);

    expect(
        fn () => app(TransactionalOutbox::class)->run(
            function (DomainEventOutbox $outbox) use (
                $envelope,
            ): void {
                $outbox->append($envelope);
                $outbox->append($envelope);
            },
        ),
    )->toThrow(QueryException::class);

    /*
     * The unique violation rolls back the complete transaction, including
     * the first insert.
     */
    $this->assertDatabaseCount('outbox_messages', 0);
});

test('outbox sequence preserves database insertion order', function () {
    $organization = Organization::factory()->create();

    $project = Project::factory()
        ->for($organization)
        ->create();

    $firstEnvelope = makeTransactionalOutboxEnvelope($project);
    $secondEnvelope = makeTransactionalOutboxEnvelope($project);

    app(TransactionalOutbox::class)->run(
        function (DomainEventOutbox $outbox) use (
            $firstEnvelope,
            $secondEnvelope,
        ): void {
            $outbox->append($firstEnvelope);
            $outbox->append($secondEnvelope);
        },
    );

    $messages = OutboxMessage::query()
        ->orderBy('sequence')
        ->get();

    expect($messages)
        ->toHaveCount(2)
        ->and($messages[0]->event_id)
        ->toBe($firstEnvelope->eventId)
        ->and($messages[1]->event_id)
        ->toBe($secondEnvelope->eventId)
        ->and($messages[1]->sequence)
        ->toBeGreaterThan($messages[0]->sequence);
});
