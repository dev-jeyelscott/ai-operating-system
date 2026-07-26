<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Events\Contracts\DomainEventConsumerRegistry;
use App\Application\Events\Data\StoredDomainEvent;
use App\Application\Events\DeduplicatedDomainEventConsumer;
use App\Models\OutboxMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use UnexpectedValueException;

/**
 * Delivers one persisted event to all subscribed deduplicated consumers.
 */
final class ConsumeOutboxMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Limit automatic processing attempts.
     */
    public int $tries = 5;

    /**
     * Stop one consumer execution that exceeds this duration.
     */
    public int $timeout = 60;

    /**
     * Prevent repeated infrastructure exceptions from running indefinitely.
     */
    public int $maxExceptions = 3;

    /**
     * Create the queued event-delivery job.
     */
    public function __construct(
        public readonly string $eventId,
    ) {}

    /**
     * Return bounded queue retry delays in seconds.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    /**
     * Deliver the event to every registered subscriber.
     */
    public function handle(
        DomainEventConsumerRegistry $registry,
        DeduplicatedDomainEventConsumer $deduplicatedConsumer,
    ): void {
        $message = OutboxMessage::query()
            ->where('event_id', $this->eventId)
            ->firstOrFail();

        /*
     * Retrieve the value through Eloquent's attribute API instead of dynamic
     * property inference. The model cast should return an array, while this
     * runtime guard safely rejects malformed or incorrectly cast envelopes.
     */
        $envelope = $message->getAttribute('envelope');

        if (! is_array($envelope)) {
            throw new UnexpectedValueException(
                'The stored domain-event envelope must be an array.',
            );
        }

        /** @var array<string, mixed> $envelope */
        $event = new StoredDomainEvent(
            eventId: $message->event_id,
            eventName: $message->event_name,
            organizationId: $message->organization_id,
            projectId: $message->project_id,
            schemaVersion: $message->schema_version,
            envelope: $envelope,
        );

        foreach (
            $registry->forEvent($event->eventName) as $consumer
        ) {
            $deduplicatedConsumer->handle(
                event: $event,
                consumer: $consumer,
            );
        }
    }

    /**
     * Add searchable event metadata to Laravel Horizon.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'domain-event',
            'event:'.$this->eventId,
        ];
    }
}
