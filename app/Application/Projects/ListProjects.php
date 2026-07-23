<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Application\Projects\Contracts\ProjectRepository;
use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Lists projects through the tenant-safe persistence boundary.
 */
final readonly class ListProjects
{
    /**
     * Inject the project persistence contract.
     */
    public function __construct(
        private ProjectRepository $projects,
    ) {}

    /**
     * Return current or archived projects for one organization.
     *
     * @return LengthAwarePaginator<int, Project>
     */
    public function handle(
        int $organizationId,
        bool $archived,
        int $perPage = 12,
    ): LengthAwarePaginator {
        return $this->projects->paginateForOrganization(
            organizationId: $organizationId,
            archived: $archived,
            perPage: $perPage,
        );
    }
}
