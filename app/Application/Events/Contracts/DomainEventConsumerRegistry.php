<?php

declare(strict_types=1);

namespace App\Application\Events\Contracts;

/**
 * Resolves configured consumers for one canonical event name.
 */
interface DomainEventConsumerRegistry
{
    /**
     * Return all consumers subscribed to the event.
     *
     * @return list<DomainEventConsumer>
     */
    public function forEvent(string $eventName): array;
}
