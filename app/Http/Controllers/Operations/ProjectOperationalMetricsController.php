<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use App\Application\Operations\GetProjectOperationalMetrics;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders project-scoped queue, lease, workflow, and recovery metrics.
 */
final class ProjectOperationalMetricsController extends Controller
{
    /**
     * Display operational metrics derived from authoritative application state.
     */
    public function __invoke(
        Organization $organization,
        Project $project,
        GetProjectOperationalMetrics $metrics,
    ): Response {
        return Inertia::render(
            'projects/operations/metrics',
            [
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
                'dashboardUrl' => route(
                    'organizations.projects.operations.index',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
                'recoveryCenterUrl' => route(
                    'organizations.projects.operations.recovery.index',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
                'metrics' => $metrics->handle(
                    organizationId: $organization->id,
                    projectId: $project->id,
                ),
            ],
        );
    }
}
