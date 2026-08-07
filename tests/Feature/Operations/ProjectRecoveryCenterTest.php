<?php

declare(strict_types=1);

use App\Domain\Audit\AuditEventType;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Identity\OrganizationRole;
use App\Models\AuditEvent;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OutboxMessage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Create one organization, membership, and project for recovery tests.
 *
 * @return array{0: User, 1: Organization, 2: Project}
 */
function createRecoveryTestContext(
    OrganizationRole $role = OrganizationRole::Owner,
): array {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    OrganizationMembership::factory()
        ->for($organization)
        ->for($user)
        ->create([
            'role' => $role,
        ]);

    $project = Project::factory()
        ->for($organization)
        ->create();

    return [$user, $organization, $project];
}

/**
 * Create one execution and its latest failed attempt for recovery display.
 */
function createRecoveryTestExecution(
    Project $project,
    ExecutionStatus $status,
    string $logicalRole,
    bool $retryable,
): Execution {
    $execution = Execution::factory()
        ->for($project)
        ->create([
            'capability' => 'development.simulation',
            'logical_role' => $logicalRole,
            'status' => $status,
            'attempt_count' => 1,
            'next_attempt_at' => $status === ExecutionStatus::RetryScheduled
                ? now()->addMinute()
                : null,
            'started_at' => now()->subMinute(),
            'finished_at' => $status === ExecutionStatus::RetryScheduled
                ? null
                : now(),
        ]);

    ExecutionAttempt::factory()
        ->for($execution)
        ->failed()
        ->create([
            'attempt_number' => 1,
            'retryable' => $retryable,
            'error_code' => match ($status) {
                ExecutionStatus::Blocked => 'policy.blocked',
                ExecutionStatus::RetryScheduled => 'provider.transient',
                default => 'provider.failed',
            },
            'error_message' => match ($status) {
                ExecutionStatus::Blocked => 'The execution requires a human decision.',
                ExecutionStatus::RetryScheduled => 'The provider failed transiently and will be retried.',
                default => 'The execution failed permanently.',
            },
        ]);

    return $execution;
}

/**
 * Create one project-scoped outbox dead letter using the existing schema.
 *
 * @param  array<string, mixed>  $overrides
 */
function createRecoveryTestDeadLetter(
    Project $project,
    Execution $execution,
    array $overrides = [],
): OutboxMessage {
    return OutboxMessage::query()->create(array_merge([
        'event_id' => (string) Str::ulid(),
        'event_name' => 'workflow.retry_scheduled',
        'aggregate_type' => 'execution',
        'aggregate_id' => $execution->id,
        'organization_id' => $project->organization_id,
        'project_id' => $project->id,
        'occurred_at' => now(),
        'correlation_id' => (string) Str::ulid(),
        'causation_id' => null,
        'execution_id' => $execution->id,
        'schema_version' => 1,
        'envelope' => [
            'payload' => [
                'execution_id' => $execution->id,
            ],
        ],
        'available_at' => now(),
        'dispatch_attempts' => 3,
        'last_error' => RuntimeException::class.': redacted',
        'dead_lettered_at' => now(),
        'replay_count' => 0,
        'created_at' => now(),
    ], $overrides));
}

