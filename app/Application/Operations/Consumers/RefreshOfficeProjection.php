<?php

declare(strict_types=1);

namespace App\Application\Operations\Consumers;

use App\Application\Events\Contracts\DomainEventConsumer;
use App\Application\Events\Data\StoredDomainEvent;
use App\Application\Operations\BuildOfficeProjection;
use App\Domain\Audit\AuditEventType;

/**
 * Refreshes the office projection after project-scoped workflow events.
 */
final readonly class RefreshOfficeProjection implements DomainEventConsumer
{
    /**
     * Inject the authoritative office projection builder.
     */
    public function __construct(
        private BuildOfficeProjection $builder,
    ) {}

    /**
     * Return the stable identity used by durable consumer deduplication.
     */
    public function consumerName(): string
    {
        return 'operations.office_projection.v1';
    }

    /**
     * Return the project workflow events that can affect office state.
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
        ];
    }

    /**
     * Rebuild the projection for the event's explicitly scoped project.
     */
    public function handle(StoredDomainEvent $event): void
    {
        if ($event->projectId === null) {
            return;
        }

        $this->builder->handle(
            organizationId: $event->organizationId,
            projectId: $event->projectId,
        );
    }
}
