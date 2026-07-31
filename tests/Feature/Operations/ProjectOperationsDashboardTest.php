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
 * Create an organization owner and one project for operations page tests.
 *
 * @return array{0: User, 1: Organization, 2: Project}
 */
function createOperationsProjectContext(): array
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

it('renders the authoritative project operations dashboard', function (): void {
    [$user, $organization, $project] = createOperationsProjectContext();

    $response = $this
        ->actingAs($user)
        ->get(route('organizations.projects.operations.index', [
            'organization' => $organization,
            'project' => $project,
        ]));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('projects/operations/index')
            ->where('organization.id', $organization->id)
            ->where('project.id', $project->id)
            ->where('operations.project.id', $project->id)
            ->where('operations.summary.activeAgents', 0)
            ->where('operations.summary.pendingApprovals', 0)
            ->where(
                'recoveryCenterUrl',
                route(
                    'organizations.projects.operations.recovery.index',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
            )
            ->where(
                'usageUrl',
                route(
                    'organizations.projects.operations.usage.index',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
            )
            ->where(
                'officeProjectionUrl',
                route(
                    'organizations.projects.operations.office-projection.show',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
            )
            ->has('operations.layers', 4)
        );
});

it('does not resolve a project through another organization route', function (): void {
    [$user, $organization] = createOperationsProjectContext();

    $otherOrganization = Organization::factory()->create();

    $otherProject = Project::factory()
        ->for($otherOrganization)
        ->create();

    $this
        ->actingAs($user)
        ->get(route('organizations.projects.operations.index', [
            'organization' => $organization,
            'project' => $otherProject,
        ]))
        ->assertNotFound();
});
