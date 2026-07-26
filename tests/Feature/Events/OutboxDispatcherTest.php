<?php

declare(strict_types=1);

use App\Application\Events\Contracts\OutboxTransport;
use App\Jobs\ConsumeOutboxMessage;
use App\Models\Organization;
use App\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Persist one valid outbox fixture ready for dispatcher processing.
 */
function createDispatchableOutboxMessage(
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
        'created_at' => $occurredAt,
    ]);
}

test('the dispatcher queues and publishes a committed outbox message', function (): void {
    Queue::fake();

    $organization = Organization::factory()->create();
    $message = createDispatchableOutboxMessage($organization);

    $this->artisan('outbox:dispatch', [
        '--limit' => 10,
    ])->assertSuccessful();

    Queue::assertPushed(
        ConsumeOutboxMessage::class,
        function (
            ConsumeOutboxMessage $job,
        ) use ($message): bool {
            return $job->eventId === $message->event_id;
        },
    );

    $message->refresh();

    expect($message->published_at)
        ->not->toBeNull()
        ->and($message->dispatch_attempts)
        ->toBe(1)
        ->and($message->reservation_token)
        ->toBeNull()
        ->and($message->reserved_until)
        ->toBeNull();
});

test('an already published event is not dispatched again', function (): void {
    Queue::fake();

    $organization = Organization::factory()->create();
    $message = createDispatchableOutboxMessage($organization);

    $this->artisan('outbox:dispatch')
        ->assertSuccessful();

    $this->artisan('outbox:dispatch')
        ->assertSuccessful();

    Queue::assertPushed(
        ConsumeOutboxMessage::class,
        1,
    );

    expect($message->fresh()->dispatch_attempts)
        ->toBe(1);
});

test('a transport failure releases the event for a bounded retry', function (): void {
    $this->app->instance(
        OutboxTransport::class,
        new class implements OutboxTransport
        {
            /**
             * Simulate an unavailable queue transport.
             */
            public function publish(string $eventId): void
            {
                throw new RuntimeException(
                    'Redis transport unavailable.',
                );
            }
        },
    );

    $organization = Organization::factory()->create();
    $message = createDispatchableOutboxMessage($organization);

    $this->artisan('outbox:dispatch')
        ->assertFailed();

    $message->refresh();

    expect($message->published_at)
        ->toBeNull()
        ->and($message->dispatch_attempts)
        ->toBe(1)
        ->and($message->reservation_token)
        ->toBeNull()
        ->and($message->reserved_until)
        ->toBeNull()
        ->and($message->last_error)
        ->toContain('Redis transport unavailable')
        ->and($message->available_at->isFuture())
        ->toBeTrue();
});
