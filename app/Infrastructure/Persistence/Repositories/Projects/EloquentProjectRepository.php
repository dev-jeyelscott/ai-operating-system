<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories\Projects;

use App\Application\Projects\Contracts\ProjectRepository;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Projects\ProjectType;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Persists projects through tenant-qualified Eloquent queries.
 */
final class EloquentProjectRepository implements ProjectRepository
{
    /**
     * Paginate current or archived projects for one organization.
     *
     * @return LengthAwarePaginator<int, Project>
     */
    public function paginateForOrganization(
        int $organizationId,
        bool $archived,
        int $perPage = 12,
    ): LengthAwarePaginator {
        if ($perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException(
                'The projects page size must be between 1 and 100.',
            );
        }

        $query = $this->queryForOrganization($organizationId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($archived) {
            $query->archived();
        } else {
            $query->unarchived();
        }

        return $query->paginate($perPage);
    }

    /**
     * Retrieve a project by its internal identifier within one organization.
     */
    public function findByIdOrFail(
        int $organizationId,
        int $projectId,
    ): Project {
        return $this->queryForOrganization($organizationId)
            ->whereKey($projectId)
            ->firstOrFail();
    }

    /**
     * Create a project and assign ownership through the organization relation.
     */
    public function create(
        int $organizationId,
        string $name,
        ?string $description,
        ProjectType $projectType,
    ): Project {
        return DB::transaction(
            function () use (
                $organizationId,
                $name,
                $description,
                $projectType,
            ): Project {
                $organization = Organization::query()->findOrFail(
                    $organizationId,
                );

                $project = new Project([
                    'name' => $name,
                    'slug' => $this->generateUniqueSlug($name),
                    'description' => $description,
                    'project_type' => $projectType,
                ]);

                /*
                 * Saving through the relationship assigns organization_id
                 * without making tenant ownership mass assignable.
                 */
                $organization->projects()->save($project);

                /*
                 * Every application-created project starts with a safe,
                 * intentionally incomplete schema-v1 configuration.
                 *
                 * This runs inside the existing project transaction, so project
                 * creation cannot succeed without configuration initialization.
                 */
                $project->configuration()->create();

                return $project
                    ->refresh()
                    ->load('configuration');
            },
            attempts: 3,
        );
    }

    /**
     * Update a project under a tenant-qualified row lock.
     */
    public function update(
        int $organizationId,
        int $projectId,
        string $name,
        ?string $description,
        ProjectType $projectType,
    ): Project {
        return DB::transaction(
            function () use (
                $organizationId,
                $projectId,
                $name,
                $description,
                $projectType,
            ): Project {
                $project = $this->lockedProject(
                    organizationId: $organizationId,
                    projectId: $projectId,
                );

                $project->fill([
                    'name' => $name,
                    'description' => $description,
                    'project_type' => $projectType,
                ])->save();

                /*
                 * Slug, organization ownership, workflow status, and archive
                 * state remain immutable through the metadata update path.
                 */
                return $project->refresh();
            },
            attempts: 3,
        );
    }

    /**
     * Archive a project after resolving it inside the tenant boundary.
     */
    public function archive(
        int $organizationId,
        int $projectId,
    ): Project {
        $project = $this->findByIdOrFail(
            organizationId: $organizationId,
            projectId: $projectId,
        );

        $project->archive();

        return $project;
    }

    /**
     * Restore a project after resolving it inside the tenant boundary.
     */
    public function restore(
        int $organizationId,
        int $projectId,
    ): Project {
        $project = $this->findByIdOrFail(
            organizationId: $organizationId,
            projectId: $projectId,
        );

        $project->restore();

        return $project;
    }

    /**
     * Apply a guarded workflow transition inside the tenant boundary.
     */
    public function transitionTo(
        int $organizationId,
        int $projectId,
        ProjectStatus $target,
    ): Project {
        $project = $this->findByIdOrFail(
            organizationId: $organizationId,
            projectId: $projectId,
        );

        $project->transitionTo($target);

        return $project;
    }

    /**
     * Start every project query with its mandatory organization predicate.
     *
     * @return Builder<Project>
     */
    private function queryForOrganization(int $organizationId): Builder
    {
        return Project::query()->forOrganization($organizationId);
    }

    /**
     * Retrieve a tenant-owned project under a row lock.
     */
    private function lockedProject(
        int $organizationId,
        int $projectId,
    ): Project {
        return $this->queryForOrganization($organizationId)
            ->whereKey($projectId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Generate a stable globally unique project slug.
     */
    private function generateUniqueSlug(string $name): string
    {
        $prefix = Str::slug($name);

        if ($prefix === '') {
            $prefix = 'project';
        }

        return sprintf(
            '%s-%s',
            Str::limit($prefix, 150, ''),
            strtolower((string) Str::ulid()),
        );
    }
}
