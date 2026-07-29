<?php

declare(strict_types=1);

namespace App\Application\Development\Data;

final readonly class DevelopmentExecutionRequest
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  list<string>  $includedScope
     * @param  list<string>  $excludedScope
     * @param  list<string>  $acceptanceCriteria
     * @param  list<string>  $dependencyReferences
     * @param  list<string>  $evidenceRequirements
     * @param  array<string, scalar|null>  $repositoryProviderMetadata
     * @param  list<string>  $validationCommands
     * @param  array<string, scalar|list<string>|null>  $providerPolicy
     * @param  array<string, scalar|null>  $budgetPolicy
     * @param  array<string, scalar|null>  $retryPolicy
     */
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $roadmapId,
        public string $ticketId,
        public string $executionId,
        public int $attemptId,
        public int $attemptNumber,
        public string $leaseId,
        public int $contextSnapshotId,
        public string $contextFingerprint,
        public string $ticketObjective,
        public array $includedScope,
        public array $excludedScope,
        public array $acceptanceCriteria,
        public array $dependencyReferences,
        public array $evidenceRequirements,
        public string $risk,
        public int $complexity,
        public array $repositoryProviderMetadata,
        public string $repositoryBaseReference,
        public string $integrationTarget,
        public array $validationCommands,
        public string $requestedReasoning,
        public string $effectiveReasoning,
        public string $reasoningResolutionSource,
        public array $providerPolicy,
        public array $budgetPolicy,
        public array $retryPolicy,
        public string $simulationScenario,
        public int $deterministicSeed,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion, 'organization_id' => $this->organizationId,
            'project_id' => $this->projectId, 'roadmap_id' => $this->roadmapId, 'ticket_id' => $this->ticketId,
            'execution_id' => $this->executionId, 'attempt_id' => $this->attemptId, 'attempt_number' => $this->attemptNumber,
            'lease_id' => $this->leaseId, 'context_snapshot_id' => $this->contextSnapshotId,
            'context_fingerprint' => $this->contextFingerprint, 'ticket_objective' => $this->ticketObjective,
            'included_scope' => $this->includedScope, 'excluded_scope' => $this->excludedScope,
            'acceptance_criteria' => $this->acceptanceCriteria, 'dependency_references' => $this->dependencyReferences,
            'evidence_requirements' => $this->evidenceRequirements, 'risk' => $this->risk, 'complexity' => $this->complexity,
            'repository_provider_metadata' => $this->repositoryProviderMetadata,
            'repository_base_reference' => $this->repositoryBaseReference, 'integration_target' => $this->integrationTarget,
            'validation_commands' => $this->validationCommands, 'requested_reasoning' => $this->requestedReasoning,
            'effective_reasoning' => $this->effectiveReasoning, 'reasoning_resolution_source' => $this->reasoningResolutionSource,
            'provider_policy' => $this->providerPolicy, 'budget_policy' => $this->budgetPolicy, 'retry_policy' => $this->retryPolicy,
            'simulation_scenario' => $this->simulationScenario, 'deterministic_seed' => $this->deterministicSeed,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['organization_id'] ?? 0), (int) ($data['project_id'] ?? 0), (int) ($data['roadmap_id'] ?? 0),
            (string) ($data['ticket_id'] ?? ''), (string) ($data['execution_id'] ?? ''), (int) ($data['attempt_id'] ?? 0),
            (int) ($data['attempt_number'] ?? 0), (string) ($data['lease_id'] ?? ''), (int) ($data['context_snapshot_id'] ?? 0),
            (string) ($data['context_fingerprint'] ?? ''), (string) ($data['ticket_objective'] ?? ''), self::list($data['included_scope'] ?? null),
            self::list($data['excluded_scope'] ?? null), self::list($data['acceptance_criteria'] ?? null), self::list($data['dependency_references'] ?? null),
            self::list($data['evidence_requirements'] ?? null), (string) ($data['risk'] ?? ''), (int) ($data['complexity'] ?? 0),
            self::map($data['repository_provider_metadata'] ?? null), (string) ($data['repository_base_reference'] ?? ''),
            (string) ($data['integration_target'] ?? ''), self::list($data['validation_commands'] ?? null),
            (string) ($data['requested_reasoning'] ?? ''), (string) ($data['effective_reasoning'] ?? ''),
            (string) ($data['reasoning_resolution_source'] ?? ''), self::map($data['provider_policy'] ?? null),
            self::map($data['budget_policy'] ?? null), self::map($data['retry_policy'] ?? null),
            (string) ($data['simulation_scenario'] ?? ''), (int) ($data['deterministic_seed'] ?? 0), (int) ($data['schema_version'] ?? 0),
        );
    }

    /** @return list<string> */
    private static function list(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \InvalidArgumentException('Development request list field is malformed.');
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new \InvalidArgumentException('Development request list item is malformed.');
            }
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('Development request policy field is malformed.');
        }

        foreach ($value as $item) {
            if (is_array($item)) {
                if (! array_is_list($item) || array_any($item, static fn (mixed $nested): bool => ! is_string($nested))) {
                    throw new \InvalidArgumentException('Development request policy item is malformed.');
                }
            } elseif (! is_scalar($item) && $item !== null) {
                throw new \InvalidArgumentException('Development request policy item is malformed.');
            }
        }

        return $value;
    }
}
