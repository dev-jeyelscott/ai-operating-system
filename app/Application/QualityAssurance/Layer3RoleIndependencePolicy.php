<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance;

use App\Application\QualityAssurance\Data\Layer3RoleIndependenceDecision;
use App\Domain\Executions\ExecutionCapability;
use App\Domain\QualityAssurance\Layer3RoleIndependenceFailureReason;
use App\Models\Execution;

/**
 * Enforces deterministic separation between Layer 2 and Layer 3 executions.
 */
final class Layer3RoleIndependencePolicy
{
    /**
     * Capabilities that represent Layer 2 implementation work.
     *
     * @var list<string>
     */
    private const array IMPLEMENTATION_CAPABILITIES = [
        'development',
        'development.execute',
    ];

    /**
     * Capabilities that represent Layer 3 review work.
     *
     * @var list<string>
     */
    private const array REVIEW_CAPABILITIES = [
        'quality_assurance',
        'quality_assurance.review',
    ];

    /**
     * Determine whether the proposed Layer 3 execution is independent.
     */
    public function evaluate(
        Execution $implementationExecution,
        Execution $reviewExecution,
    ): Layer3RoleIndependenceDecision {
        if (
            ! $this->hasPersistedIdentity($implementationExecution)
            || ! $this->hasPersistedIdentity($reviewExecution)
        ) {
            return Layer3RoleIndependenceDecision::rejected(
                Layer3RoleIndependenceFailureReason::MissingExecutionIdentity,
            );
        }

        /*
         * This is the primary AIOS-104 invariant. A replay, reused model,
         * or incorrectly supplied Layer 2 execution must not review itself.
         */
        if (
            $implementationExecution->getKey()
            === $reviewExecution->getKey()
        ) {
            return Layer3RoleIndependenceDecision::rejected(
                Layer3RoleIndependenceFailureReason::SameLogicalExecution,
            );
        }

        if (
            ! ExecutionCapability::DevelopmentExecute
                ->accepts(
                    $implementationExecution->capability,
                )
        ) {
            return Layer3RoleIndependenceDecision::rejected(
                Layer3RoleIndependenceFailureReason::InvalidImplementationCapability,
            );
        }

        if (
            ! ExecutionCapability::QualityAssuranceReview
                ->accepts(
                    $reviewExecution->capability,
                )
        ) {
            return Layer3RoleIndependenceDecision::rejected(
                Layer3RoleIndependenceFailureReason::InvalidReviewCapability,
            );
        }

        /*
         * A reviewer from another project cannot provide a valid review,
         * even when both executions coincidentally use matching ticket data.
         */
        if (
            $implementationExecution->project_id
            !== $reviewExecution->project_id
        ) {
            return Layer3RoleIndependenceDecision::rejected(
                Layer3RoleIndependenceFailureReason::CrossProjectLineage,
            );
        }

        /*
         * Both executions must operate against an approved immutable context.
         */
        if (
            $implementationExecution->project_context_snapshot_id === null
            || $reviewExecution->project_context_snapshot_id === null
        ) {
            return Layer3RoleIndependenceDecision::rejected(
                Layer3RoleIndependenceFailureReason::MissingContextSnapshot,
            );
        }

        /*
         * Reviewing against another context version could approve work using
         * requirements that were not authoritative during implementation.
         */
        if (
            $implementationExecution->project_context_snapshot_id
            !== $reviewExecution->project_context_snapshot_id
        ) {
            return Layer3RoleIndependenceDecision::rejected(
                Layer3RoleIndependenceFailureReason::ContextSnapshotMismatch,
            );
        }

        /*
         * Role attribution is required for auditability. This policy does not
         * require different provider vendors or a multi-reviewer quorum.
         */
        if (
            ! $this->hasLogicalRole($implementationExecution)
            || ! $this->hasLogicalRole($reviewExecution)
        ) {
            return Layer3RoleIndependenceDecision::rejected(
                Layer3RoleIndependenceFailureReason::MissingLogicalRole,
            );
        }

        return Layer3RoleIndependenceDecision::allowed();
    }

    /**
     * Determine whether the execution has a durable logical identity.
     */
    private function hasPersistedIdentity(Execution $execution): bool
    {
        $key = $execution->getKey();

        return is_string($key) && trim($key) !== '';
    }

    /**
     * Determine whether an auditable logical role was assigned.
     */
    private function hasLogicalRole(Execution $execution): bool
    {
        return $execution->logical_role !== null
            && trim($execution->logical_role) !== '';
    }
}
