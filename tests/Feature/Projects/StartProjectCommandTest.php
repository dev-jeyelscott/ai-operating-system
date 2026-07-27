<?php

declare(strict_types=1);

use App\Application\Audit\Data\AuditContext;
use App\Application\Documents\ReviewDocumentVersion;
use App\Application\Events\Contracts\DomainEventOutbox;
use App\Application\Projects\BuildStartProjectCommand;
use App\Application\Projects\Commands\StartProject;
use App\Application\Projects\Handlers\StartProjectHandler;
use App\Application\Shared\Commands\CommandBus;
use App\Application\Shared\Commands\CommandResultStatus;
use App\Domain\Audit\AuditActorType;
use App\Domain\Events\DomainEventEnvelope;
use App\Domain\Idempotency\IdempotencyKeyStatus;
use App\Domain\Identity\OrganizationRole;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Integrations\NotionConnectionStatus;
use App\Domain\Projects\ProjectSetupStep;
use App\Domain\Projects\ProjectStatus;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Execution;
use App\Models\IdempotencyKey;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectConfigurationVersion;
use App\Models\ProjectContextSnapshot;
use App\Models\ProjectIntegration;
use App\Models\ProjectSetupProgress;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Models\WorkflowInstance;
use Database\Seeders\ProjectDeliveryWorkflowSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Http::preventStrayRequests();

    Storage::fake(
        (string) config('filesystems.artifact'),
    );

    $this->seed(ProjectDeliveryWorkflowSeeder::class);
});

test(
    'it starts one project and replays the original result',
    function (): void {
        $fixture = startProjectCommandFixture();

        $command = app(
            BuildStartProjectCommand::class,
        )->handle(
            organizationId: $fixture['organization']->id,
            projectId: $fixture['project']->id,
            requestedByUserId: $fixture['owner']->id,
            idempotencyKey: 'start-project-command-001',
            correlationId: (string) Str::ulid(),
        );

        $bus = app(CommandBus::class);

        $first = $bus->dispatch($command);
        $second = $bus->dispatch($command);

        expect($first->status)
            ->toBe(CommandResultStatus::Succeeded)
            ->and($second->toArray())
            ->toBe($first->toArray());

        $execution = Execution::query()->sole();
        $snapshot = ProjectContextSnapshot::query()->sole();
        $workflow = WorkflowInstance::query()->sole();

        expect($execution->id)
            ->toBe($first->data['execution_id'])
            ->and($execution->project_context_snapshot_id)
            ->toBe($snapshot->id)
            ->and($execution->workflow_instance_id)
            ->toBe($workflow->id)
            ->and($execution->capability)
            ->toBe('planning.roadmap')
            ->and($execution->status->value)
            ->toBe('queued')
            ->and($fixture['project']->refresh()->status)
            ->toBe(ProjectStatus::Planning);

        $this->assertDatabaseCount(
            'project_context_snapshots',
            1,
        );

        $this->assertDatabaseCount(
            'workflow_instances',
            1,
        );

        $this->assertDatabaseCount(
            'executions',
            1,
        );

        $this->assertDatabaseCount(
            'outbox_messages',
            2,
        );

        $this->assertDatabaseCount(
            'notification_events',
            1,
        );

        $this->assertDatabaseCount(
            'notification_recipients',
            1,
        );

        expect(
            AuditEvent::query()
                ->where(
                    'event_type',
                    'project.context_snapshot.created',
                )
                ->count(),
        )->toBe(1)
            ->and(
                AuditEvent::query()
                    ->where(
                        'event_type',
                        'project.start_requested',
                    )
                    ->count(),
            )->toBe(1);

        Http::assertNothingSent();
    },
);

