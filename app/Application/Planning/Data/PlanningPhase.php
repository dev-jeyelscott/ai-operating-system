<?php

declare(strict_types=1);

namespace App\Application\Planning\Data;

final readonly class PlanningPhase
{
    public function __construct(public string $stableId, public string $name) {}

    /** @return array{stable_id:string,name:string} */
    public function toArray(): array
    {
        return ['stable_id' => $this->stableId, 'name' => $this->name];
    }
}
