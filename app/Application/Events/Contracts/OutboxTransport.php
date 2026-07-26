<?php

declare(strict_types=1);

namespace App\Application\Events\Contracts;

/**
 * Delivers one persisted outbox event to the asynchronous consumer pipeline.
 */
interface OutboxTransport
{
    /**
     * Queue one event by its stable event identifier.
     */
    public function publish(string $eventId): void;
}
