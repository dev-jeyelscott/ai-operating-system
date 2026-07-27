<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\Project;
use App\Models\User;

/**
 * Authorizes project viewers to receive project-level stream events.
 */
final readonly class ProjectEventStreamChannel
{
    /**
     * Resolve the tenant-scoped project and delegate access to ProjectPolicy.
     */
    public function join(
        User $user,
        int|string $organizationId,
        int|string $projectId,
    ): bool {
        $organizationId = (int) $organizationId;
        $projectId = (int) $projectId;

        if ($organizationId < 1 || $projectId < 1) {
            return false;
        }

        $project = Project::query()
            ->forOrganization($organizationId)
            ->whereKey($projectId)
            ->first();

        return $project !== null
            && $user->can('view', $project);
    }
}
