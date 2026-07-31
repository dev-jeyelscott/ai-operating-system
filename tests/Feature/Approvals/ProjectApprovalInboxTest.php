<?php

declare(strict_types=1);

use App\Domain\Approvals\ApprovalType;
use App\Models\Approval;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Create an organization owner and project for approval inbox tests.
 *
 * @return array{0: User, 1: Organization, 2: Project}
 */
function createApprovalInboxProjectContext(): array
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

it('lists pending approvals and excludes terminal approvals', function (): void {
    [$user, $organization, $project] = createApprovalInboxProjectContext();

    $pending = Approval::factory()
        ->for($project)
        ->create([
            'type' => ApprovalType::Recovery,
            'request_payload' => [
                'summary' => 'Approve a safe recovery replay.',
            ],
        ]);

    Approval::factory()
        ->approved()
        ->for($project)
        ->create();

    $response = $this
        ->actingAs($user)
        ->get(route('organizations.projects.approvals.index', [
            'organization' => $organization,
            'project' => $project,
        ]));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('projects/approvals/index')
            ->where('project.id', $project->id)
            ->where('canApprove', true)
            ->where('inbox.summary.total', 1)
            ->where('inbox.summary.recovery', 1)
            ->has('inbox.items', 1)
            ->where('inbox.items.0.id', $pending->id)
            ->where('inbox.items.0.category', 'recovery')
        );
});

it('filters the approval inbox by category', function (): void {
    [$user, $organization, $project] = createApprovalInboxProjectContext();

    Approval::factory()
        ->for($project)
        ->create(['type' => ApprovalType::Roadmap]);

    Approval::factory()
        ->for($project)
        ->create(['type' => ApprovalType::Recovery]);

    $response = $this
        ->actingAs($user)
        ->get(route('organizations.projects.approvals.index', [
            'organization' => $organization,
            'project' => $project,
            'category' => 'roadmap',
        ]));

    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('filters.category', 'roadmap')
        ->has('inbox.items', 1)
        ->where('inbox.items.0.category', 'roadmap')
    );
});

it('allows a viewer to inspect the inbox without granting approval actions', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    OrganizationMembership::factory()
        ->viewer()
        ->for($organization)
        ->for($user)
        ->create();

    $project = Project::factory()
        ->for($organization)
        ->create();

    Approval::factory()
        ->for($project)
        ->create();

    $this
        ->actingAs($user)
        ->get(route('organizations.projects.approvals.index', [
            'organization' => $organization,
            'project' => $project,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('canApprove', false)
            ->has('inbox.items', 1)
        );
});
