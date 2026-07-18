<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Application\Projects\ArchiveProject;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

/**
 * Handles the explicit project archive command.
 */
final class ArchiveProjectController
{
    /**
     * Archive the authorized project and return to its detail page.
     */
    public function __invoke(
        Organization $organization,
        Project $project,
        ArchiveProject $archiveProject,
    ): RedirectResponse {
        $project = $archiveProject->handle(
            organizationId: $organization->id,
            projectId: $project->id,
        );

        return to_route('organizations.projects.show', [
            'organization' => $organization,
            'project' => $project,
        ])->with('status', 'project-archived');
    }
}
