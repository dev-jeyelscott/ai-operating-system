<?php

declare(strict_types=1);

namespace App\Infrastructure\Events;

use App\Application\Events\Contracts\DomainEventConsumptionStore;
use App\Application\Events\Data\StoredDomainEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stores successful consumer/event receipts in PostgreSQL.
 */
final class EloquentDomainEventConsumptionStore implements DomainEventConsumptionStore
{
    /**
     * Insert the consumer receipt inside the caller's active transaction.
     *
     * PostgreSQL's unique constraint resolves concurrent duplicate deliveries.
     * A return value of false means another successful transaction already
     * consumed this event for the same stable consumer identity.
     */
    public function claim(
        StoredDomainEvent $event,
        string $consumerName,
    ): bool {
        $now = CarbonImmutable::now();

        return DB::table('domain_event_consumptions')
            ->insertOrIgnore([
                'event_id' => $event->eventId,
                'consumer_name' => $consumerName,
                'organization_id' => $event->organizationId,
                'project_id' => $event->projectId,
                'consumed_at' => $now,
                'created_at' => $now,
            ]) === 1;
    }
}
