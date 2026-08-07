<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use App\Application\Operations\GetProjectOfficeProjection;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

/**
 * Returns the persisted office projection as JSON.
 */
final class ProjectOfficeProjectionController extends Controller
{
    /**
     * Return the tenant-scoped office projection contract.
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
