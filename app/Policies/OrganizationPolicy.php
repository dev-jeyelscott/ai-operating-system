<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\OrganizationRole;
use App\Domain\Projects\ProjectPermission;
use App\Domain\Projects\ProjectPermissionMatrix;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Authorizes organization-scoped operations using explicit memberships.
 */
final readonly class OrganizationPolicy
{
    /**
     * Inject the project permission evaluator used by project-related abilities.
     */
    public function __construct(
        private ProjectPermissionMatrix $projectPermissions,
    ) {}

    /**
     * Allow authenticated users to create their own organization.
     */
    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    /**
     * Allow users to list organizations to which they belong.
     */
    public function viewAny(User $user): bool
    {
        return $user->organizationMemberships()->exists();
    }

    /**
     * Allow organization members to view the organization.
     *
     * Non-members receive a not-found response to avoid exposing whether an
     * organization identifier exists.
     */
    public function view(User $user, Organization $organization): Response
    {
        return $this->roleFor($user, $organization) !== null
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Allow owners and administrators to update organization metadata.
     */
    public function update(User $user, Organization $organization): Response
    {
        return $this->requireRole(
            user: $user,
            organization: $organization,
            allowedRoles: [
                OrganizationRole::Owner,
                OrganizationRole::Administrator,
            ],
        );
    }

    /**
     * Allow only owners to delete an organization.
     */
    public function delete(User $user, Organization $organization): Response
    {
        return $this->requireRole(
            user: $user,
            organization: $organization,
            allowedRoles: [OrganizationRole::Owner],
        );
    }

    /**
     * Allow owners and administrators to manage organization members.
     */
    public function manageMembers(
        User $user,
        Organization $organization,
    ): Response {
        return $this->requireRole(
            user: $user,
            organization: $organization,
            allowedRoles: [
                OrganizationRole::Owner,
                OrganizationRole::Administrator,
            ],
        );
    }

    /**
     * Allow only an existing owner to transfer organization ownership.
     */
    public function transferOwnership(
        User $user,
        Organization $organization,
    ): Response {
        return $this->requireRole(
            user: $user,
            organization: $organization,
            allowedRoles: [OrganizationRole::Owner],
        );
    }

    /**
     * Determine whether the user may create projects in the organization.
     */
    public function createProject(
        User $user,
        Organization $organization,
    ): Response {
        $role = $this->roleFor($user, $organization);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return $this->projectPermissions->allows(
            $role,
            ProjectPermission::Create,
        )
            ? Response::allow()
            : Response::deny('You cannot create projects in this organization.');
    }

    /**
     * Require one of the specified organization roles.
     *
     * @param  list<OrganizationRole>  $allowedRoles
     */
    private function requireRole(
        User $user,
        Organization $organization,
        array $allowedRoles,
    ): Response {
        $role = $this->roleFor($user, $organization);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return in_array($role, $allowedRoles, true)
            ? Response::allow()
            : Response::deny(
                'Your organization role does not permit this operation.',
            );
    }

    /**
     * Resolve the user's role inside the specified organization.
     */
    private function roleFor(
        User $user,
        Organization $organization,
    ): ?OrganizationRole {
        return OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->first()
            ?->role;
    }
}
