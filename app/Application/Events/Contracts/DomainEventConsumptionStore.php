<?php

declare(strict_types=1);

namespace App\Application\Events\Contracts;

use App\Application\Events\Data\StoredDomainEvent;

/**
 * Creates durable successful-consumption receipts.
 */
interface DomainEventConsumptionStore
{
    /**
     * Claim a consumer/event pair inside the active database transaction.
     *
     * Returns false when the pair was already consumed successfully.
     */
    public function claim(
        StoredDomainEvent $event,
        string $consumerName,
    ): bool;
}
