<?php

declare(strict_types=1);

namespace App\Application\Planning\Data;

final readonly class PlanningRoadmapDefinition
{
    /**
     * @param  list<PlanningPhase>  $phases
     * @param  list<PlanningMilestone>  $milestones
     * @param  list<PlanningTask>  $tasks
     * @param  list<PlanningDependency>  $dependencies
     */
    public function __construct(
        public array $phases,
        public array $milestones,
        public array $tasks,
        public array $dependencies,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'phases' => array_map(static fn (PlanningPhase $phase): array => $phase->toArray(), $this->phases),
            'milestones' => array_map(static fn (PlanningMilestone $milestone): array => $milestone->toArray(), $this->milestones),
            'tasks' => array_map(static fn (PlanningTask $task): array => $task->toArray(), $this->tasks),
            'dependencies' => array_map(static fn (PlanningDependency $dependency): array => $dependency->toArray(), $this->dependencies),
        ];
    }
}
