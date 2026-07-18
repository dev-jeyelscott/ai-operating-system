<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Application\Projects\RestoreProject;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

/**
 * Handles the explicit project restore command.
 */
final class RestoreProjectController
{
    /**
     * Restore the authorized project and return to its detail page.
     */
    public function __invoke(
        Organization $organization,
        Project $project,
        RestoreProject $restoreProject,
    ): RedirectResponse {
        $project = $restoreProject->handle(
            organizationId: $organization->id,
            projectId: $project->id,
        );

        return to_route('organizations.projects.show', [
            'organization' => $organization,
            'project' => $project,
        ])->with('status', 'project-restored');
    }
}
