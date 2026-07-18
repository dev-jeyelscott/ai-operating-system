<?php

declare(strict_types=1);

use App\Domain\Identity\OrganizationRole;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Projects\ProjectType;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create an organization membership for a Project CRUD test user.
 */
function createProjectCrudOrganization(
    User $user,
    OrganizationRole $role = OrganizationRole::Owner,
): Organization {
    $organization = Organization::factory()->create();

    OrganizationMembership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $organization;
}

test('an organization member can list only its current projects', function () {
    $user = User::factory()->create();

    $organization = createProjectCrudOrganization($user);

    $currentProject = Project::factory()
        ->for($organization)
        ->create([
            'name' => 'Current Project',
        ]);

    Project::factory()
        ->for($organization)
        ->create([
            'name' => 'Archived Project',
            'archived_at' => now(),
        ]);

    $otherOrganization = Organization::factory()->create();

    Project::factory()
        ->for($otherOrganization)
        ->create([
            'name' => 'Hidden Project',
        ]);

    $this
        ->actingAs($user)
        ->get(
            route('organizations.projects.index', [
                'organization' => $organization,
            ]),
        )
        ->assertOk()
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->component('projects/index')
                ->where('organization.id', $organization->id)
                ->where('filters.archived', false)
                ->has('projects.data', 1)
                ->where('projects.data.0.id', $currentProject->id)
                ->where('projects.data.0.name', 'Current Project'),
        );
});

test('an organization member can list archived projects', function () {
    $user = User::factory()->create();

    $organization = createProjectCrudOrganization($user);

    Project::factory()
        ->for($organization)
        ->create([
            'name' => 'Current Project',
        ]);

    $archivedProject = Project::factory()
        ->for($organization)
        ->create([
            'name' => 'Archived Project',
            'archived_at' => now(),
        ]);

    $this
        ->actingAs($user)
        ->get(
            route('organizations.projects.index', [
                'organization' => $organization,
                'archived' => 1,
            ]),
        )
        ->assertOk()
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->component('projects/index')
                ->where('filters.archived', true)
                ->has('projects.data', 1)
                ->where('projects.data.0.id', $archivedProject->id),
        );
});

test('an owner can create a draft project', function () {
    $user = User::factory()->create();

    $organization = createProjectCrudOrganization(
        $user,
        OrganizationRole::Owner,
    );

    $otherOrganization = Organization::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(
            route('organizations.projects.store', [
                'organization' => $organization,
            ]),
            [
                'name' => '  AI Operations Platform  ',
                'description' => '  Project description.  ',
                'project_type' => ProjectType::WebApplication->value,

                /*
                 * These unvalidated ownership and state values must be ignored.
                 */
                'organization_id' => $otherOrganization->id,
                'status' => ProjectStatus::Active->value,
                'archived_at' => now()->toIso8601String(),
            ],
        );

    $project = Project::query()->sole();

    $response
        ->assertRedirect(
            route('organizations.projects.show', [
                'organization' => $organization,
                'project' => $project,
            ]),
        )
        ->assertSessionHas('status', 'project-created');

    expect($project->organization_id)
        ->toBe($organization->id)
        ->and($project->name)
        ->toBe('AI Operations Platform')
        ->and($project->description)
        ->toBe('Project description.')
        ->and($project->status)
        ->toBe(ProjectStatus::Draft)
        ->and($project->archived_at)
        ->toBeNull();
});

test('a viewer cannot create a project', function () {
    $user = User::factory()->create();

    $organization = createProjectCrudOrganization(
        $user,
        OrganizationRole::Viewer,
    );

    $this
        ->actingAs($user)
        ->post(
            route('organizations.projects.store', [
                'organization' => $organization,
            ]),
            [
                'name' => 'Forbidden Project',
                'description' => null,
                'project_type' => ProjectType::WebApplication->value,
            ],
        )
        ->assertForbidden();

    $this->assertDatabaseCount('projects', 0);
});

test('a member can view and update project metadata', function () {
    $user = User::factory()->create();

    $organization = createProjectCrudOrganization(
        $user,
        OrganizationRole::Member,
    );

    $project = Project::factory()
        ->for($organization)
        ->create([
            'name' => 'Original Name',
            'description' => 'Original description.',
            'project_type' => ProjectType::WebApplication,
        ]);

    $originalSlug = $project->slug;
    $originalStatus = $project->status;

    $this
        ->actingAs($user)
        ->get(
            route('organizations.projects.show', [
                'organization' => $organization,
                'project' => $project,
            ]),
        )
        ->assertOk()
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->component('projects/show')
                ->where('project.id', $project->id)
                ->where('permissions.update', true),
        );

    $this
        ->actingAs($user)
        ->put(
            route('organizations.projects.update', [
                'organization' => $organization,
                'project' => $project,
            ]),
            [
                'name' => 'Updated Name',
                'description' => 'Updated description.',
                'project_type' => ProjectType::Api->value,
                'status' => ProjectStatus::Completed->value,
            ],
        )
        ->assertRedirect(
            route('organizations.projects.show', [
                'organization' => $organization,
                'project' => $project,
            ]),
        )
        ->assertSessionHas('status', 'project-updated');

    $project->refresh();

    expect($project->name)
        ->toBe('Updated Name')
        ->and($project->description)
        ->toBe('Updated description.')
        ->and($project->project_type)
        ->toBe(ProjectType::Api)
        ->and($project->slug)
        ->toBe($originalSlug)
        ->and($project->status)
        ->toBe($originalStatus)
        ->and($project->organization_id)
        ->toBe($organization->id);
});

