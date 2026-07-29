<?php

declare(strict_types=1);

namespace App\Application\Development;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Events\Contracts\DomainEventOutbox;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Events\DomainEventActor;
use App\Domain\Events\DomainEventEnvelope;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\RoadmapTask;
use App\Models\TicketExecutionLease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class RecordDevelopmentLifecycleEvent
{
    public function __construct(private DomainEventOutbox $outbox, private RecordAuditEvent $audit) {}

    public function record(
        AuditEventType $eventType,
        Execution $execution,
        ExecutionAttempt $attempt,
        RoadmapTask $ticket,
        TicketExecutionLease $lease,
        ?string $causationId = null,
    ): string {
        $execution->loadMissing('project');
        $eventId = (string) Str::ulid();
        $occurredAt = CarbonImmutable::now();
        $payload = [
            'roadmap_id' => $ticket->roadmap_id, 'roadmap_task_id' => $ticket->id, 'ticket_id' => $ticket->stable_id,
            'execution_id' => $execution->id, 'attempt_id' => $attempt->id, 'lease_id' => $lease->id,
            'provider' => $attempt->execution_provider, 'schema_version' => 1,
        ];
        $this->outbox->append(new DomainEventEnvelope(
            eventId: $eventId, eventName: $eventType->value, aggregateType: AuditSubjectType::Execution->value,
            aggregateId: $execution->id, organizationId: $execution->project->organization_id, projectId: $execution->project_id,
            actor: DomainEventActor::system('development-orchestrator'), provider: $attempt->execution_provider,
            occurredAt: $occurredAt, correlationId: $execution->correlation_id, causationId: $causationId,
            executionId: $execution->id, schemaVersion: 1, payload: $payload,
        ));
        $this->audit->record(
            organizationId: $execution->project->organization_id, projectId: $execution->project_id,
            actorType: AuditActorType::System, actorId: 'development-orchestrator', eventType: $eventType,
            subjectType: AuditSubjectType::Execution, subjectId: $execution->id, correlationId: $execution->correlation_id,
            metadata: $payload, causationId: $causationId, executionId: $execution->id,
            deduplicationKey: "development:{$eventType->value}:{$execution->id}:{$attempt->id}",
        );

        return $eventId;
    }
}
