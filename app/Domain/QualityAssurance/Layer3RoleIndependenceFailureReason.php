<?php

declare(strict_types=1);

namespace App\Domain\QualityAssurance;

/**
 * Identifies why a proposed Layer 3 execution is not independent from Layer 2.
 */
enum Layer3RoleIndependenceFailureReason: string
{
    case MissingExecutionIdentity = 'missing_execution_identity';
    case SameLogicalExecution = 'same_logical_execution';
    case InvalidImplementationCapability = 'invalid_implementation_capability';
    case InvalidReviewCapability = 'invalid_review_capability';
    case CrossProjectLineage = 'cross_project_lineage';
    case MissingContextSnapshot = 'missing_context_snapshot';
    case ContextSnapshotMismatch = 'context_snapshot_mismatch';
    case MissingLogicalRole = 'missing_logical_role';
}
