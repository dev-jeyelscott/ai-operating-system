<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use App\Application\Operations\GetProjectOfficeProjection;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

/**
 * Returns the authenticated project's persisted office projection.
 */
final class ProjectOfficeProjectionController extends Controller
{
    /**
     * Return one tenant-scoped, read-only office projection response.
     */
    public function __invoke(
        Organization $organization,
        Project $project,
        GetProjectOfficeProjection $projection,
    ): JsonResponse {
        return response()->json(
            $projection->handle(
                organizationId: $organization->id,
                projectId: $project->id,
            ),
        );
    }
}
