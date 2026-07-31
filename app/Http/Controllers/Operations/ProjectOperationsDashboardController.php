<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use App\Application\Operations\GetProjectOperationsReadModel;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the accessible project operations dashboard.
 */
final class ProjectOperationsDashboardController extends Controller
{
    /**
     * Display the authoritative project operations read model without 3D.
     */
    public function __invoke(
        Organization $organization,
        Project $project,
        GetProjectOperationsReadModel $operations,
    ): Response {
        return Inertia::render('projects/operations/index', [
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
                'terminal' => $project->status->isTerminal(),
            ],
            'projectUrl' => route(
                'organizations.projects.show',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ),
            'approvalInboxUrl' => route(
                'organizations.projects.approvals.index',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ),
            'operations' => $operations->handle(
                organizationId: $organization->id,
                projectId: $project->id,
            ),
        ]);
    }
}
