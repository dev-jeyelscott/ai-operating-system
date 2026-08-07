<?php

declare(strict_types=1);

use App\Application\Events\Contracts\OutboxTransport;
use App\Application\Events\DispatchOutboxMessages;
use App\Models\Organization;
use App\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Simulates a newer dispatcher taking ownership during transport publication.
 */
final class ReservationStealingOutboxTransport implements OutboxTransport
{
    public const CONFLICT_WARNING =
        'Outbox reservation ownership changed before the dispatch outcome was persisted.';

    public const NEW_OWNER_ERROR = 'State owned by a newer dispatcher.';

    public const TRANSPORT_ERROR =
        'Redis transport unavailable during ownership transfer.';

    /**
     * Create the test transport behavior.
     */
    public function __construct(
        private readonly string $newReservationToken,
        private readonly bool $failPublication,
    ) {}

    /**
     * Transfer reservation ownership before optionally failing publication.
     */
    public function publish(string $eventId): void
    {
        OutboxMessage::query()
            ->where('event_id', $eventId)
            ->update([
                'reservation_token' => $this->newReservationToken,
                'reserved_until' => CarbonImmutable::now()->addMinute(),
                'last_error' => self::NEW_OWNER_ERROR,
            ]);

        if ($this->failPublication) {
            throw new RuntimeException(self::TRANSPORT_ERROR);
        }
    }
}

/**
 * Persist one valid message ready for ownership-conflict testing.
 */
function createOwnershipConflictOutboxMessage(
    Organization $organization,
): OutboxMessage {
    $eventId = (string) Str::ulid();
    $occurredAt = CarbonImmutable::now();

    return OutboxMessage::query()->create([
        'event_id' => $eventId,
        'event_name' => 'test.event.created',
        'aggregate_type' => 'test.aggregate',
        'aggregate_id' => 'aggregate-1',
        'organization_id' => $organization->id,
        'project_id' => null,
        'occurred_at' => $occurredAt,
        'correlation_id' => $eventId,
        'causation_id' => null,
        'execution_id' => null,
        'schema_version' => 1,
        'envelope' => [
            'event_id' => $eventId,
            'event_name' => 'test.event.created',
            'aggregate_type' => 'test.aggregate',
            'aggregate_id' => 'aggregate-1',
            'organization_id' => $organization->id,
            'project_id' => null,
            'actor' => [
                'type' => 'system',
                'id' => 'test-suite',
            ],
            'provider' => [
                'type' => 'application',
                'id' => 'test-suite',
            ],
            'occurred_at' => $occurredAt->toIso8601String(),
            'correlation_id' => $eventId,
            'causation_id' => null,
            'execution_id' => null,
            'schema_version' => 1,
            'payload' => [
                'value' => 'example',
            ],
        ],
        'published_at' => null,
        'available_at' => $occurredAt,
        'reserved_until' => null,
        'reservation_token' => null,
        'dispatch_attempts' => 0,
        'last_error' => null,
        'dead_lettered_at' => null,
        'replay_count' => 0,
        'last_replayed_at' => null,
        'created_at' => $occurredAt,
    ]);
}

/**
 * Dispatch one configured batch and return its operational counters.
 *
 * @return array{
 *     expired_dead_lettered: int,
 *     claimed: int,
 *     published: int,
 *     failed: int,
 *     dead_lettered: int,
 *     reservation_conflicts: int
 * }
 */
function dispatchOwnershipConflictBatch(
    int $maximumAttempts,
): array {
    return app(DispatchOutboxMessages::class)->handle(
        limit: 10,
        leaseSeconds: 60,
        maximumAttempts: $maximumAttempts,
        baseBackoffSeconds: 5,
        maximumBackoffSeconds: 300,
    );
}

/**
 * Assert that one stable ownership-conflict warning was recorded.
 */
