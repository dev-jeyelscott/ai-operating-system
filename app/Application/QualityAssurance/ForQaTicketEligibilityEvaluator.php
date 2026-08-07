<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance;

use App\Application\QualityAssurance\Data\ForQaArtifactFact;
use App\Application\QualityAssurance\Data\ForQaTicketEligibilityContext;
use App\Application\QualityAssurance\Data\ForQaTicketEligibilityResult;
use App\Domain\Executions\ExecutionAttemptStatus;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\QualityAssurance\ForQaTicketIneligibilityReason;
use App\Domain\Tickets\TicketStatus;

/**
 * Applies deterministic Layer 3 entry policy to one For QA ticket snapshot.
 *
 * The evaluator performs no queries, mutations, provider calls, or execution
 * creation. AIOS-106 remains responsible for transactional selection and QA
 * orchestration after this policy returns an eligible result.
 */
final class ForQaTicketEligibilityEvaluator
{
    /**
     * Capabilities that represent valid Layer 2 implementation executions.
     *
     * @var list<string>
     */
    private const array IMPLEMENTATION_CAPABILITIES = [
        'development',
        'development.simulation',
    ];

    /**
     * Artifacts produced by a complete MVP Layer 2 development execution.
     *
     * @var list<string>
     */
    private const array REQUIRED_ARTIFACT_TYPES = [
        'implementation_plan',
        'changed_file_manifest',
        'validation_result',
        'synthetic_branch',
        'synthetic_commit',
        'synthetic_push',
        'synthetic_pull_request',
    ];

    /**
     * Evaluate every For QA entry gate and return all relevant failures.
     */
    public function evaluate(
        ForQaTicketEligibilityContext $context,
    ): ForQaTicketEligibilityResult {
        $reasons = [];

        if ($context->ticketStatus !== TicketStatus::ForQa) {
            $reasons[] =
                ForQaTicketIneligibilityReason::StatusNotForQa;
        }

        $this->evaluateExecution(
            context: $context,
            reasons: $reasons,
        );

        $this->evaluateAttempt(
            context: $context,
            reasons: $reasons,
        );

        if (! $context->completionLeaseRecorded) {
            $reasons[] =
                ForQaTicketIneligibilityReason::CompletionLeaseMissing;
        }

        $artifactsByType = $this->artifactsByType(
            $context->artifacts,
        );

        $missingArtifactTypes = $this->missingArtifactTypes(
            $artifactsByType,
        );

        if ($missingArtifactTypes !== []) {
            $reasons[] =
                ForQaTicketIneligibilityReason::RequiredArtifactMissing;
        }

        [
            $artifactLineageMismatch,
            $artifactTypesWithoutEvidence,
        ] = $this->evaluateArtifactLineageAndEvidence(
            context: $context,
            artifactsByType: $artifactsByType,
        );

        if ($artifactLineageMismatch) {
            $reasons[] =
                ForQaTicketIneligibilityReason::ArtifactLineageMismatch;
        }

        if ($artifactTypesWithoutEvidence !== []) {
            $reasons[] =
                ForQaTicketIneligibilityReason::ArtifactEvidenceMissing;
        }

        $validationArtifact = $this->matchingArtifact(
            context: $context,
            artifactsByType: $artifactsByType,
            type: 'validation_result',
        );

        if (
            $validationArtifact !== null
            && ! $this->validationPassed($validationArtifact)
        ) {
            $reasons[] =
                ForQaTicketIneligibilityReason::ValidationNotPassed;
        }

        $pullRequestArtifact = $this->matchingArtifact(
            context: $context,
            artifactsByType: $artifactsByType,
            type: 'synthetic_pull_request',
        );

        if (
            $pullRequestArtifact !== null
            && ! $this->pullRequestTargetsDevelop(
                $pullRequestArtifact,
            )
        ) {
            $reasons[] =
                ForQaTicketIneligibilityReason::PullRequestTargetInvalid;
        }

        return new ForQaTicketEligibilityResult(
            reasons: $reasons,
            missingArtifactTypes: $missingArtifactTypes,
            artifactTypesWithoutEvidence: $artifactTypesWithoutEvidence,
        );
    }

