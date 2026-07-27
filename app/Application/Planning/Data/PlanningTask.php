<?php

declare(strict_types=1);

namespace App\Application\Planning\Data;

use App\Domain\Projects\Configuration\ReasoningLevel;

final readonly class PlanningTask
{
    /**
     * @param  array<string, mixed>  $scope
     * @param  list<PlanningAcceptanceCriterion>  $acceptanceCriteria
     * @param  list<PlanningSourceReference>  $sourceReferences
     * @param  list<string>  $evidenceRequirements
     */
    public function __construct(
        public string $stableId,
        public string $title,
        public string $objective,
        public string $phaseId,
        public string $milestoneId,
        public string $ticketType,
        public array $scope,
        public array $acceptanceCriteria,
        public array $sourceReferences,
        public array $evidenceRequirements,
        public string $priority,
        public string $risk,
        public ReasoningLevel $reasoningLevel,
        public string $logicalAgent,
        public int $estimatedComplexity,
        public bool $humanApprovalRequired,
        public string $reasoning,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'stable_id' => $this->stableId,
            'title' => $this->title,
            'objective' => $this->objective,
            'phase_id' => $this->phaseId,
            'milestone_id' => $this->milestoneId,
            'ticket_type' => $this->ticketType,
            'scope' => $this->scope,
            'acceptance_criteria' => array_map(
                static fn (PlanningAcceptanceCriterion $criterion): array => $criterion->toArray(),
                $this->acceptanceCriteria,
            ),
            'source_references' => array_map(
                static fn (PlanningSourceReference $reference): array => $reference->toArray(),
                $this->sourceReferences,
            ),
            'evidence_requirements' => $this->evidenceRequirements,
            'priority' => $this->priority,
            'risk' => $this->risk,
            'reasoning_level' => $this->reasoningLevel->value,
            'logical_agent' => $this->logicalAgent,
            'estimated_complexity' => $this->estimatedComplexity,
            'human_approval_required' => $this->humanApprovalRequired,
            'reasoning' => $this->reasoning,
        ];
    }
}
