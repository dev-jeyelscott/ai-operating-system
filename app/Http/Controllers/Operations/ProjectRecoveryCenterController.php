<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use App\Application\Operations\GetProjectRecoveryCenter;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the project blocker and recovery center.
 */
final class ProjectRecoveryCenterController extends Controller
{
    /**
     * Display tenant-scoped failures and recovery information.
     */
    public function __invoke(
        Request $request,
        Organization $organization,
        Project $project,
        GetProjectRecoveryCenter $recovery,
    ): Response {
        return Inertia::render(
            'projects/operations/recovery',
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
                'usageUrl' => route(
                    'organizations.projects.operations.usage.index',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
                'replayUrl' => route(
                    'organizations.projects.operations.recovery.replay',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
                'canReplay' => $request->user()?->can(
                    'approve',
                    $project,
                ) === true,
                'recovery' => $recovery->handle(
                    organizationId: $organization->id,
                    projectId: $project->id,
                ),
            ],
        );
    }
}
