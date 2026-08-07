<?php

declare(strict_types=1);

namespace App\Domain\QualityAssurance;

/**
 * Defines stable reasons that prevent a ticket from entering Layer 3 QA.
 *
 * These values can be reused by Layer 3 orchestration, audit metadata,
 * operational diagnostics, API responses, and future QA queue projections.
 */
enum ForQaTicketIneligibilityReason: string
{
    case StatusNotForQa = 'status_not_for_qa';

    case ImplementationExecutionMissing =
        'implementation_execution_missing';

    case ImplementationExecutionNotCompleted =
        'implementation_execution_not_completed';

    case ImplementationCapabilityInvalid =
        'implementation_capability_invalid';

    case ImplementationAttemptMissing =
        'implementation_attempt_missing';

    case ImplementationAttemptNotCompleted =
        'implementation_attempt_not_completed';

    case ProjectLineageMismatch =
        'project_lineage_mismatch';

    case ContextSnapshotMismatch =
        'context_snapshot_mismatch';

    case CompletionLeaseMissing =
        'completion_lease_missing';

    case RequiredArtifactMissing =
        'required_artifact_missing';

    case ArtifactLineageMismatch =
        'artifact_lineage_mismatch';

    case ArtifactEvidenceMissing =
        'artifact_evidence_missing';

    case ValidationNotPassed =
        'validation_not_passed';

    case PullRequestTargetInvalid =
        'pull_request_target_invalid';
}
