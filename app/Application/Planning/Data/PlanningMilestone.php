<?php

declare(strict_types=1);

namespace App\Application\Planning\Data;

final readonly class PlanningMilestone
{
    public function __construct(
        public string $stableId,
        public string $name,
        public string $phaseId,
    ) {}

    /** @return array{stable_id:string,name:string,phase_id:string} */
    public function toArray(): array
    {
        return ['stable_id' => $this->stableId, 'name' => $this->name, 'phase_id' => $this->phaseId];
    }
}
