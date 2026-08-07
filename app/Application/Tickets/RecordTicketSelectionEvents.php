<?php

declare(strict_types=1);

namespace App\Application\Tickets;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Events\Contracts\DomainEventOutbox;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Events\DomainEventActor;
use App\Domain\Events\DomainEventEnvelope;
use App\Models\Execution;
use App\Models\Project;
use App\Models\RoadmapTask;
use App\Models\TicketExecutionLease;
use Illuminate\Support\Str;

/**
 * Records the authoritative ticket-selection and lease-acquisition events.
 */
final readonly class RecordTicketSelectionEvents
{
    /**
     * Inject transactional event and audit writers.
     */
    public function __construct(
        private DomainEventOutbox $outbox,
        private RecordAuditEvent $auditEvents,
    ) {}

    /**
     * Append selection and lease events inside the caller's transaction.
     *
     * @return array{ticket_selected:string, lease_acquired:string}
     */
    public function acquired(
        Project $project,
        RoadmapTask $ticket,
        TicketExecutionLease $lease,
        Execution $execution,
    ): array {
        $actor = DomainEventActor::system($lease->owner);

        $commonPayload = [
            'roadmap_id' => $ticket->roadmap_id,
            'roadmap_task_id' => $ticket->id,
            'ticket_id' => $ticket->stable_id,
            'lease_id' => $lease->id,
            'execution_id' => $execution->id,
            'owner' => $lease->owner,
            'ticket_status' => $ticket->status->value,
            'acquired_at' => $lease->acquired_at->toIso8601String(),
            'expires_at' => $lease->expires_at->toIso8601String(),
        ];

        $selectedEventId = (string) Str::ulid();

        $this->outbox->append(new DomainEventEnvelope(
            eventId: $selectedEventId,
            eventName: AuditEventType::TicketSelected->value,
            aggregateType: AuditSubjectType::RoadmapTask->value,
            aggregateId: (string) $ticket->id,
            organizationId: $project->organization_id,
            projectId: $project->id,
            actor: $actor,
            provider: null,
            occurredAt: $lease->acquired_at,
            correlationId: $execution->correlation_id,
            causationId: null,
            executionId: $execution->id,
            schemaVersion: 1,
            payload: $commonPayload,
        ));

        $this->auditEvents->record(
            organizationId: $project->organization_id,
            projectId: $project->id,
            actorType: AuditActorType::System,
            actorId: $lease->owner,
            eventType: AuditEventType::TicketSelected,
            subjectType: AuditSubjectType::RoadmapTask,
            subjectId: (string) $ticket->id,
            correlationId: $execution->correlation_id,
            metadata: $commonPayload,
            executionId: $execution->id,
            deduplicationKey: sprintf(
                'ticket:selected:%s',
                $lease->id,
            ),
        );

        $leaseEventId = (string) Str::ulid();

        $this->outbox->append(new DomainEventEnvelope(
            eventId: $leaseEventId,
            eventName: AuditEventType::TicketLeaseAcquired->value,
            aggregateType: AuditSubjectType::TicketExecutionLease->value,
            aggregateId: $lease->id,
            organizationId: $project->organization_id,
            projectId: $project->id,
            actor: $actor,
            provider: null,
            occurredAt: $lease->acquired_at,
            correlationId: $execution->correlation_id,
            causationId: $selectedEventId,
            executionId: $execution->id,
            schemaVersion: 1,
            payload: $commonPayload,
        ));

        $this->auditEvents->record(
            organizationId: $project->organization_id,
            projectId: $project->id,
            actorType: AuditActorType::System,
            actorId: $lease->owner,
            eventType: AuditEventType::TicketLeaseAcquired,
            subjectType: AuditSubjectType::TicketExecutionLease,
            subjectId: $lease->id,
            correlationId: $execution->correlation_id,
            metadata: $commonPayload,
            executionId: $execution->id,
            deduplicationKey: sprintf(
                'ticket:lease_acquired:%s',
                $lease->id,
            ),
        );

        return [
            'ticket_selected' => $selectedEventId,
            'lease_acquired' => $leaseEventId,
        ];
    }
}
