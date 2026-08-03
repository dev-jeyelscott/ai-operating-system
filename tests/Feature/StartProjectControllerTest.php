<?php

declare(strict_types=1);

use App\Application\Projects\Commands\StartProject;
use App\Application\Shared\Commands\CommandBus;
use App\Application\Shared\Commands\CommandResult;
use App\Domain\Identity\OrganizationRole;
use App\Domain\Projects\ProjectStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DeterministicDemoSeeder;

/**
 * @return array{owner: User, organization: Organization, project: Project}
 */
function startProjectControllerFixture(): array
{
    $owner = User::factory()->create();
    $organization = Organization::factory()->create();

    OrganizationMembership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role' => OrganizationRole::Owner,
    ]);

    return [
        'owner' => $owner,
        'organization' => $organization,
        'project' => Project::factory()
            ->for($organization)
            ->create(),
    ];
}

/**
 * @param  array{owner: User, organization: Organization, project: Project}  $fixture
 */
function startProjectControllerUrl(array $fixture): string
{
    return route('organizations.projects.start', [
        'organization' => $fixture['organization'],
        'project' => $fixture['project'],
    ]);
}

test('an authorized owner can start a project through the command bus', function (): void {
    $fixture = startProjectControllerFixture();
    $commands = Mockery::mock(CommandBus::class);

    $commands->shouldReceive('dispatch')
        ->once()
        ->with(Mockery::on(
            static fn (mixed $command): bool => $command instanceof StartProject
                && $command->organizationId === $fixture['organization']->id
                && $command->projectId === $fixture['project']->id
                && $command->requestedByUserId === $fixture['owner']->id
                && $command->requestIdempotencyKey === 'start-project:controller:001',
        ))
        ->andReturn(CommandResult::succeeded());

    $this->app->instance(CommandBus::class, $commands);

    $this->actingAs($fixture['owner'])
        ->post(startProjectControllerUrl($fixture), [
            'idempotency_key' => 'start-project:controller:001',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'project-started')
        ->assertSessionHasNoErrors();
});

test('the deterministic happy-path owner starts the real planning workflow', function (): void {
    $this->seed(DeterministicDemoSeeder::class);

    $owner = User::query()
        ->where('email', 'demo-owner@example.test')
        ->firstOrFail();
    $project = Project::query()
        ->where('slug', 'demo-happy-path')
        ->firstOrFail();

    $this->actingAs($owner)
        ->post(route('organizations.projects.start', [
            'organization' => $project->organization,
            'project' => $project,
        ]), [
            'idempotency_key' => 'start-project:demo:integration',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'project-started')
        ->assertSessionHasNoErrors();

    expect($project->refresh()->status)->toBe(ProjectStatus::Planning);
});

test('a command conflict is returned as a safe project form error', function (): void {
    $fixture = startProjectControllerFixture();
    $commands = Mockery::mock(CommandBus::class);

    $commands->shouldReceive('dispatch')
        ->once()
        ->andReturn(CommandResult::conflict(
            'The project context changed after StartProject was prepared.',
        ));

    $this->app->instance(CommandBus::class, $commands);

    $this->actingAs($fixture['owner'])
        ->post(startProjectControllerUrl($fixture), [
            'idempotency_key' => 'start-project:controller:conflict',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('project');
});

test('a member without start permission cannot submit a project start request', function (): void {
    $fixture = startProjectControllerFixture();
    $member = User::factory()->create();

    OrganizationMembership::factory()->create([
        'organization_id' => $fixture['organization']->id,
        'user_id' => $member->id,
        'role' => OrganizationRole::Member,
    ]);

    $this->actingAs($member)
        ->post(startProjectControllerUrl($fixture), [
            'idempotency_key' => 'start-project:controller:forbidden',
        ])
        ->assertForbidden();
});

test('a start request requires a valid idempotency key', function (): void {
    $fixture = startProjectControllerFixture();

    $this->actingAs($fixture['owner'])
        ->post(startProjectControllerUrl($fixture), [
            'idempotency_key' => 'not valid',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('idempotency_key');
});
