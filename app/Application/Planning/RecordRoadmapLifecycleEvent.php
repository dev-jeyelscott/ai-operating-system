<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Events\Contracts\DomainEventOutbox;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Events\DomainEventActor;
use App\Domain\Events\DomainEventEnvelope;
use App\Models\Execution;
use App\Models\Roadmap;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class RecordRoadmapLifecycleEvent
{
    public function __construct(
        private DomainEventOutbox $outbox,
        private RecordAuditEvent $auditEvents,
    ) {}

    public function generated(Roadmap $roadmap, string $correlationId): string
    {
        return $this->append(
            roadmap: $roadmap,
            eventName: 'roadmap.generated',
            auditEventType: AuditEventType::RoadmapGenerated,
            actor: null,
            correlationId: $correlationId,
            payload: ['provider_id' => $roadmap->provider_id],
        );
    }

    public function edited(Roadmap $roadmap, User $actor, string $correlationId): string
    {
        return $this->append(
            roadmap: $roadmap,
            eventName: 'roadmap.edited',
            auditEventType: AuditEventType::RoadmapEdited,
            actor: $actor,
            correlationId: $correlationId,
            payload: [],
        );
    }

    public function approved(Roadmap $roadmap, ?User $actor, string $correlationId, string $authority): string
    {
        return $this->append(
            roadmap: $roadmap,
            eventName: 'roadmap.approved',
            auditEventType: AuditEventType::RoadmapApproved,
            actor: $actor,
            correlationId: $correlationId,
            payload: ['authority' => $authority],
        );
    }

    public function rejected(Roadmap $roadmap, User $actor, string $correlationId): string
    {
        return $this->append(
            roadmap: $roadmap,
            eventName: 'roadmap.rejected',
            auditEventType: AuditEventType::RoadmapRejected,
            actor: $actor,
            correlationId: $correlationId,
            payload: ['feedback_present' => $roadmap->regeneration_feedback !== null],
        );
    }

    public function regenerationRequested(Roadmap $roadmap, Execution $execution, User $actor, string $correlationId): string
    {
        return $this->append(
            roadmap: $roadmap,
            eventName: 'roadmap.regeneration_requested',
            auditEventType: AuditEventType::RoadmapRegenerationRequested,
            actor: $actor,
            correlationId: $correlationId,
            payload: [
                'project_id' => $execution->project_id,
                'execution_id' => $execution->id,
                'project_context_snapshot_id' => $execution->project_context_snapshot_id,
                'capability' => $execution->capability,
                'feedback_fingerprint' => $roadmap->feedback_fingerprint,
            ],
            executionId: $execution->id,
        );
    }

    /** @param array<string, mixed> $payload */
    private function append(
        Roadmap $roadmap,
        string $eventName,
        AuditEventType $auditEventType,
        ?User $actor,
        string $correlationId,
        array $payload,
        ?string $executionId = null,
    ): string {
        $roadmap->loadMissing('project');
        $eventId = (string) Str::ulid();
        $executionId ??= $roadmap->planning_execution_id;
        $basePayload = [
            'roadmap_id' => $roadmap->id,
            'revision' => $roadmap->revision,
            'content_version' => $roadmap->content_version,
            'candidate_fingerprint' => $roadmap->candidate_fingerprint,
            'output_fingerprint' => $roadmap->output_fingerprint,
            'status' => $roadmap->status,
        ];
        $metadata = array_merge($basePayload, $payload);
        $occurredAt = CarbonImmutable::now();

        $this->outbox->append(new DomainEventEnvelope(
            eventId: $eventId,
            eventName: $eventName,
            aggregateType: 'roadmap',
            aggregateId: (string) $roadmap->id,
            organizationId: $roadmap->project->organization_id,
            projectId: $roadmap->project_id,
            actor: $actor === null ? DomainEventActor::system('planning-engine') : DomainEventActor::user($actor->id),
            provider: null,
            occurredAt: $occurredAt,
            correlationId: $correlationId,
            causationId: null,
            executionId: $executionId,
            schemaVersion: 1,
            payload: $metadata,
        ));

        $this->auditEvents->record(
            organizationId: $roadmap->project->organization_id,
            projectId: $roadmap->project_id,
            actorType: $actor === null ? AuditActorType::System : AuditActorType::User,
            actorId: $actor === null ? 'planning-engine' : (string) $actor->id,
            eventType: $auditEventType,
            subjectType: AuditSubjectType::Roadmap,
            subjectId: (string) $roadmap->id,
            correlationId: $correlationId,
            metadata: $metadata,
            executionId: $executionId,
            deduplicationKey: sprintf('roadmap:%s:%d:%d:%d', $auditEventType->value, $roadmap->id, $roadmap->revision, $roadmap->content_version),
        );

        return $eventId;
    }
}
