<?php

declare(strict_types=1);

use App\Application\Events\Contracts\OutboxTransport;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Jobs\ConsumeOutboxMessage;
use App\Models\Organization;
use App\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create one complete outbox fixture for dead-letter tests.
 *
 * @param  array<string, mixed>  $overrides
 */
function createDeadLetterOutboxFixture(
    Organization $organization,
    array $overrides = [],
): OutboxMessage {
    $eventId = (string) Str::ulid();
    $now = CarbonImmutable::now();

    return OutboxMessage::query()->create(
        array_merge(
            [
                'event_id' => $eventId,
                'event_name' => 'test.dead_letter.created',
                'aggregate_type' => 'test.aggregate',
                'aggregate_id' => 'aggregate-1',
                'organization_id' => $organization->id,
                'project_id' => null,
                'occurred_at' => $now,
                'correlation_id' => $eventId,
                'causation_id' => null,
                'execution_id' => null,
                'schema_version' => 1,
                'envelope' => [
                    'event_id' => $eventId,
                    'event_name' => 'test.dead_letter.created',
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
                    'occurred_at' => $now->toIso8601String(),
                    'correlation_id' => $eventId,
                    'causation_id' => null,
                    'execution_id' => null,
                    'schema_version' => 1,
                    'payload' => [],
                ],
                'published_at' => null,
                'available_at' => $now,
                'reserved_until' => null,
                'reservation_token' => null,
                'dispatch_attempts' => 0,
                'last_error' => null,
                'dead_lettered_at' => null,
                'replay_count' => 0,
                'last_replayed_at' => null,
                'created_at' => $now,
            ],
            $overrides,
        ),
    );
}

/**
 * Persist one Laravel failed-job fixture for ConsumeOutboxMessage.
 */
function createFailedConsumeOutboxJobFixture(
    string $eventId,
): string {
    $uuid = (string) Str::uuid();

    $payload = json_encode(
        [
            'uuid' => $uuid,
            'displayName' => ConsumeOutboxMessage::class,
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'maxTries' => 5,
            'maxExceptions' => 3,
            'failOnTimeout' => false,
            'backoff' => '5,30,120,300',
            'timeout' => 60,
            'retryUntil' => null,
            'data' => [
                'commandName' => ConsumeOutboxMessage::class,
                'command' => serialize(
                    new ConsumeOutboxMessage($eventId),
                ),
            ],
            'attempts' => 5,
        ],
        JSON_THROW_ON_ERROR,
    );

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => $payload,
        'exception' => 'RuntimeException: consumer unavailable',
        'failed_at' => CarbonImmutable::now(),
    ]);

    return $uuid;
}

