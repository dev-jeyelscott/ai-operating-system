<?php

declare(strict_types=1);

namespace App\Application\Planning\Data;

use App\Domain\Projects\Configuration\ReasoningLevel;

/** Immutable, transport-neutral source context for Layer 1 planning. */
final readonly class PlanningExecutionRequest
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  list<PlanningSourceReference>  $documents
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $policies
     * @param  list<array<string, mixed>>  $existingRoadmaps
     * @param  array<string, mixed>  $providerPolicy
     * @param  array<string, mixed>  $reasoningPolicy
     * @param  array<string, mixed>  $budgetPolicy
     * @param  array<string, mixed>  $approvalPolicy
     * @param  array<string, mixed>  $organizationPolicy
     * @param  array<string, mixed>  $projectPolicy
     * @param  array<string, mixed>|null  $latestRoadmap
     */
    public function __construct(
        public int $projectId,
        public int $contextSnapshotId,
        public string $contextFingerprint,
        public ReasoningLevel $reasoningLevel,
        public array $documents,
        public string $scenario = 'happy_path',
        public int $seed = 1,
        public ?string $feedbackFingerprint = null,
        public ?int $configurationVersionId = null,
        public ?int $configurationRevision = null,
        public ?int $configurationSchemaVersion = null,
        public array $configuration = [],
        public array $policies = [],
        public array $existingRoadmaps = [],
        public array $providerPolicy = [],
        public array $reasoningPolicy = [],
        public array $budgetPolicy = [],
        public array $approvalPolicy = [],
        public array $organizationPolicy = [],
        public array $projectPolicy = [],
        public ?array $latestRoadmap = null,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'project_id' => $this->projectId,
            'context_snapshot_id' => $this->contextSnapshotId,
            'context_fingerprint' => $this->contextFingerprint,
            'reasoning_level' => $this->reasoningLevel->value,
            'documents' => array_map(
                static fn (PlanningSourceReference $document): array => $document->toArray(),
                $this->documents,
            ),
            'scenario' => $this->scenario,
            'seed' => $this->seed,
            'feedback_fingerprint' => $this->feedbackFingerprint,
            'configuration_version_id' => $this->configurationVersionId,
            'configuration_revision' => $this->configurationRevision,
            'configuration_schema_version' => $this->configurationSchemaVersion,
            'configuration' => $this->configuration,
            'policies' => $this->policies,
            'provider_policy' => $this->providerPolicy,
            'reasoning_policy' => $this->reasoningPolicy,
            'budget_policy' => $this->budgetPolicy,
            'approval_policy' => $this->approvalPolicy,
            'organization_policy' => $this->organizationPolicy,
            'project_policy' => $this->projectPolicy,
            'existing_roadmaps' => $this->existingRoadmaps,
            'latest_roadmap' => $this->latestRoadmap,
        ];
    }
}
