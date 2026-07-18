<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Models\Project;

/**
 * Restores an archived project without changing its workflow lifecycle state.
 */
final readonly class RestoreProject
{
    /**
     * Execute the idempotent aggregate restore operation.
     */
    public function handle(Project $project): Project
    {
        $project->restore();

        return $project;
    }
}
