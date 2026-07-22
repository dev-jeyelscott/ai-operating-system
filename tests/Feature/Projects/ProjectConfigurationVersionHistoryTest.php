<?php

declare(strict_types=1);

use App\Application\Audit\Contracts\AuditEventRepository;
use App\Application\Projects\CreateProject;
use App\Application\Projects\SaveProjectSetupStep;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Projects\ProjectSetupStep;
use App\Domain\Projects\ProjectType;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProjectConfiguration;
use App\Models\ProjectConfigurationVersion;
use App\Models\User;
use Mockery\MockInterface;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->organization = Organization::factory()->create();

    OrganizationMembership::factory()
        ->for($this->organization)
        ->for($this->user)
        ->owner()
        ->create();

    /*
     * Use the application command rather than directly creating models so the
     * fixture exercises the production configuration-history boundary.
     */
    $this->project = app(CreateProject::class)->handle(
        actorUserId: $this->user->id,
        organizationId: $this->organization->id,
        name: 'Configuration History Project',
        description: 'Exercises immutable configuration history.',
        projectType: ProjectType::WebApplication,
        correlationId: 'test-project-configuration-history',
    );
});

test('project creation records immutable revision one', function (): void {
    $configuration = $this->project
        ->configuration()
        ->firstOrFail();

    $version = $this->project
        ->configurationVersions()
        ->sole();

    expect($version->revision)
        ->toBe(1)
        ->and($version->schema_version)
        ->toBe($configuration->schema_version)
        ->and($version->actor_type)
        ->toBe(AuditActorType::User)
        ->and($version->actor_id)
        ->toBe((string) $this->user->id)
        ->and($version->change_reason)
        ->toBe('project.created');

    /*
    * PostgreSQL jsonb preserves the JSON value but does not preserve object-key
    * ordering. Compare the two snapshots as JSON documents instead of requiring
    * identical PHP associative-array insertion order.
    */
    $this->assertJsonStringEqualsJsonString(
        json_encode(
            $configuration->toVersionedArray(),
            JSON_THROW_ON_ERROR,
        ),
        json_encode(
            $version->snapshot,
            JSON_THROW_ON_ERROR,
        ),
    );

    expect(
        $this->project
            ->latestConfigurationVersion()
            ->firstOrFail()
            ->is($version),
    )->toBeTrue();

    $this->assertDatabaseHas('audit_events', [
        'project_id' => $this->project->id,
        'actor_type' => AuditActorType::User->value,
        'actor_id' => (string) $this->user->id,
        'event_type' => AuditEventType::ProjectConfigurationVersionCreated->value,
        'subject_type' => AuditSubjectType::ProjectConfigurationVersion->value,
        'subject_id' => (string) $version->id,
    ]);
});

test('material configuration updates append the next revision', function (): void {
    $baseline = $this->project
        ->configurationVersions()
        ->where('revision', 1)
        ->firstOrFail();

    $baselineSnapshot = $baseline->snapshot;

    app(SaveProjectSetupStep::class)->handle(
        actorUserId: $this->user->id,
        organizationId: $this->organization->id,
        projectId: $this->project->id,
        step: ProjectSetupStep::Details,
        payload: projectConfigurationVersionTechnologyStackPayload(),
        correlationId: 'test-material-configuration-update',
    );

    $configuration = ProjectConfiguration::query()
        ->where('project_id', $this->project->id)
        ->firstOrFail();

    $updatedVersion = ProjectConfigurationVersion::query()
        ->where('project_id', $this->project->id)
        ->where('revision', 2)
        ->firstOrFail();

    expect(
        $this->project->configurationVersions()->count(),
    )
        ->toBe(2)
        ->and($configuration->revision)
        ->toBe(2)
        ->and($updatedVersion->actor_type)
        ->toBe(AuditActorType::User)
        ->and($updatedVersion->actor_id)
        ->toBe((string) $this->user->id)
        ->and($updatedVersion->change_reason)
        ->toBe('project_setup.details');

    /*
    * The snapshot is stored as PostgreSQL jsonb, so object-key order is not part
    * of the persisted contract. JSON comparison still verifies nested values,
    * scalar types, and array contents.
    */
    $this->assertJsonStringEqualsJsonString(
        json_encode(
            $configuration->toVersionedArray(),
            JSON_THROW_ON_ERROR,
        ),
        json_encode(
            $updatedVersion->snapshot,
            JSON_THROW_ON_ERROR,
        ),
    );
});

