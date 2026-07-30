<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance;

use App\Application\QualityAssurance\Data\ForQaArtifactFact;
use App\Application\QualityAssurance\Data\ForQaTicketEligibilityContext;
use App\Domain\Tickets\TicketLeaseReleaseReason;
use App\Models\Artifact;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\RoadmapTask;
use App\Models\TicketExecutionLease;

/**
 * Builds the authoritative, tenant-scoped facts consumed by AIOS-105.
 */
final class BuildForQaTicketEligibilityContext
{
    /**
     * Build one immutable eligibility snapshot from persisted Layer 2 records.
     */
    public function build(
        RoadmapTask $ticket,
        Execution $implementationExecution,
        ExecutionAttempt $implementationAttempt,
    ): ForQaTicketEligibilityContext {
        $ticket->loadMissing('roadmap');

        $artifacts = Artifact::query()
            ->forProject($implementationExecution->project_id)
            ->forExecution($implementationExecution->id)
            ->where(
                'execution_attempt_id',
                $implementationAttempt->id,
            )
            ->with('evidence')
            ->orderBy('artifact_type')
            ->orderBy('id')
            ->get();

        $artifactFacts = $artifacts
            ->map(static function (Artifact $artifact): ForQaArtifactFact {
                return new ForQaArtifactFact(
                    type: $artifact->artifact_type,
                    executionId: $artifact->execution_id,
                    executionAttemptId: $artifact->execution_attempt_id,
                    hasEvidence: $artifact->evidence->isNotEmpty(),
                    metadata: $artifact->metadata,
                );
            })
            ->values()
            ->all();

        $completionLeaseRecorded = TicketExecutionLease::query()
            ->where(
                'project_id',
                $implementationExecution->project_id,
            )
            ->where(
                'roadmap_task_id',
                $ticket->id,
            )
            ->where(
                'execution_id',
                $implementationExecution->id,
            )
            ->whereNotNull('released_at')
            ->where(
                'release_reason',
                TicketLeaseReleaseReason::Completion->value,
            )
            ->exists();

        return new ForQaTicketEligibilityContext(
            ticketStatus: $ticket->status,
            ticketProjectId: $implementationExecution->project_id,
            roadmapContextSnapshotId: $ticket->roadmap->project_context_snapshot_id,
            implementationExecutionId: $implementationExecution->id,
            implementationProjectId: $implementationExecution->project_id,
            implementationContextSnapshotId: $implementationExecution
                ->project_context_snapshot_id,
            implementationCapability: $implementationExecution->capability,
            implementationExecutionStatus: $implementationExecution->status,
            implementationAttemptId: $implementationAttempt->id,
            implementationAttemptStatus: $implementationAttempt->status,
            completionLeaseRecorded: $completionLeaseRecorded,
            artifacts: $artifactFacts,
        );
    }
}
