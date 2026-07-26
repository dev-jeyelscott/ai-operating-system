<?php

declare(strict_types=1);

namespace App\Application\Approvals;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Events\Contracts\DomainEventOutbox;
use App\Domain\Approvals\ApprovalDecision;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Events\DomainEventActor;
use App\Domain\Events\DomainEventEnvelope;
use App\Models\Approval;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Appends approval domain and audit events inside the caller's transaction.
 */
final readonly class RecordApprovalLifecycleEvent
{
    /**
     * Inject transactional outbox and audit persistence.
     */
    public function __construct(
        private DomainEventOutbox $outbox,
        private RecordAuditEvent $auditEvents,
    ) {}

    /**
     * Record creation of a new approval request.
     */
    public function requested(
        Approval $approval,
        ?User $requester,
        string $correlationId,
        ?string $causationId,
    ): string {
        return $this->append(
            approval: $approval,
            domainActor: $requester === null
                ? DomainEventActor::system('approval-engine')
                : DomainEventActor::user($requester->id),
            auditActorType: $requester === null
                ? AuditActorType::System
                : AuditActorType::User,
            auditActorId: $requester === null
                ? 'approval-engine'
                : (string) $requester->id,
            eventName: 'approval.requested',
            auditEventType: AuditEventType::ApprovalRequested,
            correlationId: $correlationId,
            causationId: $causationId,
            payload: [
                'expires_at' => $approval->expires_at?->toISOString(),
            ],
        );
    }

    /**
     * Record an authorized approve or reject decision.
     */
    public function decided(
        Approval $approval,
        User $actor,
        ApprovalDecision $decision,
        string $correlationId,
        ?string $causationId,
    ): string {
        $eventName = match ($decision) {
            ApprovalDecision::Approve => 'approval.granted',
            ApprovalDecision::Reject => 'approval.rejected',
        };

        $auditEventType = match ($decision) {
            ApprovalDecision::Approve => AuditEventType::ApprovalGranted,
            ApprovalDecision::Reject => AuditEventType::ApprovalRejected,
        };

        return $this->append(
            approval: $approval,
            domainActor: DomainEventActor::user($actor->id),
            auditActorType: AuditActorType::User,
            auditActorId: (string) $actor->id,
            eventName: $eventName,
            auditEventType: $auditEventType,
            correlationId: $correlationId,
            causationId: $causationId,
            payload: [
                'decision' => $decision->value,
                'reason_present' => $approval->decision_reason !== null,
            ],
        );
    }

    /**
     * Record deterministic expiration of a pending approval.
     */
    public function expired(
        Approval $approval,
        string $correlationId,
        ?string $causationId,
    ): string {
        return $this->append(
            approval: $approval,
            domainActor: DomainEventActor::system('approval-engine'),
            auditActorType: AuditActorType::System,
            auditActorId: 'approval-engine',
            eventName: 'approval.expired',
            auditEventType: AuditEventType::ApprovalExpired,
            correlationId: $correlationId,
            causationId: $causationId,
            payload: [
                'expired_at' => CarbonImmutable::now()->toISOString(),
            ],
        );
    }

    /**
     * Append matching domain and audit records for one lifecycle event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function append(
        Approval $approval,
        DomainEventActor $domainActor,
        AuditActorType $auditActorType,
        string $auditActorId,
        string $eventName,
        AuditEventType $auditEventType,
        string $correlationId,
        ?string $causationId,
        array $payload,
    ): string {
        $approval->loadMissing('project');

        $eventId = (string) Str::ulid();
        $occurredAt = CarbonImmutable::now();

        $basePayload = [
            'approval_id' => $approval->id,
            'approval_type' => $approval->type->value,
            'status' => $approval->status->value,
            'workflow_instance_id' => $approval->workflow_instance_id,
            'execution_id' => $approval->execution_id,
        ];

        $this->outbox->append(new DomainEventEnvelope(
            eventId: $eventId,
            eventName: $eventName,
            aggregateType: 'approval',
            aggregateId: $approval->id,
            organizationId: $approval->project->organization_id,
            projectId: $approval->project_id,
            actor: $domainActor,
            provider: null,
            occurredAt: $occurredAt,
            correlationId: $correlationId,
            causationId: $causationId,
            executionId: $approval->execution_id,
            schemaVersion: 1,
            payload: array_merge($basePayload, $payload),
        ));

        $this->auditEvents->record(
            organizationId: $approval->project->organization_id,
            projectId: $approval->project_id,
            actorType: $auditActorType,
            actorId: $auditActorId,
            eventType: $auditEventType,
            subjectType: AuditSubjectType::Approval,
            subjectId: $approval->id,
            correlationId: $correlationId,
            metadata: array_merge($basePayload, $payload),
            causationId: $causationId,
            executionId: $approval->execution_id,
            schemaVersion: 1,
            deduplicationKey: sprintf(
                'approval:%s:%s',
                $auditEventType->value,
                $approval->id,
            ),
        );

        return $eventId;
    }
}
