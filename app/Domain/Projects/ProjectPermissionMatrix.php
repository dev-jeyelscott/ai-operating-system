<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Identity\OrganizationRole;

/**
 * Resolves project permissions from an organization membership role.
 *
 * This class contains no HTTP, database, or framework-specific behavior so the
 * permission rules can be tested independently of Laravel policies.
 */
final class ProjectPermissionMatrix
{
    /**
     * Determine whether the role grants the requested project permission.
     */
    public function allows(
        OrganizationRole $role,
        ProjectPermission $permission,
    ): bool {
        return match ($permission) {
            ProjectPermission::View => true,

            ProjectPermission::Create,
            ProjectPermission::Update => in_array(
                $role,
                [
                    OrganizationRole::Owner,
                    OrganizationRole::Administrator,
                    OrganizationRole::Member,
                ],
                true,
            ),

            ProjectPermission::Approve,
            ProjectPermission::Start,
            ProjectPermission::ManageIntegrations,
            ProjectPermission::Archive,
            ProjectPermission::Restore => in_array(
                $role,
                [
                    OrganizationRole::Owner,
                    OrganizationRole::Administrator,
                ],
                true,
            ),
        };
    }
}
