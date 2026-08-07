<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('an organization member sees the accessible empty roadmap state', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    OrganizationMembership::factory()->for($organization)->for($user)->create();
    $project = Project::factory()->for($organization)->create();

    $this->actingAs($user)
        ->get(route('organizations.projects.roadmaps.index', compact('organization', 'project')))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('projects/roadmaps/show')
            ->where('organization.id', $organization->id)
            ->where('project.id', $project->id)
            ->where('roadmap', null)
            ->missing('diagnostics')
            ->has('revisions', 0)
            ->where('permissions.decide', false));
});

test('a roadmap route cannot expose another organization project', function (): void {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $otherUser = User::factory()->create();
    $otherOrganization = Organization::factory()->create();
    OrganizationMembership::factory()->for($otherOrganization)->for($otherUser)->owner()->create();

    $this->actingAs($otherUser)
        ->get(route('organizations.projects.roadmaps.index', [
            'organization' => $otherOrganization,
            'project' => $project,
        ]))
        ->assertNotFound();
});