function assertOwnershipConflictWarning(
    OutboxMessage $message,
    string $operation,
): void {
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(
            fn (string $warning, array $context): bool => $warning === ReservationStealingOutboxTransport::CONFLICT_WARNING
                && $context['operation'] === $operation
                && $context['outbox_sequence'] === $message->sequence
                && $context['event_id'] === $message->event_id
                && $context['dispatch_attempt'] === 1,
        );
}

test('lost ownership after publication does not mark the newer reservation as published', function (): void {
    Log::spy();

    $organization = Organization::factory()->create();
    $message = createOwnershipConflictOutboxMessage($organization);
    $newReservationToken = (string) Str::uuid();

    $this->app->instance(
        OutboxTransport::class,
        new ReservationStealingOutboxTransport(
            newReservationToken: $newReservationToken,
            failPublication: false,
        ),
    );

    $result = dispatchOwnershipConflictBatch(
        maximumAttempts: 3,
    );

    expect($result)->toMatchArray([
        'expired_dead_lettered' => 0,
        'claimed' => 1,
        'published' => 0,
        'failed' => 0,
        'dead_lettered' => 0,
        'reservation_conflicts' => 1,
    ]);

    $message->refresh();

    expect($message->published_at)
        ->toBeNull()
        ->and($message->dead_lettered_at)
        ->toBeNull()
        ->and($message->reservation_token)
        ->toBe($newReservationToken)
        ->and($message->last_error)
        ->toBe(ReservationStealingOutboxTransport::NEW_OWNER_ERROR);

    assertOwnershipConflictWarning(
        message: $message,
        operation: 'mark_published',
    );
});

test('lost ownership after a retryable failure does not release the newer reservation', function (): void {
    Log::spy();

    $organization = Organization::factory()->create();
    $message = createOwnershipConflictOutboxMessage($organization);
    $newReservationToken = (string) Str::uuid();

    $this->app->instance(
        OutboxTransport::class,
        new ReservationStealingOutboxTransport(
            newReservationToken: $newReservationToken,
            failPublication: true,
        ),
    );

    $result = dispatchOwnershipConflictBatch(
        maximumAttempts: 3,
    );

    expect($result)->toMatchArray([
        'expired_dead_lettered' => 0,
        'claimed' => 1,
        'published' => 0,
        'failed' => 1,
        'dead_lettered' => 0,
        'reservation_conflicts' => 1,
    ]);

    $message->refresh();

    expect($message->published_at)
        ->toBeNull()
        ->and($message->dead_lettered_at)
        ->toBeNull()
        ->and($message->reservation_token)
        ->toBe($newReservationToken)
        ->and($message->last_error)
        ->toBe(ReservationStealingOutboxTransport::NEW_OWNER_ERROR);

    assertOwnershipConflictWarning(
        message: $message,
        operation: 'release',
    );
});

test('lost ownership after an exhausted failure does not dead-letter the newer reservation', function (): void {
    Log::spy();

    $organization = Organization::factory()->create();
    $message = createOwnershipConflictOutboxMessage($organization);
    $newReservationToken = (string) Str::uuid();

    $this->app->instance(
        OutboxTransport::class,
        new ReservationStealingOutboxTransport(
            newReservationToken: $newReservationToken,
            failPublication: true,
        ),
    );

    $result = dispatchOwnershipConflictBatch(
        maximumAttempts: 1,
    );

    expect($result)->toMatchArray([
        'expired_dead_lettered' => 0,
        'claimed' => 1,
        'published' => 0,
        'failed' => 1,
        'dead_lettered' => 0,
        'reservation_conflicts' => 1,
    ]);

    $message->refresh();

    expect($message->published_at)
        ->toBeNull()
        ->and($message->dead_lettered_at)
        ->toBeNull()
        ->and($message->reservation_token)
        ->toBe($newReservationToken)
        ->and($message->last_error)
        ->toBe(ReservationStealingOutboxTransport::NEW_OWNER_ERROR);

    assertOwnershipConflictWarning(
        message: $message,
        operation: 'mark_dead_lettered',
    );
});