test('a viewer can view but cannot update a project', function () {
    $user = User::factory()->create();

    $organization = createProjectCrudOrganization(
        $user,
        OrganizationRole::Viewer,
    );

    $project = Project::factory()
        ->for($organization)
        ->create();

    $this
        ->actingAs($user)
        ->get(
            route('organizations.projects.show', [
                'organization' => $organization,
                'project' => $project,
            ]),
        )
        ->assertOk();

    $this
        ->actingAs($user)
        ->put(
            route('organizations.projects.update', [
                'organization' => $organization,
                'project' => $project,
            ]),
            [
                'name' => 'Forbidden Update',
                'description' => null,
                'project_type' => ProjectType::Api->value,
            ],
        )
        ->assertForbidden();
});

test('an owner can archive and restore a project idempotently', function () {
    $user = User::factory()->create();

    $organization = createProjectCrudOrganization(
        $user,
        OrganizationRole::Owner,
    );

    $project = Project::factory()
        ->for($organization)
        ->create([
            'status' => ProjectStatus::Configuring,
        ]);

    $this
        ->actingAs($user)
        ->put(
            route('organizations.projects.archive', [
                'organization' => $organization,
                'project' => $project,
            ]),
        )
        ->assertRedirect(
            route('organizations.projects.show', [
                'organization' => $organization,
                'project' => $project,
            ]),
        )
        ->assertSessionHas('status', 'project-archived');

    $project->refresh();

    expect($project->archived_at)
        ->not->toBeNull()
        ->and($project->status)
        ->toBe(ProjectStatus::Configuring);

    $archivedAt = $project->archived_at;

    /*
     * A repeated archive command must not fail or alter the workflow state.
     */
    $this
        ->actingAs($user)
        ->put(
            route('organizations.projects.archive', [
                'organization' => $organization,
                'project' => $project,
            ]),
        )
        ->assertRedirect();

    expect($project->refresh()->archived_at?->equalTo($archivedAt))
        ->toBeTrue()
        ->and($project->status)
        ->toBe(ProjectStatus::Configuring);

    $this
        ->actingAs($user)
        ->put(
            route('organizations.projects.restore', [
                'organization' => $organization,
                'project' => $project,
            ]),
        )
        ->assertRedirect(
            route('organizations.projects.show', [
                'organization' => $organization,
                'project' => $project,
            ]),
        )
        ->assertSessionHas('status', 'project-restored');

    expect($project->refresh()->archived_at)
        ->toBeNull()
        ->and($project->status)
        ->toBe(ProjectStatus::Configuring);
});

test('a regular member cannot archive or restore projects', function () {
    $user = User::factory()->create();

    $organization = createProjectCrudOrganization(
        $user,
        OrganizationRole::Member,
    );

    $project = Project::factory()
        ->for($organization)
        ->create();

    $this
        ->actingAs($user)
        ->put(
            route('organizations.projects.archive', [
                'organization' => $organization,
                'project' => $project,
            ]),
        )
        ->assertForbidden();

    $project->forceFill([
        'archived_at' => now(),
    ])->save();

    $this
        ->actingAs($user)
        ->put(
            route('organizations.projects.restore', [
                'organization' => $organization,
                'project' => $project,
            ]),
        )
        ->assertForbidden();
});

test('a non-member cannot list another organizations projects', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Project::factory()
        ->for($organization)
        ->create();

    $this
        ->actingAs($user)
        ->get(
            route('organizations.projects.index', [
                'organization' => $organization,
            ]),
        )
        ->assertNotFound();
});

test('scoped binding rejects a project owned by another organization', function () {
    $user = User::factory()->create();

    $firstOrganization = createProjectCrudOrganization(
        $user,
        OrganizationRole::Owner,
    );

    $secondOrganization = createProjectCrudOrganization(
        $user,
        OrganizationRole::Owner,
    );

    $secondProject = Project::factory()
        ->for($secondOrganization)
        ->create();

    /*
     * The user belongs to both organizations. The 404 must therefore come from
     * parent-child route scoping, not merely from membership authorization.
     */
    $this
        ->actingAs($user)
        ->get(
            route('organizations.projects.show', [
                'organization' => $firstOrganization,
                'project' => $secondProject,
            ]),
        )
        ->assertNotFound();
});

test('archive state cannot be changed through mass assignment', function () {
    $project = new Project;

    expect($project->isFillable('archived_at'))
        ->toBeFalse()
        ->and($project->isFillable('status'))
        ->toBeFalse()
        ->and($project->isFillable('organization_id'))
        ->toBeFalse();
});
