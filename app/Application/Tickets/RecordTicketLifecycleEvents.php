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
use App\Domain\Tickets\TicketExternalStateSource;
use App\Domain\Tickets\TicketStatus;
use App\Models\Project;
use App\Models\RoadmapTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Appends authoritative ticket lifecycle events to the existing outbox and audit stores.
 */
final readonly class RecordTicketLifecycleEvents
{
    public function __construct(
        private DomainEventOutbox $outbox,
        private RecordAuditEvent $auditEvents,
    ) {}

    public function transitioned(
        Project $project,
        RoadmapTask $ticket,
        TicketStatus $from,
        TicketStatus $to,
        string $actorId,
        string $correlationId,
        string $idempotencyKeyHash,
        string $requestFingerprint,
        ?string $executionId,
        CarbonImmutable $occurredAt,
    ): string {
        return $this->append(
            project: $project,
            ticket: $ticket,
            eventType: AuditEventType::TicketStatusTransitioned,
            actorId: $actorId,
            correlationId: $correlationId,
            idempotencyKeyHash: $idempotencyKeyHash,
            requestFingerprint: $requestFingerprint,
            executionId: $executionId,
            occurredAt: $occurredAt,
            payload: [
                'from_status' => $from->value,
                'to_status' => $to->value,
                'status_changed_at' => $ticket->status_changed_at->toIso8601String(),
                'ready_at' => $ticket->ready_at?->toIso8601String(),
            ],
        );
    }

    public function reconciled(
        Project $project,
        RoadmapTask $ticket,
        TicketExternalStateSource $source,
        string $actorId,
        string $correlationId,
        string $idempotencyKeyHash,
        string $requestFingerprint,
        CarbonImmutable $observedAt,
    ): string {
        return $this->append(
            project: $project,
            ticket: $ticket,
            eventType: AuditEventType::TicketExternalStateReconciled,
            actorId: $actorId,
            correlationId: $correlationId,
            idempotencyKeyHash: $idempotencyKeyHash,
            requestFingerprint: $requestFingerprint,
            executionId: null,
            occurredAt: $observedAt,
            payload: [
                'source' => $source->value,
                'observed_at' => $observedAt->toIso8601String(),
                'desired_state' => $ticket->desired_state->value,
                'reported_state' => $ticket->reported_state,
                'observed_state' => $ticket->observed_state,
                'actual_state' => $ticket->actual_state->value,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function append(
        Project $project,
        RoadmapTask $ticket,
        AuditEventType $eventType,
        string $actorId,
        string $correlationId,
        string $idempotencyKeyHash,
        string $requestFingerprint,
        ?string $executionId,
        CarbonImmutable $occurredAt,
        array $payload,
    ): string {
        $eventId = (string) Str::ulid();
        $metadata = [
            'roadmap_id' => $ticket->roadmap_id,
            'roadmap_task_id' => $ticket->id,
            'ticket_id' => $ticket->stable_id,
            'request_fingerprint' => $requestFingerprint,
            ...$payload,
        ];

        $this->outbox->append(new DomainEventEnvelope(
            eventId: $eventId,
            eventName: $eventType->value,
            aggregateType: AuditSubjectType::RoadmapTask->value,
            aggregateId: (string) $ticket->id,
            organizationId: $project->organization_id,
            projectId: $project->id,
            actor: DomainEventActor::system($actorId),
            provider: null,
            occurredAt: $occurredAt,
            correlationId: $correlationId,
            causationId: null,
            executionId: $executionId,
            schemaVersion: 1,
            payload: $metadata,
        ));

        $this->auditEvents->record(
            organizationId: $project->organization_id,
            projectId: $project->id,
            actorType: AuditActorType::System,
            actorId: $actorId,
            eventType: $eventType,
            subjectType: AuditSubjectType::RoadmapTask,
            subjectId: (string) $ticket->id,
            correlationId: $correlationId,
            metadata: $metadata,
            executionId: $executionId,
            deduplicationKey: self::deduplicationKey(
                eventType: $eventType,
                ticketId: $ticket->id,
                idempotencyKeyHash: $idempotencyKeyHash,
            ),
        );

        return $eventId;
    }

    public static function deduplicationKey(
        AuditEventType $eventType,
        int $ticketId,
        string $idempotencyKeyHash,
    ): string {
        return sprintf(
            'ticket:%s:%d:%s',
            $eventType->value,
            $ticketId,
            $idempotencyKeyHash,
        );
    }
}
