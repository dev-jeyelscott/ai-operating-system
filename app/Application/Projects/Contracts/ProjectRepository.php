<?php

declare(strict_types=1);

namespace App\Application\Projects\Contracts;

use App\Application\Projects\Data\ProjectMutationResult;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Projects\ProjectType;
use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Defines organization-scoped persistence operations for projects.
 *
 * Every method requires an explicit organization identifier so callers cannot
 * accidentally read or mutate a project outside the active tenant boundary.
 */
interface ProjectRepository
{
    /**
     * Paginate projects owned by one organization.
     *
     * @return LengthAwarePaginator<int, Project>
     */
    public function paginateForOrganization(
        int $organizationId,
        bool $archived,
        int $perPage = 12,
    ): LengthAwarePaginator;

    /**
     * Retrieve one project only when it belongs to the supplied organization.
     */
    public function findByIdOrFail(
        int $organizationId,
        int $projectId,
    ): Project;

    /**
     * Create a project owned by the supplied organization.
     */
    public function create(
        int $organizationId,
        string $name,
        ?string $description,
        ProjectType $projectType,
    ): Project;

    /**
     * Update mutable project metadata and report whether persistence changed.
     */
    public function update(
        int $organizationId,
        int $projectId,
        string $name,
        ?string $description,
        ProjectType $projectType,
    ): ProjectMutationResult;

    /**
     * Archive a project and report whether its archive state changed.
     */
    public function archive(
        int $organizationId,
        int $projectId,
    ): ProjectMutationResult;

    /**
     * Restore a project and report whether its archive state changed.
     */
    public function restore(
        int $organizationId,
        int $projectId,
    ): ProjectMutationResult;

    /**
     * Apply a guarded workflow transition inside the supplied organization.
     */
    public function transitionTo(
        int $organizationId,
        int $projectId,
        ProjectStatus $target,
    ): Project;
}
