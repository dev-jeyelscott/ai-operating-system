<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use App\Application\Operations\GetProjectOperationsReadModel;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

/**
 * Returns the authoritative project operations read model as JSON.
 */
final class ProjectOperationsReadModelController extends Controller
{
    /**
     * Return the tenant-scoped project operations contract.
     */
    public function __invoke(
        Organization $organization,
        Project $project,
        GetProjectOperationsReadModel $operations,
    ): JsonResponse {
        return response()->json(
            $operations->handle(
                organizationId: $organization->id,
                projectId: $project->id,
            ),
        );
    }
}
