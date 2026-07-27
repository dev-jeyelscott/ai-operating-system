<?php

declare(strict_types=1);

namespace App\Application\Executions;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Events\Contracts\DomainEventOutbox;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Events\DomainEventActor;
use App\Domain\Events\DomainEventEnvelope;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Records execution domain and audit events inside the caller's transaction.
 */
final readonly class RecordExecutionLifecycleEvent
{
    /**
     * Inject transactional event and audit persistence.
     */
    public function __construct(
        private DomainEventOutbox $outbox,
        private RecordAuditEvent $auditEvents,
    ) {}

    /**
     * Append matching domain and audit records for one lifecycle transition.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(
        Execution $execution,
        string $eventName,
        AuditEventType $auditEventType,
        CarbonImmutable $occurredAt,
        array $payload = [],
        ?ExecutionAttempt $attempt = null,
        ?User $actor = null,
        ?string $causationId = null,
    ): string {
        $execution->loadMissing('project');

        $eventId = (string) Str::ulid();

        $domainActor = $actor === null
            ? DomainEventActor::system('execution-manager')
            : DomainEventActor::user($actor->id);

        $auditActorType = $actor === null
            ? AuditActorType::System
            : AuditActorType::User;

        $auditActorId = $actor === null
            ? 'execution-manager'
            : (string) $actor->id;

        $basePayload = [
            'execution_id' => $execution->id,
            'workflow_instance_id' => $execution->workflow_instance_id,
            'capability' => $execution->capability,
            'logical_role' => $execution->logical_role,
            'status' => $execution->status->value,
            'attempt_count' => $execution->attempt_count,
            'attempt_id' => $attempt?->id,
            'attempt_number' => $attempt?->attempt_number,
            'attempt_status' => $attempt?->status->value,
        ];

        $eventPayload = array_merge(
            $basePayload,
            $payload,
        );

        $this->outbox->append(new DomainEventEnvelope(
            eventId: $eventId,
            eventName: $eventName,
            aggregateType: 'execution',
            aggregateId: $execution->id,
            organizationId: $execution->project->organization_id,
            projectId: $execution->project_id,
            actor: $domainActor,
            provider: $attempt?->execution_provider,
            occurredAt: $occurredAt,
            correlationId: $execution->correlation_id,
            causationId: $causationId,
            executionId: $execution->id,
            schemaVersion: 1,
            payload: $eventPayload,
        ));

        $this->auditEvents->record(
            organizationId: $execution->project->organization_id,
            projectId: $execution->project_id,
            actorType: $auditActorType,
            actorId: $auditActorId,
            eventType: $auditEventType,
            subjectType: AuditSubjectType::Execution,
            subjectId: $execution->id,
            correlationId: $execution->correlation_id,
            metadata: $eventPayload,
            causationId: $causationId,
            executionId: $execution->id,
            schemaVersion: 1,
            deduplicationKey: sprintf(
                'execution:%s:%s:%d',
                $execution->id,
                $auditEventType->value,
                $attempt->attempt_number
                    ?? $execution->attempt_count,
            ),
        );

        return $eventId;
    }
}