test('identical normalized updates do not create duplicate versions', function (): void {
    $payload = projectConfigurationVersionTechnologyStackPayload();

    $saveProjectSetupStep = app(SaveProjectSetupStep::class);

    $saveProjectSetupStep->handle(
        actorUserId: $this->user->id,
        organizationId: $this->organization->id,
        projectId: $this->project->id,
        step: ProjectSetupStep::Details,
        payload: $payload,
        correlationId: 'test-first-identical-update',
    );

    $saveProjectSetupStep->handle(
        actorUserId: $this->user->id,
        organizationId: $this->organization->id,
        projectId: $this->project->id,
        step: ProjectSetupStep::Details,
        payload: $payload,
        correlationId: 'test-repeated-identical-update',
    );

    expect(
        $this->project->configurationVersions()->pluck('revision')->all(),
    )->toBe([1, 2]);

    expect(
        AuditEvent::query()
            ->where('project_id', $this->project->id)
            ->where(
                'event_type',
                AuditEventType::ProjectConfigurationVersionCreated,
            )
            ->count(),
    )->toBe(2);
});

test('history rows reject application updates and deletion', function (): void {
    $version = $this->project
        ->configurationVersions()
        ->firstOrFail();

    $version->change_reason = 'tampered';

    expect(
        fn (): bool => $version->save(),
    )->toThrow(
        LogicException::class,
        'Project configuration versions are immutable.',
    );

    expect(
        fn (): ?bool => $version->delete(),
    )->toThrow(
        LogicException::class,
        'Project configuration versions cannot be deleted.',
    );
});

test('an audit failure rolls back the configuration and its new version', function (): void {
    /*
     * Replace the audit adapter after the initial project fixture exists.
     * The next history write will fail before either transaction can commit.
     */
    $this->mock(
        AuditEventRepository::class,
        function (MockInterface $mock): void {
            $mock->shouldReceive('append')
                ->once()
                ->andThrow(new RuntimeException('Audit persistence failed.'));
        },
    );

    expect(
        fn () => app(SaveProjectSetupStep::class)->handle(
            actorUserId: $this->user->id,
            organizationId: $this->organization->id,
            projectId: $this->project->id,
            step: ProjectSetupStep::Details,
            payload: projectConfigurationVersionTechnologyStackPayload(),
            correlationId: 'test-transaction-rollback',
        ),
    )->toThrow(
        RuntimeException::class,
        'Audit persistence failed.',
    );

    $configuration = ProjectConfiguration::query()
        ->where('project_id', $this->project->id)
        ->firstOrFail();

    expect($configuration->revision)
        ->toBe(1)
        ->and($this->project->configurationVersions()->count())
        ->toBe(1)
        ->and(
            $this->project
                ->setupProgress()
                ->firstOrFail()
                ->completed_steps,
        )
        ->toBe([]);
});

/**
 * Return one normalized details-step payload for history tests.
 *
 * @return array{
 *     technology_stack: array{
 *         languages: list<string>,
 *         frameworks: list<string>,
 *         databases: list<string>,
 *         infrastructure: list<string>,
 *         package_managers: list<string>,
 *         runtimes: list<string>
 *     }
 * }
 */
function projectConfigurationVersionTechnologyStackPayload(): array
{
    return [
        'technology_stack' => [
            'languages' => [
                'PHP',
                'TypeScript',
            ],
            'frameworks' => [
                'Laravel 13',
                'Inertia.js 3',
                'React',
            ],
            'databases' => [
                'PostgreSQL',
                'Redis',
            ],
            'infrastructure' => [
                'Docker Compose',
                'GitHub Actions',
            ],
            'package_managers' => [
                'Composer',
                'pnpm',
            ],
            'runtimes' => [
                'PHP 8.5',
                'Node.js 22',
            ],
        ],
    ];
}
