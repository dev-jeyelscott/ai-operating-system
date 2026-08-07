<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use App\Application\Operations\GetProjectUsageSummary;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders project execution usage and cost information.
 */
final class ProjectUsageController extends Controller
{
    /**
     * Display project usage with simulation and provider costs separated.
     */
    public function __invoke(
        Organization $organization,
        Project $project,
        GetProjectUsageSummary $usage,
    ): Response {
        return Inertia::render(
            'projects/operations/usage',
            [
                'project' => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'slug' => $project->slug,
                ],
                'operationsUrl' => route(
                    'organizations.projects.operations.index',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
                'recoveryUrl' => route(
                    'organizations.projects.operations.recovery.index',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
                'usage' => $usage->handle(
                    organizationId: $organization->id,
                    projectId: $project->id,
                ),
            ],
        );
    }
}
