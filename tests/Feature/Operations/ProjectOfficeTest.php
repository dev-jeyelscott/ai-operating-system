<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Create one organization owner and project for office page tests.
 *
 * @return array{0: User, 1: Organization, 2: Project}
 */
function createOfficeProjectContext(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    OrganizationMembership::factory()
        ->owner()
        ->for($organization)
        ->for($user)
        ->create();

    $project = Project::factory()
        ->for($organization)
        ->create();

    return [$user, $organization, $project];
}

it('renders the tenant-scoped office page from persisted projection state', function (): void {
    [$user, $organization, $project] = createOfficeProjectContext();

    $response = $this
        ->actingAs($user)
        ->get(route('organizations.projects.operations.office.index', [
            'organization' => $organization,
            'project' => $project,
        ]));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('projects/operations/office')
            ->where('organization.id', $organization->id)
            ->where('project.id', $project->id)
            ->where('officeProjection.project.id', $project->id)
            ->where('officeProjection.metadata.schemaVersion', 1)
            ->has('officeProjection.rooms', 7)
            ->where(
                'operationsUrl',
                route(
                    'organizations.projects.operations.index',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
            )
            ->where(
                'officeProjectionEndpointUrl',
                route(
                    'organizations.projects.operations.office-projection.show',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
            )
        );
});

it('does not resolve an office project through another organization', function (): void {
    [$user, $organization] = createOfficeProjectContext();

    $otherOrganization = Organization::factory()->create();

    $otherProject = Project::factory()
        ->for($otherOrganization)
        ->create();

    $this
        ->actingAs($user)
        ->get(route('organizations.projects.operations.office.index', [
            'organization' => $organization,
            'project' => $otherProject,
        ]))
        ->assertNotFound();
});