it('shows blocked retry-scheduled and failed executions', function (): void {
    [$user, $organization, $project] = createRecoveryTestContext();

    createRecoveryTestExecution(
        project: $project,
        status: ExecutionStatus::Blocked,
        logicalRole: 'blocked_engineer',
        retryable: false,
    );

    createRecoveryTestExecution(
        project: $project,
        status: ExecutionStatus::RetryScheduled,
        logicalRole: 'retrying_engineer',
        retryable: true,
    );

    createRecoveryTestExecution(
        project: $project,
        status: ExecutionStatus::Failed,
        logicalRole: 'failed_engineer',
        retryable: false,
    );

    $this
        ->actingAs($user)
        ->get(route(
            'organizations.projects.operations.recovery.index',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->component('projects/operations/recovery')
                ->where('recovery.summary.blocked', 1)
                ->where('recovery.summary.retryScheduled', 1)
                ->where('recovery.summary.failed', 1)
                ->has('recovery.executions', 3)
                ->where(
                    'recovery.executions',
                    static fn (Collection $rows): bool => $rows
                        ->pluck('status')
                        ->sort()
                        ->values()
                        ->all() === [
                            ExecutionStatus::Blocked->value,
                            ExecutionStatus::Failed->value,
                            ExecutionStatus::RetryScheduled->value,
                        ],
                )
        );
});

it('lists only dead letters owned by the current project', function (): void {
    [$user, $organization, $project] = createRecoveryTestContext();

    $currentExecution = Execution::factory()
        ->for($project)
        ->create();

    $currentDeadLetter = createRecoveryTestDeadLetter(
        project: $project,
        execution: $currentExecution,
    );

    $otherProject = Project::factory()
        ->for($organization)
        ->create();

    $otherExecution = Execution::factory()
        ->for($otherProject)
        ->create();

    createRecoveryTestDeadLetter(
        project: $otherProject,
        execution: $otherExecution,
    );

    $otherOrganization = Organization::factory()->create();

    $foreignProject = Project::factory()
        ->for($otherOrganization)
        ->create();

    $foreignExecution = Execution::factory()
        ->for($foreignProject)
        ->create();

    createRecoveryTestDeadLetter(
        project: $foreignProject,
        execution: $foreignExecution,
    );

    $this
        ->actingAs($user)
        ->get(route(
            'organizations.projects.operations.recovery.index',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->where('recovery.summary.deadLetters', 1)
                ->has('recovery.deadLetters', 1)
                ->where(
                    'recovery.deadLetters.0.id',
                    $currentDeadLetter->event_id,
                )
        );
});

it('allows owners and administrators to replay project dead letters', function (
    string $role,
): void {
    [$user, $organization, $project] = createRecoveryTestContext(
        OrganizationRole::from($role),
    );

    $execution = Execution::factory()
        ->for($project)
        ->create();

    $outbox = createRecoveryTestDeadLetter(
        project: $project,
        execution: $execution,
    );

    $this
        ->actingAs($user)
        ->post(route(
            'organizations.projects.operations.recovery.replay',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ), [
            'source' => 'outbox',
            'identifier' => $outbox->event_id,
            'reason' => 'The transient dependency has recovered.',
        ])
        ->assertRedirect();

    $outbox->refresh();

    expect($outbox->dead_lettered_at)->toBeNull()
        ->and($outbox->dispatch_attempts)->toBe(0)
        ->and($outbox->replay_count)->toBe(1)
        ->and($outbox->last_replayed_at)->not->toBeNull();
})->with([
    'owner' => OrganizationRole::Owner->value,
    'administrator' => OrganizationRole::Administrator->value,
]);

it('forbids members and viewers from replaying project dead letters', function (
    string $role,
): void {
    [$user, $organization, $project] = createRecoveryTestContext(
        OrganizationRole::from($role),
    );

    $execution = Execution::factory()
        ->for($project)
        ->create();

    $outbox = createRecoveryTestDeadLetter(
        project: $project,
        execution: $execution,
    );

    $this
        ->actingAs($user)
        ->post(route(
            'organizations.projects.operations.recovery.replay',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ), [
            'source' => 'outbox',
            'identifier' => $outbox->event_id,
            'reason' => 'The transient dependency has recovered.',
        ])
        ->assertForbidden();

    $outbox->refresh();

    expect($outbox->dead_lettered_at)->not->toBeNull()
        ->and($outbox->dispatch_attempts)->toBe(3)
        ->and($outbox->replay_count)->toBe(0)
        ->and($outbox->last_replayed_at)->toBeNull();
})->with([
    'member' => OrganizationRole::Member->value,
    'viewer' => OrganizationRole::Viewer->value,
]);

it('returns not found for another project dead-letter identifier', function (): void {
    [$user, $organization, $project] = createRecoveryTestContext();

    $otherProject = Project::factory()
        ->for($organization)
        ->create();

    $otherExecution = Execution::factory()
        ->for($otherProject)
        ->create();

    $otherDeadLetter = createRecoveryTestDeadLetter(
        project: $otherProject,
        execution: $otherExecution,
    );

    $this
        ->actingAs($user)
        ->post(route(
            'organizations.projects.operations.recovery.replay',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ), [
            'source' => 'outbox',
            'identifier' => $otherDeadLetter->event_id,
            'reason' => 'The transient dependency has recovered.',
        ])
        ->assertNotFound();

    $otherDeadLetter->refresh();

    expect($otherDeadLetter->dead_lettered_at)->not->toBeNull()
        ->and($otherDeadLetter->replay_count)->toBe(0);
});

it('does not replay the same outbox record twice', function (): void {
    [$user, $organization, $project] = createRecoveryTestContext();

    $execution = Execution::factory()
        ->for($project)
        ->create();

    $outbox = createRecoveryTestDeadLetter(
        project: $project,
        execution: $execution,
    );

    $payload = [
        'source' => 'outbox',
        'identifier' => $outbox->event_id,
        'reason' => 'The transient dependency has recovered.',
    ];

    $route = route(
        'organizations.projects.operations.recovery.replay',
        [
            'organization' => $organization,
            'project' => $project,
        ],
    );

    $this
        ->actingAs($user)
        ->post($route, $payload)
        ->assertRedirect();

    /*
     * The first replay removes the row from the dead-letter set. A second
     * replay therefore fails closed as a missing recovery resource.
     */
    $this
        ->actingAs($user)
        ->post($route, $payload)
        ->assertNotFound();

    $outbox->refresh();

    expect($outbox->replay_count)->toBe(1)
        ->and($outbox->dead_lettered_at)->toBeNull()
        ->and(OutboxMessage::query()
            ->where('event_id', $outbox->event_id)
            ->count())
        ->toBe(1)
        ->and(AuditEvent::query()
            ->where(
                'event_type',
                AuditEventType::DeadLetterReplayRequested->value,
            )
            ->where('subject_id', $outbox->event_id)
            ->count())
        ->toBe(1);
});

it('redacts the replay reason and records it in the audit trail', function (): void {
    [$user, $organization, $project] = createRecoveryTestContext();

    $execution = Execution::factory()
        ->for($project)
        ->create();

    $outbox = createRecoveryTestDeadLetter(
        project: $project,
        execution: $execution,
    );

    $this
        ->actingAs($user)
        ->post(route(
            'organizations.projects.operations.recovery.replay',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ), [
            'source' => 'outbox',
            'identifier' => $outbox->event_id,
            'reason' => 'Retry after api_key=super-secret and https://internal.example.test/path recovered.',
        ])
        ->assertRedirect();

    $audit = AuditEvent::query()
        ->where('organization_id', $organization->id)
        ->where('project_id', $project->id)
        ->where(
            'event_type',
            AuditEventType::DeadLetterReplayRequested->value,
        )
        ->where('subject_id', $outbox->event_id)
        ->sole();

    expect($audit->actor_id)->toBe((string) $user->id)
        ->and($audit->metadata['source'] ?? null)->toBe('outbox')
        ->and($audit->metadata['reason'] ?? null)
        ->toBe(
            'Retry after [redacted-secret] and [redacted-url] recovered.',
        )
        ->and(json_encode($audit->metadata, JSON_THROW_ON_ERROR))
        ->not->toContain('super-secret')
        ->not->toContain('internal.example.test');
});

it('does not expose raw outbox envelopes or exception messages', function (): void {
    [$user, $organization, $project] = createRecoveryTestContext();

    $execution = Execution::factory()
        ->for($project)
        ->create();

    $outbox = createRecoveryTestDeadLetter(
        project: $project,
        execution: $execution,
        overrides: [
            'envelope' => [
                'payload' => [
                    'execution_id' => $execution->id,
                    'raw_secret' => 'envelope-secret-value',
                ],
            ],
            'last_error' => RuntimeException::class.': exception-secret-value',
        ],
    );

    $response = $this
        ->actingAs($user)
        ->get(route(
            'organizations.projects.operations.recovery.index',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ));

    $response
        ->assertOk()
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->has(
                    'recovery.deadLetters.0',
                    fn (Assert $deadLetter): Assert => $deadLetter
                        ->where('source', 'outbox')
                        ->where('id', $outbox->event_id)
                        ->where('eventId', $outbox->event_id)
                        ->where('eventName', 'workflow.retry_scheduled')
                        ->where('attempts', 3)
                        ->where('errorType', RuntimeException::class)
                        ->has('failedAt')
                        ->missing('envelope')
                        ->missing('lastError')
                        ->missing('last_error')
                        ->missing('exception')
                        ->missing('exceptionMessage')
                        ->etc()
                )
        )
        ->assertDontSee('envelope-secret-value', false)
        ->assertDontSee('exception-secret-value', false);
});
