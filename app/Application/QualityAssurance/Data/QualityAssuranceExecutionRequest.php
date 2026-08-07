<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance\Data;

/**
 * Contains the immutable, provider-safe input for one Layer 3 execution.
 */
final readonly class QualityAssuranceExecutionRequest
{
    /**
     * @param  list<string>  $includedScope
     * @param  list<string>  $excludedScope
     * @param  list<mixed>  $acceptanceCriteria
     * @param  list<string>  $requiredEvidence
     * @param  list<array<string, mixed>>  $implementationArtifacts
     * @param  list<string>  $evidenceIds
     * @param  array<string, mixed>  $providerPolicy
     */
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $roadmapId,
        public int $roadmapTaskId,
        public string $ticketId,
        public string $reviewExecutionId,
        public int $reviewAttemptId,
        public string $implementationExecutionId,
        public int $implementationAttemptId,
        public int $contextSnapshotId,
        public string $contextFingerprint,
        public string $ticketObjective,
        public array $includedScope,
        public array $excludedScope,
        public array $acceptanceCriteria,
        public array $requiredEvidence,
        public string $ticketRisk,
        public string $targetBranch,
        public string $implementationLogicalRole,
        public string $reviewLogicalRole,
        public array $implementationArtifacts,
        public array $evidenceIds,
        public string $requestedReasoning,
        public string $effectiveReasoning,
        public string $reasoningResolutionSource,
        public array $providerPolicy,
        public string $simulationScenario,
        public int $deterministicSeed,
    ) {}
}
