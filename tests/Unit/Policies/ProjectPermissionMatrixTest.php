<?php

declare(strict_types=1);

use App\Domain\Identity\OrganizationRole;
use App\Domain\Projects\ProjectPermission;
use App\Domain\Projects\ProjectPermissionMatrix;

/**
 * Return the complete expected project permission matrix.
 *
 * @return array<string, array{OrganizationRole, ProjectPermission, bool}>
 */
function projectPermissionMatrixCases(): array
{
    return [
        'owner may view projects' => [
            OrganizationRole::Owner,
            ProjectPermission::View,
            true,
        ],
        'owner may create projects' => [
            OrganizationRole::Owner,
            ProjectPermission::Create,
            true,
        ],
        'owner may update projects' => [
            OrganizationRole::Owner,
            ProjectPermission::Update,
            true,
        ],
        'owner may approve project operations' => [
            OrganizationRole::Owner,
            ProjectPermission::Approve,
            true,
        ],
        'owner may manage project integrations' => [
            OrganizationRole::Owner,
            ProjectPermission::ManageIntegrations,
            true,
        ],
        'owner may archive projects' => [
            OrganizationRole::Owner,
            ProjectPermission::Archive,
            true,
        ],
        'owner may restore projects' => [
            OrganizationRole::Owner,
            ProjectPermission::Restore,
            true,
        ],

        'administrator may view projects' => [
            OrganizationRole::Administrator,
            ProjectPermission::View,
            true,
        ],
        'administrator may create projects' => [
            OrganizationRole::Administrator,
            ProjectPermission::Create,
            true,
        ],
        'administrator may update projects' => [
            OrganizationRole::Administrator,
            ProjectPermission::Update,
            true,
        ],
        'administrator may approve project operations' => [
            OrganizationRole::Administrator,
            ProjectPermission::Approve,
            true,
        ],
        'administrator may manage project integrations' => [
            OrganizationRole::Administrator,
            ProjectPermission::ManageIntegrations,
            true,
        ],
        'administrator may archive projects' => [
            OrganizationRole::Administrator,
            ProjectPermission::Archive,
            true,
        ],
        'administrator may restore projects' => [
            OrganizationRole::Administrator,
            ProjectPermission::Restore,
            true,
        ],

        'member may view projects' => [
            OrganizationRole::Member,
            ProjectPermission::View,
            true,
        ],
        'member may create projects' => [
            OrganizationRole::Member,
            ProjectPermission::Create,
            true,
        ],
        'member may update projects' => [
            OrganizationRole::Member,
            ProjectPermission::Update,
            true,
        ],
        'member may not approve project operations' => [
            OrganizationRole::Member,
            ProjectPermission::Approve,
            false,
        ],
        'member may not manage project integrations' => [
            OrganizationRole::Member,
            ProjectPermission::ManageIntegrations,
            false,
        ],
        'member may not archive projects' => [
            OrganizationRole::Member,
            ProjectPermission::Archive,
            false,
        ],
        'member may not restore projects' => [
            OrganizationRole::Member,
            ProjectPermission::Restore,
            false,
        ],

        'viewer may view projects' => [
            OrganizationRole::Viewer,
            ProjectPermission::View,
            true,
        ],
        'viewer may not create projects' => [
            OrganizationRole::Viewer,
            ProjectPermission::Create,
            false,
        ],
        'viewer may not update projects' => [
            OrganizationRole::Viewer,
            ProjectPermission::Update,
            false,
        ],
        'viewer may not approve project operations' => [
            OrganizationRole::Viewer,
            ProjectPermission::Approve,
            false,
        ],
        'viewer may not manage project integrations' => [
            OrganizationRole::Viewer,
            ProjectPermission::ManageIntegrations,
            false,
        ],
        'viewer may not archive projects' => [
            OrganizationRole::Viewer,
            ProjectPermission::Archive,
            false,
        ],
        'viewer may not restore projects' => [
            OrganizationRole::Viewer,
            ProjectPermission::Restore,
            false,
        ],
    ];
}

dataset('project permission matrix', projectPermissionMatrixCases());

test(
    'project permission matrix resolves :dataset',
    function (
        OrganizationRole $role,
        ProjectPermission $permission,
        bool $expectedAllowed,
    ): void {
        $matrix = new ProjectPermissionMatrix;

        expect($matrix->allows($role, $permission))
            ->toBe($expectedAllowed);
    },
)->with('project permission matrix');

test(
    'project permission dataset covers every role and permission pair exactly once',
    function (): void {
        $coveredPairs = [];

        foreach (projectPermissionMatrixCases() as [$role, $permission]) {
            $coveredPairs[] = $role->value.':'.$permission->value;
        }

        $expectedPairCount = count(OrganizationRole::cases())
            * count(ProjectPermission::cases());

        expect($coveredPairs)
            ->toHaveCount($expectedPairCount)
            ->and(array_values(array_unique($coveredPairs)))
            ->toHaveCount($expectedPairCount);
    },
);