test(
    'it rejects reuse of a key for another context fingerprint',
    function (): void {
        $fixture = startProjectCommandFixture();

        $command = app(
            BuildStartProjectCommand::class,
        )->handle(
            organizationId: $fixture['organization']->id,
            projectId: $fixture['project']->id,
            requestedByUserId: $fixture['owner']->id,
            idempotencyKey: 'start-project-context-conflict',
            correlationId: (string) Str::ulid(),
        );

        $bus = app(CommandBus::class);

        expect($bus->dispatch($command)->isSuccessful())
            ->toBeTrue();

        $conflicting = new StartProject(
            organizationId: $command->organizationId,
            projectId: $command->projectId,
            requestedByUserId: $command->requestedByUserId,
            contextFingerprint: str_repeat('a', 64),
            requestIdempotencyKey: $command->requestIdempotencyKey,
            correlationId: (string) Str::ulid(),
        );

        $result = $bus->dispatch($conflicting);

        expect($result->status)
            ->toBe(CommandResultStatus::Conflict);

        $this->assertDatabaseCount('executions', 1);
        $this->assertDatabaseCount('workflow_instances', 1);
        $this->assertDatabaseCount(
            'project_context_snapshots',
            1,
        );
    },
);

test(
    'it returns preflight validation without creating state',
    function (): void {
        $fixture = startProjectCommandFixture(
            projectStatus: ProjectStatus::Configuring,
        );

        $command = app(
            BuildStartProjectCommand::class,
        )->handle(
            organizationId: $fixture['organization']->id,
            projectId: $fixture['project']->id,
            requestedByUserId: $fixture['owner']->id,
            idempotencyKey: 'start-project-invalid-preflight',
            correlationId: (string) Str::ulid(),
        );

        $result = app(CommandBus::class)
            ->dispatch($command);

        expect($result->status)
            ->toBe(CommandResultStatus::ValidationFailed)
            ->and($result->details['fields'])
            ->toHaveKey('preflight.project.status');

        $this->assertDatabaseCount(
            'project_context_snapshots',
            0,
        );

        $this->assertDatabaseCount(
            'workflow_instances',
            0,
        );

        $this->assertDatabaseCount(
            'executions',
            0,
        );

        $this->assertDatabaseCount(
            'outbox_messages',
            0,
        );

        $this->assertDatabaseCount(
            'notification_events',
            0,
        );
    },
);

test(
    'it conflicts when an approved document is replaced after StartProject is prepared',
    function (): void {
        $fixture = startProjectCommandFixture();

        $command = app(BuildStartProjectCommand::class)->handle(
            organizationId: $fixture['organization']->id,
            projectId: $fixture['project']->id,
            requestedByUserId: $fixture['owner']->id,
            idempotencyKey: 'start-project-stale-document-context',
            correlationId: (string) Str::ulid(),
        );

        $approved = DocumentVersion::query()
            ->whereHas(
                'document',
                fn ($query) => $query->where(
                    'project_id',
                    $fixture['project']->id,
                ),
            )
            ->where('status', 'approved')
            ->firstOrFail();

        $replacement = DocumentVersion::factory()
            ->for($approved->document)
            ->classified()
            ->create([
                'version' => $approved->version + 1,
                'supersedes_document_version_id' => $approved->id,
            ]);

        app(ReviewDocumentVersion::class)->approve(
            document: $approved->document,
            version: $replacement,
            auditContext: AuditContext::user(
                userId: $fixture['owner']->id,
                correlationId: (string) Str::ulid(),
            ),
        );

        $result = app(CommandBus::class)->dispatch($command);

        expect($result->status)
            ->toBe(CommandResultStatus::Conflict)
            ->and($result->details['reason'])
            ->toBe('project_context_changed');

        $this->assertDatabaseCount('workflow_instances', 0);
        $this->assertDatabaseCount('executions', 0);
        $this->assertDatabaseCount('project_context_snapshots', 0);
        $this->assertDatabaseCount('notification_events', 0);
        $this->assertDatabaseCount('notification_recipients', 0);
    },
);

