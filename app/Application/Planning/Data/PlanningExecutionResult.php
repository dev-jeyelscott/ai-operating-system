<?php

declare(strict_types=1);

namespace App\Application\Planning\Data;

/** Canonical normalized provider response, safe to persist after validation. */
final readonly class PlanningExecutionResult
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  list<PlanningDocument>  $documentInventory
     * @param  list<string>  $architectureConcerns
     * @param  list<string>  $securityConcerns
     * @param  list<string>  $scope
     * @param  list<string>  $assumptions
     * @param  list<string>  $constraints
     * @param  list<string>  $definitionOfDone
     * @param  list<string>  $requiredApprovals
     * @param  list<string>  $gaps
     * @param  list<string>  $conflicts
     * @param  list<string>  $risks
     * @param  list<PlanningDiagnostic>  $diagnostics
     */
    public function __construct(
        public int $schemaVersion,
        public string $outcome,
        public array $documentInventory,
        public string $documentSummary,
        public array $architectureConcerns,
        public array $securityConcerns,
        public string $goal,
        public array $scope,
        public array $assumptions,
        public array $constraints,
        public array $definitionOfDone,
        public array $requiredApprovals,
        public PlanningRoadmapDefinition $roadmap,
        public array $gaps = [],
        public array $conflicts = [],
        public array $risks = [],
        public bool $humanDecisionRequired = false,
        public array $diagnostics = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'outcome' => $this->outcome,
            'document_inventory' => array_map(static fn (PlanningDocument $document): array => $document->toArray(), $this->documentInventory),
            'document_summary' => $this->documentSummary,
            'architecture_concerns' => $this->architectureConcerns,
            'security_concerns' => $this->securityConcerns,
            'goal' => $this->goal,
            'scope' => $this->scope,
            'assumptions' => $this->assumptions,
            'constraints' => $this->constraints,
            'definition_of_done' => $this->definitionOfDone,
            'required_approvals' => $this->requiredApprovals,
            'roadmap' => $this->roadmap->toArray(),
            'gaps' => $this->gaps,
            'conflicts' => $this->conflicts,
            'risks' => $this->risks,
            'human_decision_required' => $this->humanDecisionRequired,
            'diagnostics' => array_map(static fn (PlanningDiagnostic $diagnostic): array => $diagnostic->toArray(), $this->diagnostics),
        ];
    }
}
