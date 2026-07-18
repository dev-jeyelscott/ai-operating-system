<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Application\Projects\Contracts\ProjectRepository;
use App\Models\Project;

/**
 * Restores an archived project without changing its workflow lifecycle state.
 */
final readonly class RestoreProject
{
    /**
     * Inject the tenant-safe project persistence contract.
     */
    public function __construct(
        private ProjectRepository $projects,
    ) {}

    /**
     * Execute the idempotent aggregate restore operation.
     */
    public function handle(
        int $organizationId,
        int $projectId,
    ): Project {
        return $this->projects->restore(
            organizationId: $organizationId,
            projectId: $projectId,
        );
    }
}