test(
    'it reconciles a committed StartProject result after idempotency completion is interrupted',
    function (): void {
        $fixture = startProjectCommandFixture();

        $command = app(BuildStartProjectCommand::class)->handle(
            organizationId: $fixture['organization']->id,
            projectId: $fixture['project']->id,
            requestedByUserId: $fixture['owner']->id,
            idempotencyKey: 'start-project-interrupted-idempotency-completion',
            correlationId: (string) Str::ulid(),
        );

        $committed = app(StartProjectHandler::class)->handle($command);

        /*
         * Simulate the durable claim left behind when the process dies after
         * the business transaction commits and before result completion.
         */
        IdempotencyKey::query()->create([
            'scope' => $command->idempotencyScope(),
            'key_hash' => hash('sha256', $command->idempotencyKey()),
            'command_class' => $command::class,
            'request_fingerprint' => hash(
                'sha256',
                json_encode([
                    'context_fingerprint' => $command->contextFingerprint,
                    'organization_id' => $command->organizationId,
                    'project_id' => $command->projectId,
                    'requested_by_user_id' => $command->requestedByUserId,
                ], JSON_THROW_ON_ERROR),
            ),
            'status' => IdempotencyKeyStatus::Processing,
            'result_status' => null,
            'result_payload' => null,
            'lock_owner' => (string) Str::uuid(),
            'lock_expires_at' => now()->subSecond(),
            'completed_at' => null,
            'expires_at' => null,
        ]);

        $retried = app(CommandBus::class)->dispatch($command);

        expect($committed->isSuccessful())
            ->toBeTrue()
            ->and($retried->toArray())
            ->toBe($committed->toArray());

        $this->assertDatabaseCount('workflow_instances', 1);
        $this->assertDatabaseCount('executions', 1);
        $this->assertDatabaseCount('project_context_snapshots', 1);
        $this->assertDatabaseCount('notification_events', 1);
        $this->assertDatabaseCount('notification_recipients', 1);
        expect(IdempotencyKey::query()->sole()->status)
            ->toBe(IdempotencyKeyStatus::Completed);
    },
);

test(
    'it rejects a member without project approval permission',
    function (): void {
        $fixture = startProjectCommandFixture();

        $member = User::factory()->create();

        OrganizationMembership::factory()->create([
            'organization_id' => $fixture['organization']->id,
            'user_id' => $member->id,
            'role' => OrganizationRole::Member,
        ]);

        $command = app(
            BuildStartProjectCommand::class,
        )->handle(
            organizationId: $fixture['organization']->id,
            projectId: $fixture['project']->id,
            requestedByUserId: $member->id,
            idempotencyKey: 'start-project-unauthorized-member',
            correlationId: (string) Str::ulid(),
        );

        expect(
            fn () => app(CommandBus::class)
                ->dispatch($command),
        )->toThrow(AuthorizationException::class);

        $this->assertDatabaseCount(
            'project_context_snapshots',
            0,
        );

        $this->assertDatabaseCount(
            'workflow_instances',
            0,
        );

        $this->assertDatabaseCount(
            'executions',
            0,
        );
    },
);

test(
    'it rolls back authoritative records when outbox append fails',
    function (): void {
        $fixture = startProjectCommandFixture();

        $this->app->bind(
            DomainEventOutbox::class,
            static fn (): DomainEventOutbox => new class implements DomainEventOutbox
            {
                /**
                 * Simulate unavailable transactional outbox storage.
                 */
                public function append(
                    DomainEventEnvelope $event,
                ): void {
                    throw new RuntimeException(
                        'Simulated outbox failure.',
                    );
                }
            },
        );

        $command = app(
            BuildStartProjectCommand::class,
        )->handle(
            organizationId: $fixture['organization']->id,
            projectId: $fixture['project']->id,
            requestedByUserId: $fixture['owner']->id,
            idempotencyKey: 'start-project-outbox-failure',
            correlationId: (string) Str::ulid(),
        );

        expect(
            fn () => app(CommandBus::class)
                ->dispatch($command),
        )->toThrow(
            RuntimeException::class,
            'Simulated outbox failure.',
        );

        expect($fixture['project']->refresh()->status)
            ->toBe(ProjectStatus::ReadyForPlanning);

        $this->assertDatabaseCount(
            'project_context_snapshots',
            0,
        );

        $this->assertDatabaseCount(
            'workflow_instances',
            0,
        );

        $this->assertDatabaseCount(
            'executions',
            0,
        );

        $this->assertDatabaseCount(
            'outbox_messages',
            0,
        );

        $this->assertDatabaseCount(
            'notification_events',
            0,
        );

        $this->assertDatabaseCount(
            'notification_recipients',
            0,
        );
    },
);

