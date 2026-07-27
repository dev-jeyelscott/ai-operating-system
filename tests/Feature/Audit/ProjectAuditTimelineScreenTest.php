<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Models\Artifact;
use App\Models\AuditEvent;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create one authoritative project audit event fixture.
 *
 * @param  array<string, mixed>  $overrides
 */
function createProjectAuditScreenEvent(
    Organization $organization,
    Project $project,
    array $overrides = [],
): AuditEvent {
    return AuditEvent::query()->create(array_merge([
        'event_id' => (string) Str::ulid(),
        'organization_id' => $organization->id,
        'project_id' => $project->id,
        'actor_type' => AuditActorType::System,
        'actor_id' => 'project-audit-screen-test',
        'event_type' => AuditEventType::ExecutionAttemptStarted,
        'subject_type' => AuditSubjectType::Execution,
        'subject_id' => 'execution-subject',
        'correlation_id' => null,
        'causation_id' => null,
        'execution_id' => null,
        'schema_version' => 1,
        'deduplication_key' => null,
        'metadata' => [],
        'occurred_at' => CarbonImmutable::parse(
            '2026-07-27T12:00:00+08:00',
        ),
    ], $overrides));
}

/**
 * Create one owner, organization, and project fixture.
 *
 * @return array{
 *     user: User,
 *     organization: Organization,
 *     project: Project
 * }
 */
function projectAuditScreenFixture(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    OrganizationMembership::factory()
        ->for($organization)
        ->for($user)
        ->owner()
        ->create();

    $project = Project::factory()
        ->for($organization)
        ->create();

    return [
        'user' => $user,
        'organization' => $organization,
        'project' => $project,
    ];
}

test(
    'an authorized project member can inspect executions attempts artifacts and audit events',
    function (): void {
        [
            'user' => $user,
            'organization' => $organization,
            'project' => $project,
        ] = projectAuditScreenFixture();

        $execution = Execution::factory()
            ->for($project)
            ->completed()
            ->create([
                'attempt_count' => 2,
                'capability' => 'planning',
                'logical_role' => 'project_manager',
            ]);

        $failedAttempt = ExecutionAttempt::factory()
            ->for($execution)
            ->failed()
            ->create([
                'attempt_number' => 1,
                'estimated_cost' => '0.01250000',
                'cost_currency' => 'USD',
                'retry_delay_seconds' => 30,
            ]);

        $completedAttempt = ExecutionAttempt::factory()
            ->for($execution)
            ->completed()
            ->create([
                'attempt_number' => 2,
                'estimated_cost' => '0.02000000',
                'actual_cost' => '0.01800000',
                'cost_currency' => 'USD',
            ]);

        Artifact::factory()
            ->forAttempt($completedAttempt)
            ->create([
                'name' => 'Simulated planning result',
            ]);

        createProjectAuditScreenEvent(
            organization: $organization,
            project: $project,
            overrides: [
                'execution_id' => $execution->id,
                'subject_id' => $execution->id,
                'correlation_id' => $execution->correlation_id,
                'event_type' => AuditEventType::ExecutionAttemptStarted,
                'occurred_at' => CarbonImmutable::parse(
                    '2026-07-27T12:00:00+08:00',
                ),
            ],
        );

        createProjectAuditScreenEvent(
            organization: $organization,
            project: $project,
            overrides: [
                'execution_id' => $execution->id,
                'subject_id' => $execution->id,
                'correlation_id' => $execution->correlation_id,
                'event_type' => AuditEventType::ExecutionRetryScheduled,
                'occurred_at' => CarbonImmutable::parse(
                    '2026-07-27T12:01:00+08:00',
                ),
            ],
        );

        $this->actingAs($user)
            ->get(route('organizations.projects.audit.index', [
                'organization' => $organization,
                'project' => $project,
                'execution' => $execution->id,
            ]))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('projects/audit')
                    ->where('organization.id', $organization->id)
                    ->where('project.id', $project->id)
                    ->where('filters.execution', $execution->id)
                    ->has('executions', 1)
                    ->where('executions.0.id', $execution->id)
                    ->where('executions.0.attemptCount', 2)
                    ->where('executions.0.retryCount', 1)
                    ->where('executions.0.errorCount', 1)
                    ->where(
                        'executions.0.estimatedCost',
                        '0.03250000',
                    )
                    ->where(
                        'selectedExecution.attempts.0.id',
                        $failedAttempt->id,
                    )
                    ->where(
                        'selectedExecution.attempts.0.error.code',
                        'provider.failed',
                    )
                    ->where(
                        'selectedExecution.attempts.0.error.retryable',
                        true,
                    )
                    ->where(
                        'selectedExecution.attempts.1.id',
                        $completedAttempt->id,
                    )
                    ->where(
                        'selectedExecution.artifacts.0.name',
                        'Simulated planning result',
                    )
                    ->where(
                        'selectedExecution.artifacts.0.isSimulated',
                        true,
                    )
                    ->where(
                        'selectedExecution.artifacts.0.actualState',
                        'unverified',
                    )
                    ->has('timeline.data', 2)
                    ->where(
                        'timeline.data.0.eventType.value',
                        AuditEventType::ExecutionRetryScheduled->value,
                    )
                    ->where(
                        'timeline.data.1.eventType.value',
                        AuditEventType::ExecutionAttemptStarted->value,
                    ),
            );
    },
);

