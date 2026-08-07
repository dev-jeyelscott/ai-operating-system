<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Defines stable, versioned names for authoritative application events.
 */
enum AuditEventType: string
{
    case OrganizationCreated = 'organization.created';
    case OrganizationMemberAdded = 'organization.member.added';
    case OrganizationMemberRemoved = 'organization.member.removed';
    case ProjectCreated = 'project.created';
    case ProjectUpdated = 'project.updated';
    case ProjectArchived = 'project.archived';
    case ProjectRestored = 'project.restored';
    case ProjectSetupUpdated = 'project.setup.updated';
    case ProjectConfigurationVersionCreated = 'project.configuration.version.created';
    case ProjectProviderPolicyUpdated = 'project.provider_policy.updated';
    case IntegrationCredentialStored = 'integration.credential.stored';
    case IntegrationCredentialRotated = 'integration.credential.rotated';
    case NotionConnectionTestSucceeded = 'integration.notion.connection_test.succeeded';
    case NotionConnectionTestFailed = 'integration.notion.connection_test.failed';
    case CodexConnectionTestSucceeded = 'integration.codex.connection_test.succeeded';
    case CodexConnectionTestFailed = 'integration.codex.connection_test.failed';
    case NotionTicketPublished = 'integration.notion.ticket.published';
    case NotionTicketPublicationFailed = 'integration.notion.ticket.publication_failed';
    case NotionTicketReconciled = 'integration.notion.ticket.reconciled';
    case NotionConflictAccepted = 'integration.notion.conflict.accepted';
    case NotionConflictRetainInternalRequested = 'integration.notion.conflict.retain_internal_requested';
    case NotionConflictRetainInternalCompleted = 'integration.notion.conflict.retain_internal_completed';
    case NotionConflictDeferred = 'integration.notion.conflict.deferred';
    case DocumentUploaded = 'document.uploaded';
    case DocumentReplacementUploaded = 'document.replacement.uploaded';
    case DocumentScanStarted = 'document.scan.started';
    case DocumentScanCompleted = 'document.scan.completed';
    case DocumentScanFailed = 'document.scan.failed';
    case DocumentParseStarted = 'document.parse.started';
    case DocumentParseCompleted = 'document.parse.completed';
    case DocumentParseFailed = 'document.parse.failed';
    case DocumentAnalysisStarted = 'document.analysis.started';
    case DocumentAnalysisCompleted = 'document.analysis.completed';
    case DocumentAnalysisFailed = 'document.analysis.failed';
    case DocumentVersionApproved = 'document.version.approved';
    case DocumentVersionRejected = 'document.version.rejected';
    case DocumentVersionSuperseded = 'document.version.superseded';
    case DocumentProcessingRetryRequested = 'document.processing.retry_requested';
    case ProjectContextSnapshotCreated = 'project.context_snapshot.created';
    case ProjectStartRequested = 'project.start_requested';
    case RoadmapGenerated = 'roadmap.generated';
    case RoadmapEdited = 'roadmap.edited';
    case RoadmapApproved = 'roadmap.approved';
    case RoadmapRejected = 'roadmap.rejected';
    case RoadmapRegenerationRequested = 'roadmap.regeneration_requested';
    case TicketSelected = 'ticket.selected';
    case TicketLeaseAcquired = 'ticket.lease_acquired';
    case TicketLeaseHeartbeat = 'ticket.lease_heartbeat';
    case TicketLeaseReleased = 'ticket.lease_released';
    case TicketStatusTransitioned = 'ticket.status_transitioned';
    case TicketExternalStateReconciled = 'ticket.state_reconciled';
    case ImplementationStarted = 'implementation.started';
    case ValidationStarted = 'validation.started';
    case ValidationCompleted = 'validation.completed';
    case ValidationFailed = 'validation.failed';
    case SyntheticCommitCreated = 'synthetic.commit_created';
    case SyntheticPushRecorded = 'synthetic.push_recorded';
    case PullRequestCreated = 'pull_request.created';
    case ImplementationCompleted = 'implementation.completed';
    case QaStarted = 'qa.started';
    case MergeAssessmentCompleted = 'merge_assessment.completed';
    case SimulatedMergeApproved = 'simulated_merge.approved';
    case SimulatedMergeChangesRequested = 'simulated_merge.changes_requested';
    case SimulatedMergeEscalated = 'simulated_merge.escalated';
    case SimulatedMergeDeferred = 'simulated_merge.deferred';
    case WorkflowTransitioned = 'workflow.transitioned';
    case ApprovalRequested = 'approval.requested';
    case ApprovalGranted = 'approval.granted';
    case ApprovalRejected = 'approval.rejected';
    case ApprovalExpired = 'approval.expired';
    case ExecutionAttemptStarted = 'execution.attempt.started';
    case ExecutionAttemptCompleted = 'execution.attempt.completed';
    case ExecutionAttemptFailed = 'execution.attempt.failed';
    case ExecutionAttemptTimedOut = 'execution.attempt.timed_out';
    case ExecutionRetryScheduled = 'execution.retry_scheduled';
    case ExecutionRetryReleased = 'execution.retry_released';
    case ExecutionCancellationRequested = 'execution.cancellation_requested';
    case ExecutionCancelled = 'execution.cancelled';
    case ExecutionFailed = 'execution.failed';
    case ExecutionBlocked = 'execution.blocked';
    case DeadLetterReplayRequested = 'dead_letter.replay_requested';
}
