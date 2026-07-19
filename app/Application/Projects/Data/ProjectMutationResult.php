<?php

declare(strict_types=1);

namespace App\Application\Projects\Data;

use App\Models\Project;

/**
 * Reports the persisted project and whether a mutation changed database state.
 */
final readonly class ProjectMutationResult
{
    /**
     * Create an immutable result for a project persistence operation.
     */
    public function __construct(
        public Project $project,
        public bool $changed,
    ) {}
}
