<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Defines stable, versioned names for authoritative application events.
 *
 * Event values are long-lived contracts. Rename an event only through an
 * explicit schema-versioned migration and compatibility plan.
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

    case ProjectConfigurationVersionCreated =
        'project.configuration.version.created';

    case IntegrationCredentialStored =
        'integration.credential.stored';

    case IntegrationCredentialRotated =
        'integration.credential.rotated';

    case NotionConnectionTestSucceeded =
        'integration.notion.connection_test.succeeded';

    case NotionConnectionTestFailed =
        'integration.notion.connection_test.failed';

    case DocumentUploaded = 'document.uploaded';
    case DocumentReplacementUploaded =
        'document.replacement.uploaded';

    case DocumentScanStarted = 'document.scan.started';
    case DocumentScanCompleted = 'document.scan.completed';
    case DocumentScanFailed = 'document.scan.failed';

    case DocumentParseStarted = 'document.parse.started';
    case DocumentParseCompleted = 'document.parse.completed';
    case DocumentParseFailed = 'document.parse.failed';

    case DocumentAnalysisStarted = 'document.analysis.started';
    case DocumentAnalysisCompleted =
        'document.analysis.completed';
    case DocumentAnalysisFailed = 'document.analysis.failed';

    case DocumentVersionApproved = 'document.version.approved';
    case DocumentVersionRejected = 'document.version.rejected';
    case DocumentVersionSuperseded =
        'document.version.superseded';

    case DocumentProcessingRetryRequested =
        'document.processing.retry_requested';

    case ProjectContextSnapshotCreated =
        'project.context_snapshot.created';

    case ProjectStartRequested = 'project.start_requested';

    case ApprovalRequested = 'approval.requested';
    case ApprovalGranted = 'approval.granted';
    case ApprovalRejected = 'approval.rejected';
    case ApprovalExpired = 'approval.expired';

    case ExecutionAttemptStarted =
        'execution.attempt.started';

    case ExecutionAttemptCompleted =
        'execution.attempt.completed';

    case ExecutionAttemptFailed =
        'execution.attempt.failed';

    case ExecutionAttemptTimedOut =
        'execution.attempt.timed_out';

    case ExecutionRetryScheduled =
        'execution.retry_scheduled';

    case ExecutionRetryReleased =
        'execution.retry_released';

    case ExecutionCancellationRequested =
        'execution.cancellation_requested';

    case ExecutionCancelled = 'execution.cancelled';
    case ExecutionFailed = 'execution.failed';

    case DeadLetterReplayRequested =
        'dead_letter.replay_requested';
}
