<?php

declare(strict_types=1);

namespace App\Application\Planning\Data;

final readonly class PlanningDependency
{
    public function __construct(public string $taskId, public string $dependsOnTaskId) {}

    /** @return array{task_id:string,depends_on_task_id:string} */
    public function toArray(): array
    {
        return ['task_id' => $this->taskId, 'depends_on_task_id' => $this->dependsOnTaskId];
    }
}
