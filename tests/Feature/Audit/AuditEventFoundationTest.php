<?php

declare(strict_types=1);

use App\Application\Audit\Contracts\AuditEventRepository;
use App\Application\Audit\Data\AuditEventData;
use App\Application\Audit\RecordAuditEvent;
use App\Application\Projects\CreateProject;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Identity\OrganizationRole;
use App\Domain\Projects\ProjectType;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

test('organization creation appends organization and owner membership audit events', function () {
    $user = User::factory()->create();

    $this
        ->withHeader('X-Request-ID', 'audit-org-create-001')
        ->actingAs($user)
        ->post(route('organizations.store'), [
            'name' => 'Audited Organization',
        ])
        ->assertRedirect();

    $organization = Organization::query()->sole();
    $membership = OrganizationMembership::query()->sole();

    $events = AuditEvent::query()
        ->orderBy('sequence')
        ->get();

    expect($events)
        ->toHaveCount(2)
        ->and($events[0]->event_type)
        ->toBe(AuditEventType::OrganizationCreated)
        ->and($events[0]->organization_id)
        ->toBe($organization->id)
        ->and($events[0]->actor_type)
        ->toBe(AuditActorType::User)
        ->and($events[0]->actor_id)
        ->toBe((string) $user->id)
        ->and($events[0]->subject_type)
        ->toBe(AuditSubjectType::Organization)
        ->and($events[0]->subject_id)
        ->toBe((string) $organization->id)
        ->and($events[0]->correlation_id)
        ->toBe('audit-org-create-001')
        ->and($events[1]->event_type)
        ->toBe(AuditEventType::OrganizationMemberAdded)
        ->and($events[1]->subject_type)
        ->toBe(AuditSubjectType::OrganizationMembership)
        ->and($events[1]->subject_id)
        ->toBe((string) $membership->id)
        ->and($events[1]->metadata['member_user_id'])
        ->toBe($user->id)
        ->and($events[1]->metadata['role'])
        ->toBe(OrganizationRole::Owner->value);
});

test('project commands append tenant scoped audit events in sequence order', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    OrganizationMembership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => OrganizationRole::Owner,
    ]);

    $this
        ->withHeader('X-Request-ID', 'audit-project-create-001')
        ->actingAs($user)
        ->post(
            route('organizations.projects.store', [
                'organization' => $organization,
            ]),
            [
                'name' => 'Audited Project',
                'description' => 'This content must not be copied into audit metadata.',
                'project_type' => ProjectType::WebApplication->value,
            ],
        )
        ->assertRedirect();

    $project = Project::query()->sole();

    $this
        ->withHeader('X-Request-ID', 'audit-project-update-001')
        ->actingAs($user)
        ->put(
            route('organizations.projects.update', [
                'organization' => $organization,
                'project' => $project,
            ]),
            [
                'name' => 'Updated Audited Project',
                'description' => 'Updated private description.',
                'project_type' => ProjectType::Api->value,
            ],
        )
        ->assertRedirect();

    $this
        ->withHeader('X-Request-ID', 'audit-project-archive-001')
        ->actingAs($user)
        ->put(
            route('organizations.projects.archive', [
                'organization' => $organization,
                'project' => $project,
            ]),
        )
        ->assertRedirect();

    $this
        ->withHeader('X-Request-ID', 'audit-project-restore-001')
        ->actingAs($user)
        ->put(
            route('organizations.projects.restore', [
                'organization' => $organization,
                'project' => $project,
            ]),
        )
        ->assertRedirect();

    $events = AuditEvent::query()
        ->where('project_id', $project->id)
        ->orderBy('sequence')
        ->get();

    expect($events)
        ->toHaveCount(4)
        ->and($events->pluck('event_type')->all())
        ->toBe([
            AuditEventType::ProjectCreated,
            AuditEventType::ProjectUpdated,
            AuditEventType::ProjectArchived,
            AuditEventType::ProjectRestored,
        ])
        ->and($events->pluck('actor_id')->unique()->all())
        ->toBe([(string) $user->id])
        ->and($events->pluck('organization_id')->unique()->all())
        ->toBe([$organization->id])
        ->and($events->pluck('project_id')->unique()->all())
        ->toBe([$project->id]);

    $sequences = $events->pluck('sequence')->all();

    expect($sequences)
        ->toBe(
            collect($sequences)
                ->sort()
                ->values()
                ->all(),
        );

    expect($events[0]->metadata)
        ->not->toHaveKey('description')
        ->and($events[1]->metadata)
        ->not->toHaveKey('description');
});