test(
    'execution and event filters narrow the project audit timeline',
    function (): void {
        [
            'user' => $user,
            'organization' => $organization,
            'project' => $project,
        ] = projectAuditScreenFixture();

        $execution = Execution::factory()
            ->for($project)
            ->create();

        $otherExecution = Execution::factory()
            ->for($project)
            ->create();

        $matching = createProjectAuditScreenEvent(
            organization: $organization,
            project: $project,
            overrides: [
                'execution_id' => $execution->id,
                'subject_id' => $execution->id,
                'event_type' => AuditEventType::ExecutionAttemptFailed,
            ],
        );

        createProjectAuditScreenEvent(
            organization: $organization,
            project: $project,
            overrides: [
                'execution_id' => $otherExecution->id,
                'subject_id' => $otherExecution->id,
                'event_type' => AuditEventType::ExecutionAttemptCompleted,
            ],
        );

        $this->actingAs($user)
            ->get(route('organizations.projects.audit.index', [
                'organization' => $organization,
                'project' => $project,
                'execution' => $execution->id,
                'event_type' => AuditEventType::ExecutionAttemptFailed->value,
            ]))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->has('timeline.data', 1)
                    ->where(
                        'timeline.data.0.eventId',
                        $matching->event_id,
                    )
                    ->where(
                        'timeline.data.0.executionId',
                        $execution->id,
                    ),
            );
    },
);

test(
    'a project audit screen cannot expose another organization project',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
        ] = projectAuditScreenFixture();

        $otherUser = User::factory()->create();
        $otherOrganization = Organization::factory()->create();

        OrganizationMembership::factory()
            ->for($otherOrganization)
            ->for($otherUser)
            ->owner()
            ->create();

        $this->actingAs($otherUser)
            ->get(route('organizations.projects.audit.index', [
                'organization' => $otherOrganization,
                'project' => $project,
            ]))
            ->assertNotFound();

        expect($project->organization_id)
            ->toBe($organization->id);
    },
);

test(
    'an execution identifier from another project returns not found',
    function (): void {
        [
            'user' => $user,
            'organization' => $organization,
            'project' => $project,
        ] = projectAuditScreenFixture();

        $otherProject = Project::factory()
            ->for($organization)
            ->create();

        $otherExecution = Execution::factory()
            ->for($otherProject)
            ->create();

        $this->actingAs($user)
            ->get(route('organizations.projects.audit.index', [
                'organization' => $organization,
                'project' => $project,
                'execution' => $otherExecution->id,
            ]))
            ->assertNotFound();
    },
);
