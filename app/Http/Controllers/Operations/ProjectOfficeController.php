<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use App\Application\Operations\GetProjectOfficeProjection;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the tenant-scoped interactive office page.
 */
final class ProjectOfficeController extends Controller
{
    /**
     * Display the lazy-loaded 3D office shell backed by persisted projection state.
     */
    public function __invoke(
        Organization $organization,
        Project $project,
        GetProjectOfficeProjection $projection,
    ): Response {
        return Inertia::render('projects/operations/office', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => $project->status->value,
            ],
            'projectUrl' => route(
                'organizations.projects.show',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ),
            'operationsUrl' => route(
                'organizations.projects.operations.index',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ),
            'officeProjectionEndpointUrl' => route(
                'organizations.projects.operations.office-projection.show',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ),
            'officeProjection' => $projection->handle(
                organizationId: $organization->id,
                projectId: $project->id,
            ),
        ]);
    }
}
