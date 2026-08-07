<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories\Events;

use App\Application\Events\Contracts\DomainEventOutbox;
use App\Domain\Events\DomainEventEnvelope;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Appends canonical domain-event envelopes through Eloquent and PostgreSQL.
 */
final class EloquentDomainEventOutbox implements DomainEventOutbox
{
    /**
     * Append one canonical envelope inside an active database transaction.
     */
    public function append(DomainEventEnvelope $event): void
    {
        /*
         * Fail closed when a caller attempts a non-transactional append.
         *
         * The test suite may already have an outer RefreshDatabase transaction,
         * but production application paths must still use TransactionalOutbox.
         */
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException(
                'Domain events must be appended inside an active database transaction.',
            );
        }

        OutboxMessage::query()->create([
            'event_id' => $event->eventId,
            'event_name' => $event->eventName,
            'aggregate_type' => $event->aggregateType,
            'aggregate_id' => $event->aggregateId,
            'organization_id' => $event->organizationId,
            'project_id' => $event->projectId,
            'occurred_at' => $event->occurredAt,
            'correlation_id' => $event->correlationId,
            'causation_id' => $event->causationId,
            'execution_id' => $event->executionId,
            'schema_version' => $event->schemaVersion,
            'envelope' => $event->toArray(),
            'published_at' => null,
        ]);
    }
}