test('an exhausted outbox delivery becomes an explicit dead letter', function (): void {
    config()->set(
        'domain-events.dispatcher.maximum_attempts',
        1,
    );

    $this->app->instance(
        OutboxTransport::class,
        new class implements OutboxTransport
        {
            /**
             * Simulate a terminal queue transport failure.
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
    $message = createDeadLetterOutboxFixture($organization);

    $this->artisan('outbox:dispatch')
        ->assertFailed();

    $message->refresh();

    expect($message->published_at)
        ->toBeNull()
        ->and($message->dispatch_attempts)
        ->toBe(1)
        ->and($message->dead_lettered_at)
        ->not->toBeNull()
        ->and($message->reservation_token)
        ->toBeNull()
        ->and($message->reserved_until)
        ->toBeNull()
        ->and($message->last_error)
        ->toContain('Redis transport unavailable');
});

test('an operator can inspect and reset an outbox dead letter', function (): void {
    $organization = Organization::factory()->create();
    $failedAt = CarbonImmutable::now()->subMinute();

    $message = createDeadLetterOutboxFixture(
        organization: $organization,
        overrides: [
            'dispatch_attempts' => 10,
            'last_error' => 'RuntimeException: transport unavailable',
            'dead_lettered_at' => $failedAt,
        ],
    );

    $listExitCode = Artisan::call(
        'dead-letters:list',
        [
            '--source' => 'outbox',
            '--limit' => 10,
        ],
    );

    expect($listExitCode)
        ->toBe(Command::SUCCESS)
        ->and(Artisan::output())
        ->toContain($message->event_id)
        ->toContain('RuntimeException');

    $replayExitCode = Artisan::call(
        'dead-letters:replay',
        [
            'source' => 'outbox',
            'id' => $message->event_id,
            '--actor' => 'test-operator',
            '--reason' => 'Redis health has been verified.',
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
        ->and($message->last_error)
        ->toBeNull()
        ->and($message->replay_count)
        ->toBe(1)
        ->and($message->last_replayed_at)
        ->not->toBeNull()
        ->and($message->available_at->lessThanOrEqualTo(
            CarbonImmutable::now(),
        ))
        ->toBeTrue();

    $this->assertDatabaseHas('audit_events', [
        'organization_id' => $organization->id,
        'event_type' => AuditEventType::DeadLetterReplayRequested->value,
        'subject_type' => AuditSubjectType::OutboxMessage->value,
        'subject_id' => $message->event_id,
        'actor_id' => 'test-operator',
    ]);
});

test('an operator can replay one failed event consumer job', function (): void {
    $organization = Organization::factory()->create();

    $message = createDeadLetterOutboxFixture(
        organization: $organization,
        overrides: [
            'published_at' => CarbonImmutable::now(),
        ],
    );

    $failedJobId = createFailedConsumeOutboxJobFixture(
        $message->event_id,
    );

    $transport = new class implements OutboxTransport
    {
        /**
         * @var list<string>
         */
        public array $publishedEventIds = [];

        /**
         * Capture replayed event IDs without contacting Redis.
         */
        public function publish(string $eventId): void
        {
            $this->publishedEventIds[] = $eventId;
        }
    };

    $this->app->instance(
        OutboxTransport::class,
        $transport,
    );

    $listExitCode = Artisan::call(
        'dead-letters:list',
        [
            '--source' => 'queue',
            '--limit' => 10,
        ],
    );

    expect($listExitCode)
        ->toBe(Command::SUCCESS)
        ->and(Artisan::output())
        ->toContain($failedJobId)
        ->toContain($message->event_id);

    $replayExitCode = Artisan::call(
        'dead-letters:replay',
        [
            'source' => 'queue',
            'id' => $failedJobId,
            '--actor' => 'test-operator',
            '--reason' => 'The consumer dependency has recovered.',
            '--yes' => true,
        ],
    );

    expect($replayExitCode)
        ->toBe(Command::SUCCESS)
        ->and($transport->publishedEventIds)
        ->toBe([$message->event_id]);

    $this->assertDatabaseMissing('failed_jobs', [
        'uuid' => $failedJobId,
    ]);

    $this->assertDatabaseHas('audit_events', [
        'organization_id' => $organization->id,
        'event_type' => AuditEventType::DeadLetterReplayRequested->value,
        'subject_type' => AuditSubjectType::FailedQueueJob->value,
        'subject_id' => $failedJobId,
        'actor_id' => 'test-operator',
    ]);

    $duplicateExitCode = Artisan::call(
        'dead-letters:replay',
        [
            'source' => 'queue',
            'id' => $failedJobId,
            '--actor' => 'test-operator',
            '--reason' => 'Duplicate replay request.',
            '--yes' => true,
        ],
    );

    expect($duplicateExitCode)
        ->toBe(Command::FAILURE)
        ->and($transport->publishedEventIds)
        ->toBe([$message->event_id]);
});

test('an arbitrary failed Laravel job cannot be replayed', function (): void {
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(
            [
                'uuid' => $uuid,
                'displayName' => 'App\Jobs\DangerousUnknownJob',
                'data' => [
                    'command' => 'unsupported',
                ],
            ],
            JSON_THROW_ON_ERROR,
        ),
        'exception' => 'RuntimeException: arbitrary failure',
        'failed_at' => CarbonImmutable::now(),
    ]);

    $exitCode = Artisan::call(
        'dead-letters:replay',
        [
            'source' => 'queue',
            'id' => $uuid,
            '--actor' => 'test-operator',
            '--reason' => 'Attempt unsafe replay.',
            '--yes' => true,
        ],
    );

    expect($exitCode)
        ->toBe(Command::FAILURE);

    $this->assertDatabaseHas('failed_jobs', [
        'uuid' => $uuid,
    ]);
});
