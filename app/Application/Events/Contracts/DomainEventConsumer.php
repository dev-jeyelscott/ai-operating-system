<?php

declare(strict_types=1);

namespace App\Application\Events\Contracts;

use App\Application\Events\Data\StoredDomainEvent;

/**
 * Applies one domain event to an internal projection or application process.
 *
 * Database side effects are wrapped in the same transaction as the durable
 * consumer receipt. External API calls must additionally use eventId as the
 * provider-facing idempotency key because they cannot be transactionally rolled
 * back with PostgreSQL.
 */
interface DomainEventConsumer
{
    /**
     * Return the stable consumer identity used for duplicate prevention.
     */
    public function consumerName(): string;

    /**
     * Return canonical event names handled by this consumer.
     *
     * @return list<string>
     */
    public function subscribedEventNames(): array;

    /**
     * Apply the event side effect.
     */
    public function handle(StoredDomainEvent $event): void;
}
