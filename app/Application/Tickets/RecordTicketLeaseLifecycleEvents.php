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
use App\Models\TicketExecutionLease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class RecordTicketLeaseLifecycleEvents
{
    public function __construct(
        private DomainEventOutbox $outbox,
        private RecordAuditEvent $audit,
    ) {}

    public function record(
        Project $project,
        Execution $execution,
        TicketExecutionLease $lease,
        AuditEventType $eventType,
        CarbonImmutable $occurredAt,
    ): void {
        $payload = [
            'lease_id' => $lease->id,
            'execution_id' => $execution->id,
            'roadmap_task_id' => $lease->roadmap_task_id,
            'owner' => $lease->owner,
            'heartbeat_at' => $lease->heartbeat_at->toISOString(),
            'expires_at' => $lease->expires_at->toISOString(),
            'released_at' => $lease->released_at?->toISOString(),
            'release_reason' => $lease->release_reason?->value,
        ];
        $eventId = (string) Str::ulid();

        $this->outbox->append(new DomainEventEnvelope(
            eventId: $eventId,
            eventName: $eventType->value,
            aggregateType: AuditSubjectType::TicketExecutionLease->value,
            aggregateId: $lease->id,
            organizationId: $project->organization_id,
            projectId: $project->id,
            actor: DomainEventActor::system($lease->owner),
            provider: null,
            occurredAt: $occurredAt,
            correlationId: $execution->correlation_id,
            causationId: null,
            executionId: $execution->id,
            schemaVersion: 1,
            payload: $payload,
        ));

        $this->audit->record(
            organizationId: $project->organization_id,
            projectId: $project->id,
            actorType: AuditActorType::System,
            actorId: $lease->owner,
            eventType: $eventType,
            subjectType: AuditSubjectType::TicketExecutionLease,
            subjectId: $lease->id,
            correlationId: $execution->correlation_id,
            metadata: $payload,
            executionId: $execution->id,
            deduplicationKey: sprintf(
                'ticket:lease:%s:%s:%s',
                $eventType->value,
                $lease->id,
                $occurredAt->format('U.u'),
            ),
        );
    }
}
