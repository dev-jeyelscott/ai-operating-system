<?php

declare(strict_types=1);

use App\Domain\Executions\ExecutionStatus;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OutboxMessage;
use App\Models\Project;
use App\Models\ProjectConfigurationVersion;
use App\Models\ProjectContextSnapshot;
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

    $configurationVersion = ProjectConfigurationVersion::query()->create([
        'project_id' => $project->id,
        'schema_version' => 1,
        'revision' => 1,
        'actor_type' => 'system',
        'actor_id' => 'operational-metrics-test',
        'change_reason' => 'Create operational metrics test context.',
        'snapshot' => [
            'schema_version' => 1,
            'project_id' => $project->id,
        ],
        'created_at' => now(),
    ]);

    $contextSnapshot = ProjectContextSnapshot::query()->create([
        'project_id' => $project->id,
        'project_configuration_version_id' => $configurationVersion->id,
        'configuration_revision' => $configurationVersion->revision,
        'identity_schema_version' => 1,
        'approved_document_set_fingerprint' => hash(
            'sha256',
            'operational-metrics-empty-document-set',
        ),
        'approved_document_versions' => [],
    ]);
    $completedExecution = Execution::factory()
        ->for($project)
        ->create([
            'project_context_snapshot_id' => $contextSnapshot->id,
            'status' => ExecutionStatus::Completed,
            'attempt_count' => 2,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(12),
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
            'project_context_snapshot_id' => $contextSnapshot->id,
            'status' => ExecutionStatus::Running,
            'attempt_count' => 1,
            'started_at' => now()->subMinutes(3),
            'finished_at' => null,
            'created_at' => now()->subMinutes(4),
        ]);
    $inputFingerprint = hash(
        'sha256',
        'operational-metrics-test-input',
    );

    $outputFingerprint = hash(
        'sha256',
        'operational-metrics-test-output',
    );

    $roadmap = Roadmap::query()->create([
        'project_id' => $project->id,
        'planning_execution_id' => $completedExecution->id,
        'project_context_snapshot_id' => $contextSnapshot->id,
        'parent_roadmap_id' => null,
        'approval_id' => null,
        'schema_version' => 1,
        'revision' => 1,
        'content_version' => 1,
        'provider_id' => 'simulation',
        'scenario' => 'operational-metrics-test',
        'seed' => 142,
        'input_fingerprint' => $inputFingerprint,
        'output_fingerprint' => $outputFingerprint,
        'candidate_fingerprint' => $outputFingerprint,
        'approved_fingerprint' => null,
        'status' => 'generated',
        'readiness' => 'ready',
        'goal' => 'Verify project operational metrics.',
        'scope' => [
            'Project-scoped queue and workflow metrics.',
        ],
        'assumptions' => [],
        'constraints' => [],
        'definition_of_done' => [
            'The operational metrics response is rendered.',
        ],
        'required_approvals' => [],
        'document_inventory' => [],
        'document_summary' => 'No documents are required for this test.',
        'architecture_concerns' => [],
        'security_concerns' => [],
        'readiness_reasons' => [],
        'metadata' => [
            'fixture' => 'operational-metrics',
            'verification' => 'test',
        ],
        'derived_graph' => [
            'nodes' => [],
            'edges' => [],
        ],
        'generated_snapshot' => [
            'goal' => 'Verify project operational metrics.',
            'tasks' => [],
        ],
        'approved_snapshot' => null,
        'regeneration_feedback' => null,
        'feedback_fingerprint' => null,
        'generated_at' => now(),
        'approved_at' => null,
    ]);

    $ticket = RoadmapTask::query()->create([
        'roadmap_id' => $roadmap->id,
        'roadmap_phase_id' => null,
        'roadmap_milestone_id' => null,
        'stable_id' => 'AIOS-142-TEST',
        'title' => 'Operational metrics test ticket',
        'objective' => 'Provide a valid ticket for the active lease fixture.',
        'ticket_type' => 'implementation',
        'scope' => [
            'included' => [
                'Queue lease metrics',
            ],
            'excluded' => [],
        ],
        'acceptance_criteria' => [],
        'source_references' => [],
        'evidence_requirements' => [],
        'notion_body_overrides' => null,
        'priority' => 'high',
        'risk' => 'medium',
        'reasoning_level' => 'medium',
        'reasoning' => 'The test requires a valid durable ticket lease.',
        'logical_agent' => 'backend_engineer',
        'estimated_complexity' => 1,
        'human_approval_required' => false,
        'position' => 1,
        'critical_path_rank' => null,
        'critical_path_position' => null,
        'is_critical_path' => false,
    ]);

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
