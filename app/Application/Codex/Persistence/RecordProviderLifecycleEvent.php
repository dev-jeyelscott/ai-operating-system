<?php

declare(strict_types=1);

namespace App\Application\Codex\Persistence;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Events\Contracts\DomainEventOutbox;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Events\DomainEventActor;
use App\Domain\Events\DomainEventEnvelope;
use App\Models\ProviderSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Records provider lifecycle state through the existing transactional outbox
 * and append-only audit boundaries.
 */
final readonly class RecordProviderLifecycleEvent
{
    /**
     * Inject existing event infrastructure.
     */
    public function __construct(
        private DomainEventOutbox $outbox,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * Append one provider lifecycle event.
     *
     * The caller must invoke this while the corresponding provider persistence
     * mutation is inside the same database transaction.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(
        ProviderSession $session,
        AuditEventType $eventType,
        array $payload = [],
        ?string $causationId = null,
        ?string $deduplicationKey = null,
    ): string {
        $eventId = (string) Str::ulid();
        $occurredAt = CarbonImmutable::now();

        $metadata = [
            'provider_session_id' => $session->id,
            'execution_attempt_id' => $session->execution_attempt_id,
            ...$payload,
        ];

        $this->outbox->append(
            new DomainEventEnvelope(
                eventId: $eventId,
                eventName: $eventType->value,
                aggregateType: AuditSubjectType::Execution->value,
                aggregateId: $session->execution_id,
                organizationId: $session->organization_id,
                projectId: $session->project_id,
                actor: DomainEventActor::system(
                    'codex-provider-gateway',
                ),
                provider: $session->provider,
                occurredAt: $occurredAt,
                correlationId: $session->execution->correlation_id,
                causationId: $causationId,
                executionId: $session->execution_id,
                schemaVersion: 1,
                payload: $metadata,
            ),
        );

        $this->audit->record(
            organizationId: $session->organization_id,
            projectId: $session->project_id,
            actorType: AuditActorType::System,
            actorId: 'codex-provider-gateway',
            eventType: $eventType,
            subjectType: AuditSubjectType::Execution,
            subjectId: $session->execution_id,
            correlationId: $session->execution->correlation_id,
            metadata: $metadata,
            causationId: $causationId,
            executionId: $session->execution_id,
            schemaVersion: 1,
            deduplicationKey: $deduplicationKey,
        );

        return $eventId;
    }
}
