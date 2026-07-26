<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Domain\Events\DomainEvent;
use App\Domain\Events\DomainEventActor;
use App\Domain\Events\DomainEventEnvelope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Creates one validated domain-event envelope from a versioned event payload.
 */
final readonly class CreateDomainEventEnvelope
{
    /**
     * Generate event identity and wrap the versioned business payload.
     */
    public function create(
        DomainEvent $event,
        string $aggregateType,
        string $aggregateId,
        int $organizationId,
        ?int $projectId,
        DomainEventActor $actor,
        ?string $provider = null,
        ?string $correlationId = null,
        ?string $causationId = null,
        ?string $executionId = null,
    ): DomainEventEnvelope {
        $eventId = (string) Str::ulid();

        return new DomainEventEnvelope(
            eventId: $eventId,
            eventName: $event::eventName(),
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            organizationId: $organizationId,
            projectId: $projectId,
            actor: $actor,
            provider: $provider,
            occurredAt: CarbonImmutable::now(),
            correlationId: $correlationId ?? $eventId,
            causationId: $causationId,
            executionId: $executionId,
            schemaVersion: $event::schemaVersion(),
            payload: $event->payload(),
        );
    }
}
