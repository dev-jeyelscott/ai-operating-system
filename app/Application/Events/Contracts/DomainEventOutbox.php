<?php

declare(strict_types=1);

namespace App\Application\Events\Contracts;

use App\Domain\Events\DomainEventEnvelope;

/**
 * Persistence boundary for appending canonical domain-event envelopes.
 */
interface DomainEventOutbox
{
    /**
     * Append one domain event to the durable transactional outbox.
     *
     * The caller must execute this operation inside the same database
     * transaction as the related authoritative business-state mutation.
     */
    public function append(DomainEventEnvelope $event): void;
}
