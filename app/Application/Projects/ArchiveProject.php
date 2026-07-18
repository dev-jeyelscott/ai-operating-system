<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Models\Project;

/**
 * Archives a project without changing its workflow lifecycle state.
 */
final readonly class ArchiveProject
{
    /**
     * Execute the idempotent aggregate archive operation.
     */
    public function handle(Project $project): Project
    {
        $project->archive();

        return $project;
    }
}
