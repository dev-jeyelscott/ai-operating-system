<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Events\Contracts\DomainEventOutbox;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Events\DomainEventActor;
use App\Domain\Events\DomainEventEnvelope;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\QaAssessment;
use App\Models\RoadmapTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Records matching outbox and audit events for Layer 3 execution.
 */
final readonly class RecordQualityAssuranceLifecycleEvent
{
    public function __construct(
        private DomainEventOutbox $outbox,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * Record one authoritative QA lifecycle event.
     *
     * @param  array<string, mixed>  $additionalPayload
     */
    public function record(
        AuditEventType $eventType,
        QaAssessment $assessment,
        Execution $reviewExecution,
        ExecutionAttempt $reviewAttempt,
        Execution $implementationExecution,
        RoadmapTask $ticket,
        ?string $causationId = null,
        array $additionalPayload = [],
    ): string {
        $reviewExecution->loadMissing('project');

        $eventId = (string) Str::ulid();
        $occurredAt = CarbonImmutable::now();

        $payload = [
            'qa_assessment_id' => $assessment->id,
            'roadmap_id' => $ticket->roadmap_id,
            'roadmap_task_id' => $ticket->id,
            'ticket_id' => $ticket->stable_id,
            'implementation_execution_id' => $implementationExecution->id,
            'review_execution_id' => $reviewExecution->id,
            'review_attempt_id' => $reviewAttempt->id,
            'provider' => $reviewAttempt->execution_provider,
            'schema_version' => 1,
            ...$additionalPayload,
        ];

        $this->outbox->append(new DomainEventEnvelope(
            eventId: $eventId,
            eventName: $eventType->value,
            aggregateType: AuditSubjectType::Execution->value,
            aggregateId: $reviewExecution->id,
            organizationId: $reviewExecution->project->organization_id,
            projectId: $reviewExecution->project_id,
            actor: DomainEventActor::system(
                'quality-assurance-orchestrator',
            ),
            provider: $reviewAttempt->execution_provider,
            occurredAt: $occurredAt,
            correlationId: $reviewExecution->correlation_id,
            causationId: $causationId,
            executionId: $reviewExecution->id,
            schemaVersion: 1,
            payload: $payload,
        ));

        $this->audit->record(
            organizationId: $reviewExecution->project->organization_id,
            projectId: $reviewExecution->project_id,
            actorType: AuditActorType::System,
            actorId: 'quality-assurance-orchestrator',
            eventType: $eventType,
            subjectType: AuditSubjectType::Execution,
            subjectId: $reviewExecution->id,
            correlationId: $reviewExecution->correlation_id,
            metadata: $payload,
            causationId: $causationId,
            executionId: $reviewExecution->id,
            deduplicationKey: sprintf(
                'quality-assurance:%s:%s:%d',
                $eventType->value,
                $reviewExecution->id,
                $reviewAttempt->id,
            ),
        );

        return $eventId;
    }
}
