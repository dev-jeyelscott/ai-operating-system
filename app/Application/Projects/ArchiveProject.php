<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Application\Projects\Contracts\ProjectRepository;
use App\Models\Project;

/**
 * Archives a project without changing its workflow lifecycle state.
 */
final readonly class ArchiveProject
{
    /**
     * Inject the tenant-safe project persistence contract.
     */
    public function __construct(
        private ProjectRepository $projects,
    ) {}

    /**
     * Execute the idempotent aggregate archive operation.
     */
    public function handle(
        int $organizationId,
        int $projectId,
    ): Project {
        return $this->projects->archive(
            organizationId: $organizationId,
            projectId: $projectId,
        );
    }
}
