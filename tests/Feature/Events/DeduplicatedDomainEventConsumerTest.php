<?php

declare(strict_types=1);

use App\Application\Events\Contracts\DomainEventConsumer;
use App\Application\Events\Contracts\DomainEventConsumerRegistry;
use App\Application\Events\Data\StoredDomainEvent;
use App\Application\Events\DeduplicatedDomainEventConsumer;
use App\Jobs\ConsumeOutboxMessage;
use App\Models\Organization;
use App\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Schema::create(
        'test_event_side_effects',
        function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('event_id', 26);
            $table->string('consumer_name', 191);
            $table->timestampTz('created_at');
        },
    );
});

afterEach(function (): void {
    Schema::dropIfExists('test_event_side_effects');
});

/**
 * Persist one outbox fixture used by consumer-job tests.
 */
function createConsumerOutboxMessage(
    Organization $organization,
): OutboxMessage {
    $eventId = (string) Str::ulid();
    $now = CarbonImmutable::now();

    return OutboxMessage::query()->create([
        'event_id' => $eventId,
        'event_name' => 'test.event.created',
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
            'event_name' => 'test.event.created',
            'schema_version' => 1,
            'payload' => [
                'value' => 'example',
            ],
        ],
        'published_at' => $now,
        'available_at' => $now,
        'reserved_until' => null,
        'reservation_token' => null,
        'dispatch_attempts' => 1,
        'last_error' => null,
        'created_at' => $now,
    ]);
}

test('replaying the same consumer job does not duplicate its side effect', function (): void {
    $organization = Organization::factory()->create();
    $message = createConsumerOutboxMessage($organization);

    $consumer = new class implements DomainEventConsumer
    {
        /**
         * Return the durable test consumer identity.
         */
        public function consumerName(): string
        {
            return 'tests.side-effect-projector';
        }

        /**
         * Subscribe to the test event.
         *
         * @return list<string>
         */
        public function subscribedEventNames(): array
        {
            return ['test.event.created'];
        }

        /**
         * Record one database side effect.
         */
        public function handle(
            StoredDomainEvent $event,
        ): void {
            DB::table('test_event_side_effects')->insert([
                'event_id' => $event->eventId,
                'consumer_name' => $this->consumerName(),
                'created_at' => CarbonImmutable::now(),
            ]);
        }
    };

    $registry = new class($consumer) implements DomainEventConsumerRegistry
    {
        /**
         * Create the test registry.
         */
        public function __construct(
            private readonly DomainEventConsumer $consumer,
        ) {}

        /**
         * Return the configured test consumer.
         *
         * @return list<DomainEventConsumer>
         */
        public function forEvent(string $eventName): array
        {
            return [$this->consumer];
        }
    };

    $consumerRunner = app(
        DeduplicatedDomainEventConsumer::class,
    );

    $job = new ConsumeOutboxMessage(
        $message->event_id,
    );

    /*
     * Simulate a Redis duplicate or manual job replay.
     */
    $job->handle($registry, $consumerRunner);
    $job->handle($registry, $consumerRunner);

    expect(
        DB::table('test_event_side_effects')->count(),
    )->toBe(1);

    expect(
        DB::table('domain_event_consumptions')
            ->where('event_id', $message->event_id)
            ->where(
                'consumer_name',
                'tests.side-effect-projector',
            )
            ->count(),
    )->toBe(1);
});

test('a failed consumer rolls back its receipt and database side effect', function (): void {
    $organization = Organization::factory()->create();
    $eventId = (string) Str::ulid();

    $event = new StoredDomainEvent(
        eventId: $eventId,
        eventName: 'test.event.created',
        organizationId: $organization->id,
        projectId: null,
        schemaVersion: 1,
        envelope: [
            'event_id' => $eventId,
            'event_name' => 'test.event.created',
            'schema_version' => 1,
            'payload' => [],
        ],
    );

    $failingConsumer = new class implements DomainEventConsumer
    {
        /**
         * Return a stable consumer identity.
         */
        public function consumerName(): string
        {
            return 'tests.transactional-projector';
        }

        /**
         * Subscribe to the test event.
         *
         * @return list<string>
         */
        public function subscribedEventNames(): array
        {
            return ['test.event.created'];
        }

        /**
         * Write a side effect and then simulate failure.
         */
        public function handle(
            StoredDomainEvent $event,
        ): void {
            DB::table('test_event_side_effects')->insert([
                'event_id' => $event->eventId,
                'consumer_name' => $this->consumerName(),
                'created_at' => CarbonImmutable::now(),
            ]);

            throw new RuntimeException(
                'Consumer processing failed.',
            );
        }
    };

    $runner = app(
        DeduplicatedDomainEventConsumer::class,
    );

    expect(
        fn (): bool => $runner->handle(
            event: $event,
            consumer: $failingConsumer,
        ),
    )->toThrow(
        RuntimeException::class,
        'Consumer processing failed.',
    );

    expect(
        DB::table('test_event_side_effects')->count(),
    )->toBe(0);

    expect(
        DB::table('domain_event_consumptions')
            ->where('event_id', $eventId)
            ->count(),
    )->toBe(0);

    /*
     * A later queue retry using the same stable consumer name can now succeed.
     */
    $successfulConsumer = new class implements DomainEventConsumer
    {
        /**
         * Reuse the exact durable consumer identity.
         */
        public function consumerName(): string
        {
            return 'tests.transactional-projector';
        }

        /**
         * Subscribe to the test event.
         *
         * @return list<string>
         */
        public function subscribedEventNames(): array
        {
            return ['test.event.created'];
        }

        /**
         * Apply the retry successfully.
         */
        public function handle(
            StoredDomainEvent $event,
        ): void {
            DB::table('test_event_side_effects')->insert([
                'event_id' => $event->eventId,
                'consumer_name' => $this->consumerName(),
                'created_at' => CarbonImmutable::now(),
            ]);
        }
    };

    expect(
        $runner->handle(
            event: $event,
            consumer: $successfulConsumer,
        ),
    )->toBeTrue();

    expect(
        DB::table('test_event_side_effects')->count(),
    )->toBe(1);

    expect(
        DB::table('domain_event_consumptions')
            ->where('event_id', $eventId)
            ->count(),
    )->toBe(1);
});