    /**
     * Evaluate implementation execution lifecycle and lineage.
     *
     * @param  list<ForQaTicketIneligibilityReason>  $reasons
     */
    private function evaluateExecution(
        ForQaTicketEligibilityContext $context,
        array &$reasons,
    ): void {
        if ($context->implementationExecutionId === null) {
            $reasons[] =
                ForQaTicketIneligibilityReason::ImplementationExecutionMissing;

            return;
        }

        if (
            $context->implementationExecutionStatus
            !== ExecutionStatus::Completed
        ) {
            $reasons[] =
                ForQaTicketIneligibilityReason::ImplementationExecutionNotCompleted;
        }

        if (! in_array(
            $context->implementationCapability,
            self::IMPLEMENTATION_CAPABILITIES,
            true,
        )) {
            $reasons[] =
                ForQaTicketIneligibilityReason::ImplementationCapabilityInvalid;
        }

        if (
            $context->implementationProjectId
            !== $context->ticketProjectId
        ) {
            $reasons[] =
                ForQaTicketIneligibilityReason::ProjectLineageMismatch;
        }

        if (
            $context->implementationContextSnapshotId === null
            || $context->implementationContextSnapshotId
                !== $context->roadmapContextSnapshotId
        ) {
            $reasons[] =
                ForQaTicketIneligibilityReason::ContextSnapshotMismatch;
        }
    }

    /**
     * Evaluate the terminal provider attempt used to produce the artifacts.
     *
     * @param  list<ForQaTicketIneligibilityReason>  $reasons
     */
    private function evaluateAttempt(
        ForQaTicketEligibilityContext $context,
        array &$reasons,
    ): void {
        if ($context->implementationAttemptId === null) {
            $reasons[] =
                ForQaTicketIneligibilityReason::ImplementationAttemptMissing;

            return;
        }

        if (
            $context->implementationAttemptStatus
            !== ExecutionAttemptStatus::Completed
        ) {
            $reasons[] =
                ForQaTicketIneligibilityReason::ImplementationAttemptNotCompleted;
        }
    }

    /**
     * Group artifacts by their stable artifact type.
     *
     * @param  list<ForQaArtifactFact>  $artifacts
     * @return array<string, list<ForQaArtifactFact>>
     */
    private function artifactsByType(array $artifacts): array
    {
        $grouped = [];

        foreach ($artifacts as $artifact) {
            $grouped[$artifact->type] ??= [];
            $grouped[$artifact->type][] = $artifact;
        }

        return $grouped;
    }

    /**
     * Return required artifact types not present in the supplied snapshot.
     *
     * @param  array<string, list<ForQaArtifactFact>>  $artifactsByType
     * @return list<string>
     */
    private function missingArtifactTypes(
        array $artifactsByType,
    ): array {
        $missing = [];

        foreach (self::REQUIRED_ARTIFACT_TYPES as $type) {
            if (! isset($artifactsByType[$type])) {
                $missing[] = $type;
            }
        }

        return $missing;
    }

    /**
     * Check that required artifacts belong to the completed attempt and have
     * at least one immutable evidence record.
     *
     * @param  array<string, list<ForQaArtifactFact>>  $artifactsByType
     * @return array{0:bool,1:list<string>}
     */
    private function evaluateArtifactLineageAndEvidence(
        ForQaTicketEligibilityContext $context,
        array $artifactsByType,
    ): array {
        $lineageMismatch = false;
        $withoutEvidence = [];

        foreach (self::REQUIRED_ARTIFACT_TYPES as $type) {
            if (! isset($artifactsByType[$type])) {
                continue;
            }

            $artifact = $this->matchingArtifact(
                context: $context,
                artifactsByType: $artifactsByType,
                type: $type,
            );

            if ($artifact === null) {
                $lineageMismatch = true;

                continue;
            }

            if (! $artifact->hasEvidence) {
                $withoutEvidence[] = $type;
            }
        }

        return [$lineageMismatch, $withoutEvidence];
    }

    /**
     * Find the required artifact tied to the exact execution and attempt.
     *
     * @param  array<string, list<ForQaArtifactFact>>  $artifactsByType
     */
    private function matchingArtifact(
        ForQaTicketEligibilityContext $context,
        array $artifactsByType,
        string $type,
    ): ?ForQaArtifactFact {
        if (
            $context->implementationExecutionId === null
            || $context->implementationAttemptId === null
        ) {
            return null;
        }

        foreach ($artifactsByType[$type] ?? [] as $artifact) {
            if (
                $artifact->executionId
                    === $context->implementationExecutionId
                && $artifact->executionAttemptId
                    === $context->implementationAttemptId
            ) {
                return $artifact;
            }
        }

        return null;
    }

    /**
     * Verify that the validation artifact contains only passed validations.
     */
    private function validationPassed(
        ForQaArtifactFact $artifact,
    ): bool {
        $validations = $artifact->metadata['validations'] ?? null;

        if (
            ! is_array($validations)
            || ! array_is_list($validations)
            || $validations === []
        ) {
            return false;
        }

        foreach ($validations as $validation) {
            if (
                ! is_array($validation)
                || ($validation['status'] ?? null) !== 'passed'
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verify that the synthetic pull request targets develop.
     */
    private function pullRequestTargetsDevelop(
        ForQaArtifactFact $artifact,
    ): bool {
        return ($artifact->metadata['target_branch'] ?? null)
            === 'develop';
    }
}