test('unauthorized project commands do not create audit events', function () {
    $viewer = User::factory()->create();
    $organization = Organization::factory()->create();

    OrganizationMembership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $viewer->id,
        'role' => OrganizationRole::Viewer,
    ]);

    $this
        ->actingAs($viewer)
        ->post(
            route('organizations.projects.store', [
                'organization' => $organization,
            ]),
            [
                'name' => 'Forbidden Project',
                'description' => null,
                'project_type' => ProjectType::Api->value,
            ],
        )
        ->assertForbidden();

    $this->assertDatabaseCount('projects', 0);
    $this->assertDatabaseCount('audit_events', 0);
});

test('audit metadata is centrally redacted before persistence', function () {
    $organization = Organization::factory()->create();

    $event = app(RecordAuditEvent::class)->record(
        organizationId: $organization->id,
        projectId: null,
        actorType: AuditActorType::System,
        actorId: 'security-test',
        eventType: AuditEventType::OrganizationCreated,
        subjectType: AuditSubjectType::Organization,
        subjectId: (string) $organization->id,
        correlationId: 'audit-redaction-001',
        metadata: [
            'safe_value' => 'visible',
            'api_token' => 'must-not-persist',
            'nested' => [
                'password' => 'must-not-persist',
                'safe_nested_value' => 'visible',
            ],
        ],
    );

    $persisted = AuditEvent::query()
        ->where('event_id', $event->eventId)
        ->sole();

    expect($persisted->metadata)
        ->toMatchArray([
            'safe_value' => 'visible',
            'api_token' => '[REDACTED]',
            'nested' => [
                'password' => '[REDACTED]',
                'safe_nested_value' => 'visible',
            ],
        ]);
});

test('audit event updates are rejected by the database', function () {
    $organization = Organization::factory()->create();

    $event = app(RecordAuditEvent::class)->record(
        organizationId: $organization->id,
        projectId: null,
        actorType: AuditActorType::System,
        actorId: 'immutability-test',
        eventType: AuditEventType::OrganizationCreated,
        subjectType: AuditSubjectType::Organization,
        subjectId: (string) $organization->id,
    );

    expect(
        fn () => DB::table('audit_events')
            ->where('event_id', $event->eventId)
            ->update([
                'event_type' => AuditEventType::ProjectCreated->value,
            ]),
    )->toThrow(
        QueryException::class,
        'audit_events is append-only',
    );
});

test('audit event deletion is rejected by the database', function () {
    $organization = Organization::factory()->create();

    $event = app(RecordAuditEvent::class)->record(
        organizationId: $organization->id,
        projectId: null,
        actorType: AuditActorType::System,
        actorId: 'immutability-test',
        eventType: AuditEventType::OrganizationCreated,
        subjectType: AuditSubjectType::Organization,
        subjectId: (string) $organization->id,
    );

    expect(
        fn () => DB::table('audit_events')
            ->where('event_id', $event->eventId)
            ->delete(),
    )->toThrow(
        QueryException::class,
        'audit_events is append-only',
    );
});

test('a failed audit append rolls back the privileged business mutation', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    $this->app->bind(
        AuditEventRepository::class,
        static fn (): AuditEventRepository => new class implements AuditEventRepository
        {
            /**
             * Simulate an unavailable or failed audit persistence operation.
             */
            public function append(AuditEventData $event): void
            {
                throw new RuntimeException('Simulated audit write failure.');
            }
        },
    );

    expect(
        fn () => app(CreateProject::class)->handle(
            actorUserId: $user->id,
            organizationId: $organization->id,
            name: 'Must Roll Back',
            description: null,
            projectType: ProjectType::Api,
            correlationId: 'audit-rollback-001',
        ),
    )->toThrow(
        RuntimeException::class,
        'Simulated audit write failure.',
    );

    $this->assertDatabaseMissing('projects', [
        'organization_id' => $organization->id,
        'name' => 'Must Roll Back',
    ]);

    $this->assertDatabaseCount('audit_events', 0);
});
