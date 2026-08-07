<?php

declare(strict_types=1);

namespace App\Infrastructure\Events;

use App\Application\Events\Contracts\OutboxTransport;
use App\Jobs\ConsumeOutboxMessage;
use Illuminate\Support\Facades\Bus;

/**
 * Publishes persisted events through Laravel's Redis queue.
 */
final class LaravelOutboxTransport implements OutboxTransport
{
    /**
     * Queue one consumer job using only the stable event identifier.
     *
     * The complete envelope remains in PostgreSQL and is loaded by the worker.
     * This avoids copying potentially large or sensitive payloads into Redis.
     */
    public function publish(string $eventId): void
    {
        $connection = config(
            'domain-events.queue.connection',
            'redis',
        );

        $queue = config(
            'domain-events.queue.name',
            'default',
        );

        if (! is_string($connection) || trim($connection) === '') {
            $connection = 'redis';
        }

        if (! is_string($queue) || trim($queue) === '') {
            $queue = 'default';
        }

        $job = (new ConsumeOutboxMessage($eventId))
            ->onConnection($connection)
            ->onQueue($queue)
            ->afterCommit();

        Bus::dispatch($job);
    }
}
