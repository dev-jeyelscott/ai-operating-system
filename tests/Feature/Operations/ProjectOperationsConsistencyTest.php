<?php

declare(strict_types=1);

use App\Application\Events\Data\StoredDomainEvent;
use App\Application\Operations\BuildOfficeProjection;
use App\Application\Operations\Consumers\RefreshOfficeProjection;
use App\Application\Operations\GetProjectOperationsReadModel;
use App\Domain\Audit\AuditEventType;
use App\Models\Execution;
use App\Models\OfficeProjection;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OutboxMessage;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Create one organization owner and project for consistency tests.
 *
 * @return array{0: User, 1: Organization, 2: Project}
 */
function createConsistencyTestContext(): array
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

/**
 * Create one durable project event used as an office-projection checkpoint.
 */
function createConsistencyTestEvent(
    Project $project,
    ?Execution $execution = null,
): OutboxMessage {
    return OutboxMessage::query()->create([
        'event_id' => (string) Str::ulid(),
        'event_name' => AuditEventType::ExecutionAttemptStarted->value,
        'aggregate_type' => 'execution',
        'aggregate_id' => $execution?->id ?? (string) $project->id,
        'organization_id' => $project->organization_id,
        'project_id' => $project->id,
        'occurred_at' => now(),
        'correlation_id' => (string) Str::ulid(),
        'causation_id' => null,
        'execution_id' => $execution?->id,
        'schema_version' => 1,
        'envelope' => [
            'payload' => [
                'execution_id' => $execution?->id,
            ],
        ],
        'published_at' => now(),
        'created_at' => now(),
    ]);
}

/**
 * Convert a persisted outbox message into the consumer input contract.
 */
function consistencyStoredEvent(
    OutboxMessage $message,
): StoredDomainEvent {
    return new StoredDomainEvent(
        eventId: $message->event_id,
        eventName: $message->event_name,
        organizationId: $message->organization_id,
        projectId: $message->project_id,
        schemaVersion: $message->schema_version,
        envelope: $message->envelope,
    );
}

it('keeps one stable office projection when the same event is replayed', function (): void {
    [, , $project] = createConsistencyTestContext();

    $execution = Execution::factory()
        ->for($project)
        ->running()
        ->create([
            'capability' => 'development.simulation',
            'logical_role' => 'backend_engineer',
        ]);

    $message = createConsistencyTestEvent(
        project: $project,
        execution: $execution,
    );

    $consumer = app(RefreshOfficeProjection::class);
    $event = consistencyStoredEvent($message);

    $consumer->handle($event);

    $first = OfficeProjection::query()
        ->forOrganization($project->organization_id)
        ->forProject($project->id)
        ->firstOrFail();

    $firstId = $first->id;
    $firstFingerprint = $first->fingerprint;

    $consumer->handle($event);

    $second = OfficeProjection::query()
        ->forOrganization($project->organization_id)
        ->forProject($project->id)
        ->firstOrFail();

    expect(OfficeProjection::query()
        ->forOrganization($project->organization_id)
        ->forProject($project->id)
        ->count())
        ->toBe(1)
        ->and($second->id)->toBe($firstId)
        ->and($second->fingerprint)->toBe($firstFingerprint)
        ->and($second->last_event_sequence)->toBe($message->sequence)
        ->and($second->last_event_id)->toBe($message->event_id);
});

it('keeps the business fingerprint stable across full browser refreshes', function (): void {
    [$user, $organization, $project] = createConsistencyTestContext();

    $firstFingerprint = null;
    $firstAsOf = null;
    $secondFingerprint = null;
    $secondAsOf = null;

    try {
        CarbonImmutable::setTestNow('2026-07-31 20:00:00');

        $first = $this
            ->actingAs($user)
            ->get(route(
                'organizations.projects.operations.index',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ));

        $first->assertOk()
            ->assertInertia(function (Assert $page) use (
                &$firstFingerprint,
                &$firstAsOf,
            ): Assert {
                return $page
                    ->component('projects/operations/index')
                    ->where(
                        'operations.metadata.fingerprint',
                        function (mixed $value) use (
                            &$firstFingerprint,
                        ): bool {
                            if (! is_string($value)) {
                                return false;
                            }

                            $firstFingerprint = $value;

                            return true;
                        },
                    )
                    ->where(
                        'operations.metadata.asOf',
                        function (mixed $value) use (&$firstAsOf): bool {
                            if (! is_string($value)) {
                                return false;
                            }

                            $firstAsOf = $value;

                            return true;
                        },
                    );
            });

        CarbonImmutable::setTestNow('2026-07-31 20:00:05');

        $second = $this
            ->actingAs($user)
            ->get(route(
                'organizations.projects.operations.index',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ));

        $second->assertOk()
            ->assertInertia(function (Assert $page) use (
                &$secondFingerprint,
                &$secondAsOf,
            ): Assert {
                return $page
                    ->component('projects/operations/index')
                    ->where(
                        'operations.metadata.fingerprint',
                        function (mixed $value) use (
                            &$secondFingerprint,
                        ): bool {
                            if (! is_string($value)) {
                                return false;
                            }

                            $secondFingerprint = $value;

                            return true;
                        },
                    )
                    ->where(
                        'operations.metadata.asOf',
                        function (mixed $value) use (&$secondAsOf): bool {
                            if (! is_string($value)) {
                                return false;
                            }

                            $secondAsOf = $value;

                            return true;
                        },
                    );
            });
    } finally {
        CarbonImmutable::setTestNow();
    }

    expect($firstFingerprint)->toBeString()
        ->and($secondFingerprint)->toBe($firstFingerprint)
        ->and($firstAsOf)->toBeString()
        ->and($secondAsOf)->toBeString()
        ->and($secondAsOf)->not->toBe($firstAsOf);
});

