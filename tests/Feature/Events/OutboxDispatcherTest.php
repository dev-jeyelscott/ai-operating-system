<?php

declare(strict_types=1);

use App\Application\Events\Contracts\OutboxTransport;
use App\Jobs\ConsumeOutboxMessage;
use App\Models\Organization;
use App\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Persist one valid outbox fixture ready for dispatcher processing.
 *
 * @param  array<string, mixed>  $overrides
 */
function createDispatchableOutboxMessage(
    Organization $organization,
    array $overrides = [],
): OutboxMessage {
    $eventId = (string) Str::ulid();
    $occurredAt = CarbonImmutable::now();

    return OutboxMessage::query()->create(
        array_merge(
            [
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
            ],
            $overrides,
        ),
    );
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

test('it persists only sanitized details when an outbox transport fails', function (): void {
    $organization = Organization::factory()->create();
    $message = createDispatchableOutboxMessage($organization);

    app()->instance(
        OutboxTransport::class,
        new class implements OutboxTransport
        {
            public function publish(string $eventId): void
            {
                throw new RuntimeException(
                    'https://operator:fake-secret@example.test/hook Authorization: Bearer fake-secret request_body={"token":"fake-secret"}',
                );
            }
        },
    );

    $this->artisan('outbox:dispatch')->assertSuccessful();

    $storedError = $message->fresh()?->last_error;

    expect($storedError)
        ->toBeString()
        ->not->toContain('fake-secret')
        ->toContain('outbox.transport_failed')
        ->toContain('RuntimeException')
        ->toContain('[redacted-url]');
});

test('an expired exhausted reservation is dead-lettered and can be replayed safely', function (): void {
    Queue::fake();

    $maximumAttempts = 3;

    config()->set(
        'domain-events.dispatcher.maximum_attempts',
        $maximumAttempts,
    );

    $organization = Organization::factory()->create();

    $message = createDispatchableOutboxMessage(
        organization: $organization,
        overrides: [
            'dispatch_attempts' => $maximumAttempts,
            'reserved_until' => CarbonImmutable::now()->subMinute(),
            'reservation_token' => (string) Str::uuid(),
            'last_error' => 'RuntimeException: private worker implementation details',
        ],
    );

    $firstDispatchExitCode = Artisan::call(
        'outbox:dispatch',
        [
            '--limit' => 10,
        ],
    );

    expect($firstDispatchExitCode)
        ->toBe(Command::SUCCESS)
        ->and(Artisan::output())
        ->toContain('Expired dead-lettered: 1;');

    Queue::assertNothingPushed();

    $message->refresh();

    expect($message->published_at)
        ->toBeNull()
        ->and($message->dispatch_attempts)
        ->toBe($maximumAttempts)
        ->and($message->dead_lettered_at)
        ->not->toBeNull()
        ->and($message->reservation_token)
        ->toBeNull()
        ->and($message->reserved_until)
        ->toBeNull()
        ->and($message->last_error)
        ->toBe(
            'Outbox reservation expired after the maximum delivery attempts.',
        );

    $secondDispatchExitCode = Artisan::call(
        'outbox:dispatch',
        [
            '--limit' => 10,
        ],
    );

    expect($secondDispatchExitCode)
        ->toBe(Command::SUCCESS)
        ->and(Artisan::output())
        ->toContain('Expired dead-lettered: 0;');

    Queue::assertNothingPushed();

    $message->refresh();

    expect($message->dispatch_attempts)
        ->toBe($maximumAttempts)
        ->and($message->dead_lettered_at)
        ->not->toBeNull();

    $replayExitCode = Artisan::call(
        'dead-letters:replay',
        [
            'source' => 'outbox',
            'id' => $message->event_id,
            '--actor' => 'test-operator',
            '--reason' => 'The expired reservation was reviewed and may be retried.',
            '--yes' => true,
        ],
    );

    expect($replayExitCode)
        ->toBe(Command::SUCCESS);

    $message->refresh();

    expect($message->dead_lettered_at)
        ->toBeNull()
        ->and($message->dispatch_attempts)
        ->toBe(0)
        ->and($message->reservation_token)
        ->toBeNull()
        ->and($message->reserved_until)
        ->toBeNull()
        ->and($message->last_error)
        ->toBeNull()
        ->and($message->replay_count)
        ->toBe(1)
        ->and($message->last_replayed_at)
        ->not->toBeNull();

    $dispatchAfterReplayExitCode = Artisan::call(
        'outbox:dispatch',
        [
            '--limit' => 10,
        ],
    );

    expect($dispatchAfterReplayExitCode)
        ->toBe(Command::SUCCESS)
        ->and(Artisan::output())
        ->toContain('claimed: 1; published: 1;');

    Queue::assertPushed(
        ConsumeOutboxMessage::class,
        1,
    );

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
        ->and($message->dead_lettered_at)
        ->toBeNull()
        ->and($message->reservation_token)
        ->toBeNull()
        ->and($message->reserved_until)
        ->toBeNull();
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
