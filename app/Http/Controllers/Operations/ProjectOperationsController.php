<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use App\Application\Operations\GetProjectOperationsReadModel;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

/**
 * Returns the authenticated project operations read model.
 */
final class ProjectOperationsController extends Controller
{
    /**
     * Return one tenant-scoped, read-only operational snapshot.
     */
    public function __invoke(
        Organization $organization,
        Project $project,
        GetProjectOperationsReadModel $readModel,
    ): JsonResponse {
        return response()->json(
            $readModel->handle(
                organizationId: $organization->id,
                projectId: $project->id,
            ),
        );
    }
}
