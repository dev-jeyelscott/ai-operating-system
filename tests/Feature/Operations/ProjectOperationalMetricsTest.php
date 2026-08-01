<?php

declare(strict_types=1);

use App\Domain\Executions\ExecutionStatus;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OutboxMessage;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\RoadmapTask;
use App\Models\TicketExecutionLease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Create one organization owner and project for metric tests.
 *
 * @return array{0: User, 1: Organization, 2: Project}
 */
function createOperationalMetricProject(): array
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

it('renders project-scoped operational metrics', function (): void {
    [$user, $organization, $project] =
        createOperationalMetricProject();

    $completedExecution = Execution::factory()
        ->for($project)
        ->create([
            'status' => ExecutionStatus::Completed,
            'attempt_count' => 2,
            'created_at' => now()->subMinutes(12),
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(5),
        ]);

    ExecutionAttempt::factory()
        ->for($completedExecution)
        ->create([
            'attempt_number' => 1,
            'status' => 'failed',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ]);

    ExecutionAttempt::factory()
        ->for($completedExecution)
        ->create([
            'attempt_number' => 2,
            'status' => 'completed',
            'started_at' => now()->subMinutes(8),
            'finished_at' => now()->subMinutes(5),
        ]);

    $activeExecution = Execution::factory()
        ->for($project)
        ->create([
            'status' => ExecutionStatus::Running,
            'attempt_count' => 1,
            'created_at' => now()->subMinutes(4),
            'started_at' => now()->subMinutes(3),
        ]);

    $roadmap = Roadmap::factory()
        ->for($project)
        ->create();

    $ticket = RoadmapTask::factory()
        ->for($roadmap)
        ->create();

    TicketExecutionLease::query()->create([
        'project_id' => $project->id,
        'roadmap_task_id' => $ticket->id,
        'execution_id' => $activeExecution->id,
        'owner' => 'test-worker',
        'acquired_at' => now()->subMinutes(5),
        'expires_at' => now()->subMinute(),
        'heartbeat_at' => now()->subMinutes(4),
    ]);

    OutboxMessage::query()->create([
        'event_id' => (string) Str::ulid(),
        'event_name' => 'workflow.test_failed',
        'aggregate_type' => 'project',
        'aggregate_id' => (string) $project->id,
        'organization_id' => $organization->id,
        'project_id' => $project->id,
        'occurred_at' => now()->subMinutes(3),
        'correlation_id' => (string) Str::ulid(),
        'causation_id' => null,
        'execution_id' => $activeExecution->id,
        'schema_version' => 1,
        'envelope' => [],
        'available_at' => now()->subMinutes(3),
        'dispatch_attempts' => 3,
        'dead_lettered_at' => now()->subMinute(),
        'replay_count' => 0,
        'created_at' => now()->subMinutes(3),
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route(
            'organizations.projects.operations.metrics.index',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ));

    $response
        ->assertOk()
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->component('projects/operations/metrics')
                ->where('organization.id', $organization->id)
                ->where('project.id', $project->id)
                ->where('metrics.executions.total', 2)
                ->where('metrics.executions.active', 1)
                ->where('metrics.executions.completed', 1)
                ->where('metrics.attempts.total', 2)
                ->where('metrics.attempts.retries', 1)
                ->where('metrics.attempts.failed', 1)
                ->where('metrics.leases.active', 1)
                ->where('metrics.leases.expiredActive', 1)
                ->where('metrics.leases.staleHeartbeat', 1)
                ->where('metrics.deadLetters.current', 1),
        );
});

it('rejects a project from another organization boundary', function (): void {
    [$user, $organization] = createOperationalMetricProject();

    $otherOrganization = Organization::factory()->create();

    $otherProject = Project::factory()
        ->for($otherOrganization)
        ->create();

    $this
        ->actingAs($user)
        ->get(route(
            'organizations.projects.operations.metrics.index',
            [
                'organization' => $organization,
                'project' => $otherProject,
            ],
        ))
        ->assertNotFound();
});