/**
 * Create one complete tenant-scoped StartProject test fixture.
 *
 * @return array{
 *     owner: User,
 *     organization: Organization,
 *     project: Project,
 *     configuration: ProjectConfiguration
 * }
 */
function startProjectCommandFixture(
    ProjectStatus $projectStatus =
    ProjectStatus::ReadyForPlanning,
): array {
    $owner = User::factory()->create();
    $organization = Organization::factory()->create();

    OrganizationMembership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role' => OrganizationRole::Owner,
    ]);

    $project = Project::factory()
        ->for($organization)
        ->create([
            'status' => $projectStatus,
            'status_changed_at' => now(),
        ]);

    $configuration = ProjectConfiguration::factory()
        ->complete()
        ->for($project)
        ->create();

    ProjectConfigurationVersion::query()->create([
        'project_id' => $project->id,
        'schema_version' => $configuration->schema_version,
        'revision' => $configuration->revision,
        'actor_type' => AuditActorType::System,
        'actor_id' => 'start-project-command-test',
        'change_reason' => 'test_fixture',
        'snapshot' => $configuration->toVersionedArray(),
        'created_at' => now(),
    ]);

    foreach (
        $configuration->required_documents as $documentClass
    ) {
        $document = Document::factory()
            ->for($project)
            ->create([
                'document_class' => $documentClass,
                'title' => Str::headline(
                    $documentClass,
                ),
            ]);

        DocumentVersion::factory()
            ->for($document)
            ->approved()
            ->create();
    }

    ProjectSetupProgress::query()->create([
        'project_id' => $project->id,
        'current_step' => ProjectSetupStep::Review,
        'completed_steps' => array_map(
            static fn (
                ProjectSetupStep $step,
            ): string => $step->value,
            ProjectSetupStep::ordered(),
        ),
        'completed_at' => now(),
    ]);

    $credential = ProviderCredential::query()->create([
        'organization_id' => $organization->id,
        'project_id' => $project->id,
        'provider' => IntegrationProvider::Notion,
        'secret_ciphertext' => 'encrypted-start-project-command-fixture',
        'version' => 1,
        'created_by_user_id' => $owner->id,
        'last_rotated_by_user_id' => null,
        'rotated_at' => null,
    ]);

    ProjectIntegration::query()->create([
        'organization_id' => $organization->id,
        'project_id' => $project->id,
        'provider' => IntegrationProvider::Notion,
        'workspace_id' => (string) Str::uuid(),
        'workspace_name' => 'AIOS Test Workspace',
        'database_id' => (string) Str::uuid(),
        'database_name' => 'AIOS Delivery Tracker',
        'data_source_id' => (string) Str::uuid(),
        'data_source_name' => 'AIOS Delivery Tracker Data Source',
        'connection_status' => NotionConnectionStatus::Connected,
        'last_failure_code' => null,
        'last_provider_request_id' => 'request-aios-063',
        'last_tested_by_user_id' => $owner->id,
        'last_tested_at' => now(),
        'last_connected_at' => now(),
        'verified_credential_version' => $credential->version,
    ]);

    return [
        'owner' => $owner,
        'organization' => $organization,
        'project' => $project,
        'configuration' => $configuration,
    ];
}
