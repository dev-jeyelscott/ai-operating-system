<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories\Audit;

use App\Application\Audit\Contracts\AuditEventRepository;
use App\Application\Audit\Data\AuditEventData;
use App\Models\AuditEvent;
use App\Support\Security\SensitiveValueRedactor;

/**
 * Appends audit events through Eloquent and PostgreSQL.
 */
final readonly class EloquentAuditEventRepository implements AuditEventRepository
{
    /**
     * Inject the centralized sensitive-value redactor.
     */
    public function __construct(
        private SensitiveValueRedactor $redactor,
    ) {}

    /**
     * Append one immutable audit event.
     */
    public function append(AuditEventData $event): void
    {
        AuditEvent::query()->create([
            'event_id' => $event->eventId,
            'organization_id' => $event->organizationId,
            'project_id' => $event->projectId,
            'actor_type' => $event->actorType,
            'actor_id' => $event->actorId,
            'event_type' => $event->eventType,
            'subject_type' => $event->subjectType,
            'subject_id' => $event->subjectId,
            'correlation_id' => $event->correlationId,
            'metadata' => $this->redactor->redact($event->metadata),
            'occurred_at' => $event->occurredAt,
        ]);
    }
}
