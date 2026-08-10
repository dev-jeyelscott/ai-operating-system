<?php

declare(strict_types=1);

namespace App\Application\Operations\Consumers;

use App\Application\Events\Contracts\DomainEventConsumer;
use App\Application\Events\Contracts\RealTimeEventStream;
use App\Application\Events\Data\RealTimeStreamMessage;
use App\Application\Events\Data\StoredDomainEvent;
use App\Application\Operations\BuildOfficeProjection;
use App\Domain\Audit\AuditEventType;

/**
 * Refreshes durable office state and publishes a sanitized live invalidation.
 *
 * The real-time stream is not workflow truth. Clients receiving this event
 * reload the persisted projection instead of applying provider payloads.
 */
final readonly class RefreshOfficeProjection implements DomainEventConsumer
{
    /**
     * Inject the authoritative projection builder and transport abstraction.
     */
    public function __construct(
        private BuildOfficeProjection $builder,
        private RealTimeEventStream $stream,
    ) {}

    /**
     * Return the stable durable-consumer identity.
     */
    public function consumerName(): string
    {
        return 'operations.office_projection.v1';
    }

    /**
     * Return workflow events capable of changing office presentation.
     *
     * High-volume output chunks are deliberately excluded.
     *
     * @return list<string>
     */
    public function subscribedEventNames(): array
    {
        return [
            AuditEventType::ProjectCreated->value,
            AuditEventType::ProjectUpdated->value,
            AuditEventType::ProjectArchived->value,
            AuditEventType::ProjectRestored->value,
            AuditEventType::ProjectStartRequested->value,

            AuditEventType::RoadmapGenerated->value,
            AuditEventType::RoadmapEdited->value,
            AuditEventType::RoadmapApproved->value,
            AuditEventType::RoadmapRejected->value,
            AuditEventType::RoadmapRegenerationRequested->value,

            AuditEventType::TicketSelected->value,
            AuditEventType::TicketLeaseAcquired->value,
            AuditEventType::TicketLeaseHeartbeat->value,
            AuditEventType::TicketLeaseReleased->value,
            AuditEventType::TicketStatusTransitioned->value,
            AuditEventType::TicketExternalStateReconciled->value,

            AuditEventType::ImplementationStarted->value,
            AuditEventType::ValidationStarted->value,
            AuditEventType::ValidationCompleted->value,
            AuditEventType::ValidationFailed->value,
            AuditEventType::SyntheticCommitCreated->value,
            AuditEventType::SyntheticPushRecorded->value,
            AuditEventType::PullRequestCreated->value,
            AuditEventType::ImplementationCompleted->value,

            AuditEventType::QaStarted->value,
            AuditEventType::MergeAssessmentCompleted->value,
            AuditEventType::SimulatedMergeApproved->value,
            AuditEventType::SimulatedMergeChangesRequested->value,
            AuditEventType::SimulatedMergeEscalated->value,
            AuditEventType::SimulatedMergeDeferred->value,

            AuditEventType::WorkflowTransitioned->value,

            AuditEventType::ApprovalRequested->value,
            AuditEventType::ApprovalGranted->value,
            AuditEventType::ApprovalRejected->value,
            AuditEventType::ApprovalExpired->value,

            AuditEventType::ExecutionAttemptStarted->value,
            AuditEventType::ExecutionAttemptCompleted->value,
            AuditEventType::ExecutionAttemptFailed->value,
            AuditEventType::ExecutionAttemptTimedOut->value,
            AuditEventType::ExecutionRetryScheduled->value,
            AuditEventType::ExecutionRetryReleased->value,
            AuditEventType::ExecutionCancellationRequested->value,
            AuditEventType::ExecutionCancelled->value,
            AuditEventType::ExecutionFailed->value,
            AuditEventType::ExecutionBlocked->value,

            AuditEventType::ProviderSessionStarted->value,
            AuditEventType::ProviderThreadStarted->value,
            AuditEventType::ProviderTurnStarted->value,
            AuditEventType::ProviderItemStarted->value,
            AuditEventType::ProviderItemCompleted->value,
            AuditEventType::ProviderApprovalRequested->value,
            AuditEventType::ProviderApprovalResolved->value,
            AuditEventType::ProviderCommandRequested->value,
            AuditEventType::ProviderCommandCompleted->value,
            AuditEventType::ProviderTurnCompleted->value,
            AuditEventType::ProviderTurnFailed->value,
            AuditEventType::ProviderSessionCancelled->value,
        ];
    }

    /**
     * Rebuild the durable projection then signal authorized clients to reload it.
     */
    public function handle(
        StoredDomainEvent $event,
    ): void {
        if ($event->projectId === null) {
            return;
        }

        $projection = $this->builder->handle(
            organizationId: $event->organizationId,
            projectId: $event->projectId,
        );

        $correlationId = $this->envelopeString(
            event: $event,
            key: 'correlation_id',
        ) ?? $event->eventId;

        $this->stream->publish(
            new RealTimeStreamMessage(
                eventId: $event->eventId,
                eventName: 'office.projection_updated',
                organizationId: $event->organizationId,
                projectId: $event->projectId,
                occurredAt: $projection->projected_at,
                correlationId: $correlationId,
                executionId: $this->envelopeString(
                    event: $event,
                    key: 'execution_id',
                ),
                schemaVersion: 1,
                data: [
                    'source_event_name' => $event->eventName,
                    'projection_sequence' => $projection
                        ->last_event_sequence,
                    'projection_fingerprint' => $projection
                        ->fingerprint,
                    'projected_at' => $projection
                        ->projected_at
                        ->toIso8601String(),
                ],
            ),
        );
    }

    /**
     * Read one optional scalar identifier from the canonical event envelope.
     */
    private function envelopeString(
        StoredDomainEvent $event,
        string $key,
    ): ?string {
        $value = $event->envelope[$key] ?? null;

        return is_string($value) && trim($value) !== ''
            ? $value
            : null;
    }
}
