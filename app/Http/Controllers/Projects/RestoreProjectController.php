<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Application\Projects\RestoreProject;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Handles the explicit project restore command.
 */
final class RestoreProjectController
{
    /**
     * Restore the authorized project and return to its detail page.
     */
    public function __invoke(
        Request $request,
        Organization $organization,
        Project $project,
        RestoreProject $restoreProject,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $correlationId = $request->attributes->get('request_id');

        $project = $restoreProject->handle(
            actorUserId: $user->id,
            organizationId: $organization->id,
            projectId: $project->id,
            correlationId: is_string($correlationId)
                ? $correlationId
                : null,
        );

        return to_route('organizations.projects.show', [
            'organization' => $organization,
            'project' => $project,
        ])->with('status', 'project-restored');
    }
}
