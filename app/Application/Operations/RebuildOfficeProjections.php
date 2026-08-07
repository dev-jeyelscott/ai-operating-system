<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Rebuilds office projections from durable project state in bounded chunks.
 */
final readonly class RebuildOfficeProjections
{
    /**
     * Inject the single-project projection builder.
     */
    public function __construct(
        private BuildOfficeProjection $builder,
    ) {}

    /**
     * Rebuild matching project projections without loading all projects at once.
     *
     * @return array{
     *     processed: int,
     *     failed: int,
     *     failures: list<array{
     *         organization_id: int,
     *         project_id: int,
     *         exception: class-string<Throwable>
     *     }>
     * }
     */
    public function handle(
        ?int $organizationId = null,
        ?int $projectId = null,
        int $chunkSize = 100,
    ): array {
        if ($chunkSize < 1 || $chunkSize > 500) {
            throw new InvalidArgumentException(
                'The office projection rebuild chunk size must be between 1 and 500.',
            );
        }

        $query = Project::query()
            ->select(['id', 'organization_id'])
            ->orderBy('id');

        $this->applyFilters(
            query: $query,
            organizationId: $organizationId,
            projectId: $projectId,
        );

        $processed = 0;
        $failed = 0;
        $failures = [];

        $query->chunkById(
            $chunkSize,
            function (EloquentCollection $projects) use (
                &$processed,
                &$failed,
                &$failures,
            ): void {
                /** @var EloquentCollection<int, Project> $projects */
                foreach ($projects as $project) {
                    try {
                        $this->builder->handle(
                            organizationId: $project->organization_id,
                            projectId: $project->id,
                            rebuilt: true,
                        );

                        $processed++;
                    } catch (Throwable $exception) {
                        $failed++;
                        $failures[] = [
                            'organization_id' => $project->organization_id,
                            'project_id' => $project->id,
                            'exception' => $exception::class,
                        ];

                        Log::error(
                            'Office projection rebuild failed.',
                            [
                                'organization_id' => $project->organization_id,
                                'project_id' => $project->id,
                                'exception_class' => $exception::class,
                            ],
                        );
                    }
                }
            },
            column: 'id',
        );

        return [
            'processed' => $processed,
            'failed' => $failed,
            'failures' => $failures,
        ];
    }

    /**
     * Apply optional tenant and project filters to the rebuild query.
     *
     * @param  Builder<Project>  $query
     */
    private function applyFilters(
        Builder $query,
        ?int $organizationId,
        ?int $projectId,
    ): void {
        if ($organizationId !== null) {
            $query->forOrganization($organizationId);
        }

        if ($projectId !== null) {
            $query->whereKey($projectId);
        }
    }
}
