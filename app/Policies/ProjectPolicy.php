<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\OrganizationRole;
use App\Domain\Projects\ProjectPermission;
use App\Domain\Projects\ProjectPermissionMatrix;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Authorizes operations against organization-owned projects.
 */
final readonly class ProjectPolicy
{
    /**
     * Inject the domain permission evaluator.
     */
    public function __construct(
        private ProjectPermissionMatrix $permissions,
    ) {}

    /**
     * Allow every organization member to view a project.
     */
    public function view(User $user, Project $project): Response
    {
        return $this->authorizePermission(
            user: $user,
            project: $project,
            permission: ProjectPermission::View,
        );
    }

    /**
     * Allow owners, administrators, and members to update project metadata.
     */
    public function update(User $user, Project $project): Response
    {
        return $this->authorizePermission(
            user: $user,
            project: $project,
            permission: ProjectPermission::Update,
        );
    }

    /**
     * Allow only owners and administrators to make approval decisions.
     */
    public function approve(User $user, Project $project): Response
    {
        return $this->authorizePermission(
            user: $user,
            project: $project,
            permission: ProjectPermission::Approve,
        );
    }

    /**
     * Allow only owners and administrators to manage integration credentials.
     */
    public function manageIntegrations(
        User $user,
        Project $project,
    ): Response {
        return $this->authorizePermission(
            user: $user,
            project: $project,
            permission: ProjectPermission::ManageIntegrations,
        );
    }

    /**
     * Allow owners and administrators to archive projects.
     */
    public function archive(User $user, Project $project): Response
    {
        return $this->authorizePermission(
            user: $user,
            project: $project,
            permission: ProjectPermission::Archive,
        );
    }

    /**
     * Allow owners and administrators to restore archived projects.
     */
    public function restore(User $user, Project $project): Response
    {
        return $this->authorizePermission(
            user: $user,
            project: $project,
            permission: ProjectPermission::Restore,
        );
    }

    /**
     * Resolve organization membership and evaluate the requested permission.
     *
     * Non-members receive a 404 response so project existence is not disclosed.
     */
    private function authorizePermission(
        User $user,
        Project $project,
        ProjectPermission $permission,
    ): Response {
        $role = $this->roleFor(
            user: $user,
            project: $project,
        );

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return $this->permissions->allows($role, $permission)
            ? Response::allow()
            : Response::deny(
                'Your organization role does not permit this project operation.',
            );
    }

    /**
     * Resolve the user's role inside the project's owning organization.
     */
    private function roleFor(
        User $user,
        Project $project,
    ): ?OrganizationRole {
        return OrganizationMembership::query()
            ->where('organization_id', $project->organization_id)
            ->where('user_id', $user->id)
            ->first()
            ?->role;
    }
}