it('returns current operations after a missed real-time event', function (): void {
    [$user, $organization, $project] = createConsistencyTestContext();

    $response = $this
        ->actingAs($user)
        ->get(route(
            'organizations.projects.operations.index',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ));

    Execution::factory()
        ->for($project)
        ->running()
        ->create([
            'capability' => 'development.simulation',
            'logical_role' => 'backend_engineer',
        ]);

    /*
     * No broadcast or office-projection consumer is invoked. The Inertia
     * polling request must read current authoritative aggregate state.
     */
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('operations.summary.activeAgents', 0)
        ->reloadOnly(
            'operations',
            fn (Assert $reload): Assert => $reload
                ->component('projects/operations/index')
                ->where('operations.summary.activeAgents', 1)
        )
    );
});

it('rebuilds the office projection to the latest durable checkpoint', function (): void {
    [$user, $organization, $project] = createConsistencyTestContext();

    $execution = Execution::factory()
        ->for($project)
        ->running()
        ->create([
            'capability' => 'development.simulation',
            'logical_role' => 'backend_engineer',
        ]);

    $message = createConsistencyTestEvent(
        project: $project,
        execution: $execution,
    );

    app(BuildOfficeProjection::class)->handle(
        organizationId: $organization->id,
        projectId: $project->id,
        rebuilt: true,
    );

    $operations = app(GetProjectOperationsReadModel::class)->handle(
        organizationId: $organization->id,
        projectId: $project->id,
    );

    $this
        ->actingAs($user)
        ->getJson(route(
            'organizations.projects.operations.office-projection.show',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ))
        ->assertOk()
        ->assertJsonPath(
            'metadata.lastEventSequence',
            $message->sequence,
        )
        ->assertJsonPath(
            'metadata.lastEventId',
            $message->event_id,
        )
        ->assertJsonPath('project.id', $project->id)
        ->assertJsonPath(
            'summary.activeAgents',
            $operations['summary']['activeAgents'],
        )
        ->assertJsonPath(
            'summary.blockers',
            $operations['summary']['blockers'],
        )
        ->assertJsonPath(
            'summary.pendingApprovals',
            $operations['summary']['pendingApprovals'],
        )
        ->assertJsonPath(
            'summary.retriesScheduled',
            $operations['summary']['retriesScheduled'],
        );
});

it('conceals projection and recovery state across tenant boundaries', function (): void {
    [$user, $organization] = createConsistencyTestContext();

    $otherOrganization = Organization::factory()->create();

    $otherProject = Project::factory()
        ->for($otherOrganization)
        ->create();

    $this
        ->actingAs($user)
        ->getJson(route(
            'organizations.projects.operations.office-projection.show',
            [
                'organization' => $organization,
                'project' => $otherProject,
            ],
        ))
        ->assertNotFound();

    $this
        ->actingAs($user)
        ->get(route(
            'organizations.projects.operations.recovery.index',
            [
                'organization' => $organization,
                'project' => $otherProject,
            ],
        ))
        ->assertNotFound();
});

it('returns only the operations prop during an Inertia partial reload', function (): void {
    [$user, $organization, $project] = createConsistencyTestContext();

    $response = $this
        ->actingAs($user)
        ->get(route(
            'organizations.projects.operations.index',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ));

    $response->assertInertia(fn (Assert $page): Assert => $page
        ->component('projects/operations/index')
        ->has('organization')
        ->has('project')
        ->has('operations')
        ->reloadOnly(
            'operations',
            fn (Assert $reload): Assert => $reload
                ->component('projects/operations/index')
                ->has('operations')
                ->missing('organization')
                ->missing('project')
                ->missing('projectUrl')
                ->missing('approvalInboxUrl')
                ->missing('recoveryCenterUrl')
                ->missing('usageUrl')
                ->missing('officeProjectionUrl')
        )
    );
});
