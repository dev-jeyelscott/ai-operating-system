<?php

declare(strict_types=1);

use App\Application\Audit\Data\AuditContext;
use App\Application\Audit\RecordAuditEvent;
use App\Application\Documents\ReviewDocumentVersion;
use App\Application\Events\Contracts\DomainEventConsumerRegistry;
use App\Application\Events\Contracts\DomainEventOutbox;
use App\Application\Events\DeduplicatedDomainEventConsumer;
use App\Application\Integrations\Contracts\IntegrationCredentialCipher;
use App\Application\Integrations\Contracts\NotionPublicationClient;
use App\Application\Integrations\Data\NotionDataSource;
use App\Application\Integrations\Data\NotionPage;
use App\Application\Integrations\NotionPublicationException;
use App\Application\Planning\MaterializeRoadmap;
use App\Application\Planning\Notion\AcceptExternalNotionConflict;
use App\Application\Planning\Notion\DeferNotionReconciliationConflict;
use App\Application\Planning\Notion\NotionTicketFingerprint;
use App\Application\Planning\Notion\NotionTicketMapper;
use App\Application\Planning\Notion\ReconcileNotionRoadmap;
use App\Application\Planning\Notion\RetainInternalNotionConflict;
use App\Application\Planning\Notion\RetryFailedNotionPublication;
use App\Application\Planning\Notion\UpsertNotionTicket;
use App\Application\Planning\ProcessPlanningExecution;
use App\Application\Planning\RoadmapEligibility;
use App\Application\Projects\BuildStartProjectCommand;
use App\Application\Projects\Commands\StartProject;
use App\Application\Projects\Handlers\StartProjectHandler;
use App\Application\Shared\Commands\CommandBus;
use App\Application\Shared\Commands\CommandResultStatus;
use App\Domain\Audit\AuditActorType;
use App\Domain\Events\DomainEventEnvelope;
use App\Domain\Executions\ExecutionCapability;
use App\Domain\Idempotency\IdempotencyKeyStatus;
use App\Domain\Identity\OrganizationRole;
use App\Domain\Integrations\IntegrationCredentialSecret;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Integrations\NotionConnectionStatus;
use App\Domain\Projects\ProjectSetupStep;
use App\Domain\Projects\ProjectStatus;
use App\Jobs\ConsumeOutboxMessage;
use App\Jobs\ProcessPlanningExecutionJob;
use App\Jobs\RepublishNotionConflictJob;
use App\Jobs\RetryFailedNotionPublicationJob;
use App\Models\Approval;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Execution;
use App\Models\ExternalTicketMapping;
use App\Models\IdempotencyKey;
use App\Models\NotionReconciliationConflict;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OutboxMessage;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectConfigurationVersion;
use App\Models\ProjectContextSnapshot;
use App\Models\ProjectIntegration;
use App\Models\ProjectSetupProgress;
use App\Models\ProviderCredential;
use App\Models\Roadmap;
use App\Models\RoadmapTask;
use App\Models\User;
use App\Models\WorkflowInstance;
use Database\Seeders\ProjectDeliveryWorkflowSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fakes\InMemoryNotionPublicationClient;

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
            ->toBe(
                ExecutionCapability::PlanningGenerate->value,
            )
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

test('project start dispatches and completes planning from its immutable context exactly once', function (): void {
    Queue::fake();
    $fixture = startProjectCommandFixture();
    $command = app(BuildStartProjectCommand::class)->handle(
        organizationId: $fixture['organization']->id,
        projectId: $fixture['project']->id,
        requestedByUserId: $fixture['owner']->id,
        idempotencyKey: 'start-project-planning-dispatch',
        correlationId: (string) Str::ulid(),
    );

    expect(app(CommandBus::class)->dispatch($command)->isSuccessful())->toBeTrue();

    $execution = Execution::query()->sole();
    $startEvent = OutboxMessage::query()->where('event_name', 'project.start_requested')->sole();
    $consumerJob = new ConsumeOutboxMessage($startEvent->event_id);
    $consumerJob->handle(
        app(DomainEventConsumerRegistry::class),
        app(DeduplicatedDomainEventConsumer::class),
    );
    $consumerJob->handle(
        app(DomainEventConsumerRegistry::class),
        app(DeduplicatedDomainEventConsumer::class),
    );

    Queue::assertPushed(ProcessPlanningExecutionJob::class, 1);

    $fixture['configuration']->forceFill([
        'provider_policy' => ['allowed_provider_ids' => [], 'fallback_order' => []],
        'revision' => $fixture['configuration']->revision + 1,
    ])->save();

    (new ProcessPlanningExecutionJob($execution->id))->handle(app(ProcessPlanningExecution::class));

    $roadmap = Roadmap::query()->with(['approval', 'tasks.traceabilityLinks'])->sole();
    expect($execution->refresh()->status->value)->toBe('completed')
        ->and($fixture['project']->refresh()->status)->toBe(ProjectStatus::AwaitingRoadmapApproval)
        ->and($roadmap->status)->toBe('awaiting_approval')
        ->and($roadmap->approval)->not->toBeNull()
        ->and($roadmap->tasks)->not->toBeEmpty()
        ->and($roadmap->tasks->first()->traceabilityLinks)->not->toBeEmpty();
});

test('a deterministic blocked result persists safe diagnostics and does not retry', function (): void {
    $fixture = startProjectCommandFixture();
    $command = app(BuildStartProjectCommand::class)->handle(
        organizationId: $fixture['organization']->id,
        projectId: $fixture['project']->id,
        requestedByUserId: $fixture['owner']->id,
        idempotencyKey: 'blocked-planning-result',
        correlationId: (string) Str::ulid(),
    );
    app(CommandBus::class)->dispatch($command);
    $execution = Execution::query()->where('project_id', $fixture['project']->id)->sole();

    (new ProcessPlanningExecutionJob($execution->id, scenario: 'conflicts'))->handle(app(ProcessPlanningExecution::class));

    expect($execution->refresh()->status->value)->toBe('blocked')
        ->and($execution->attempts()->sole()->status->value)->toBe('failed')
        ->and($execution->attempts()->sole()->retryable)->toBeFalse()
        ->and($fixture['project']->refresh()->status)->toBe(ProjectStatus::Blocked)
        ->and(Roadmap::query()->sole()->readiness)->toBe('blocked')
        ->and($execution->planningDiagnostics()->sole()->category)->toBe('deterministic_blocker')
        ->and($execution->planningDiagnostics()->sole()->message)->not->toContain('http');
});

test('roadmap approval is authorized idempotent and rejects stale content', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    $fixture = awaitingRoadmapApprovalFixture();
    $roadmap = $fixture['roadmap'];
    $route = route('organizations.projects.roadmaps.approve', [
        'organization' => $fixture['organization'],
        'project' => $fixture['project'],
        'roadmap' => $roadmap,
    ]);
    $payload = [
        'expected_content_version' => $roadmap->content_version,
        'expected_fingerprint' => $roadmap->candidate_fingerprint,
        'idempotency_key' => 'roadmap-approval-browser-test',
    ];

    $member = User::factory()->create();
    OrganizationMembership::factory()->for($fixture['organization'])->for($member)->create();
    $this->actingAs($member)->post($route, $payload)->assertForbidden();

    $this->actingAs($fixture['owner'])
        ->post($route, [
            ...$payload,
            'expected_content_version' => $roadmap->content_version + 1,
            'idempotency_key' => 'roadmap-stale-approval-browser-test',
        ])
        ->assertSessionHasErrors('roadmap');

    $this->post($route, $payload)->assertSessionHasNoErrors();
    $this->post($route, $payload)->assertSessionHasNoErrors();

    expect($roadmap->refresh()->status)->toBe('approved')
        ->and($roadmap->approval->status->value)->toBe('approved')
        ->and($fixture['project']->refresh()->status)->toBe(ProjectStatus::ReadyForDevelopment)
        ->and(app(RoadmapEligibility::class)->allowsPublicationOrDevelopment($roadmap))->toBeTrue();

    $task = $roadmap->tasks->firstOrFail();
    $task->setAttribute('acceptance_criteria', [
        ...$task->acceptance_criteria,
        ['stable_id' => 'criterion-without-coverage', 'description' => 'Must be covered.', 'source_references' => []],
    ]);
    expect(app(RoadmapEligibility::class)->allowsPublicationOrDevelopment($roadmap))->toBeFalse();
});

test('accepting external Notion content creates a separately approvable roadmap revision', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    $fixture = awaitingRoadmapApprovalFixture();
    $roadmap = $fixture['roadmap'];
    $this->actingAs($fixture['owner'])->post(route('organizations.projects.roadmaps.approve', [
        'organization' => $fixture['organization'],
        'project' => $fixture['project'],
        'roadmap' => $roadmap,
    ]), [
        'expected_content_version' => $roadmap->content_version,
        'expected_fingerprint' => $roadmap->candidate_fingerprint,
        'idempotency_key' => 'approve-before-notion-conflict',
    ])->assertSessionHasNoErrors();

    $task = $roadmap->tasks()->firstOrFail();
    $mapping = ExternalTicketMapping::query()->create([
        'roadmap_task_id' => $task->id,
        'provider' => IntegrationProvider::Notion->value,
        'external_key' => 'notion-conflict-task-key',
        'page_id' => 'notion-page-id',
        'page_url' => 'https://www.notion.so/notion-page-id',
        'state' => 'published',
    ]);
    $mapped = app(NotionTicketMapper::class)->map($task, $mapping);
    $properties = $mapped['properties'];
    $properties['Name']['title'][0]['text']['content'] = 'Accepted Notion title';
    $page = new NotionPage(
        id: 'notion-page-id',
        dataSourceId: ProjectIntegration::query()->where('project_id', $fixture['project']->id)->sole()->data_source_id,
        url: 'https://www.notion.so/notion-page-id',
        properties: $properties,
        providerRequestId: 'request-conflict-accept',
    );
    $fingerprint = app(NotionTicketFingerprint::class)->from($properties, $mapped['body']);
    $mapping->update(['reconciliation_fingerprint' => $fingerprint]);
    $conflict = NotionReconciliationConflict::query()->create([
        'organization_id' => $fixture['organization']->id,
        'project_id' => $fixture['project']->id,
        'external_ticket_mapping_id' => $mapping->id,
        'current_fingerprint' => $fingerprint,
        'published_fingerprint' => $mapped['fingerprint'],
        'external_fingerprint' => $fingerprint,
    ]);

    $cipher = Mockery::mock(IntegrationCredentialCipher::class);
    $cipher->shouldReceive('decrypt')->andReturn(IntegrationCredentialSecret::from('secret_notion_conflict_resolution_token'));
    app()->instance(IntegrationCredentialCipher::class, $cipher);
    $client = Mockery::mock(NotionPublicationClient::class);
    $client->shouldReceive('retrievePage')->once()->andReturn($page);
    $client->shouldReceive('retrievePageBody')->once()->andReturn($mapped['body']);
    app()->instance(NotionPublicationClient::class, $client);

    $accepted = app(AcceptExternalNotionConflict::class)->handle(
        conflict: $conflict,
        actor: $fixture['owner'],
        expectedFingerprint: $fingerprint,
        reason: 'The external refinement is approved for internal review.',
        idempotencyKey: 'accept-external-notion-conflict',
        correlationId: (string) Str::ulid(),
    );
    $replayed = app(AcceptExternalNotionConflict::class)->handle(
        conflict: $conflict->fresh(),
        actor: $fixture['owner'],
        expectedFingerprint: $fingerprint,
        reason: 'The external refinement is approved for internal review.',
        idempotencyKey: 'accept-external-notion-conflict',
        correlationId: (string) Str::ulid(),
    );

    expect($accepted->id)->not->toBe($roadmap->id)
        ->and($replayed->id)->toBe($accepted->id)
        ->and($accepted->parent_roadmap_id)->toBe($roadmap->id)
        ->and($accepted->revision)->toBe($roadmap->revision + 1)
        ->and($accepted->status)->toBe('awaiting_approval')
        ->and($accepted->tasks()->where('stable_id', $task->stable_id)->sole()->title)->toBe('Accepted Notion title')
        ->and($roadmap->refresh()->status)->toBe('approved')
        ->and($fixture['project']->refresh()->status)->toBe(ProjectStatus::AwaitingRoadmapApproval)
        ->and($conflict->refresh()->state)->toBe('accepted')
        ->and($conflict->resulting_roadmap_id)->toBe($accepted->id);
});

test('retaining internal Notion content authorizes only one queued republish', function (): void {
    $fixture = notionConflictFixture();

    $resolved = app(RetainInternalNotionConflict::class)->handle(
        conflict: $fixture['conflict'],
        actor: $fixture['owner'],
        expectedFingerprint: $fixture['fingerprint'],
        reason: 'The approved roadmap remains authoritative.',
        correlationId: (string) Str::ulid(),
    );

    expect($resolved->state)->toBe('republish_queued')
        ->and($resolved->decision)->toBe('retain_internal')
        ->and($fixture['mapping']->refresh()->reconciliation_state)->toBe('republish_authorized')
        ->and(AuditEvent::query()->where('event_type', 'integration.notion.conflict.retain_internal_requested')->exists())->toBeTrue();
});

test('deferring a Notion conflict blocks subsequent publication without remote I/O', function (): void {
    $fixture = notionConflictFixture();

    app(DeferNotionReconciliationConflict::class)->handle(
        conflict: $fixture['conflict'],
        actor: $fixture['owner'],
        expectedFingerprint: $fixture['fingerprint'],
        reason: 'Await product review before choosing a source of truth.',
        correlationId: (string) Str::ulid(),
    );

    $result = app(UpsertNotionTicket::class)->handle(
        actorUserId: $fixture['owner']->id,
        organizationId: $fixture['organization']->id,
        task: $fixture['task']->fresh(),
        correlationId: (string) Str::ulid(),
    );

    expect($fixture['conflict']->refresh()->state)->toBe('deferred')
        ->and($fixture['conflict']->decision)->toBe('defer')
        ->and($fixture['mapping']->refresh()->reconciliation_state)->toBe('deferred')
        ->and($result->outcome)->toBe('blocked')
        ->and(AuditEvent::query()->where('event_type', 'integration.notion.conflict.deferred')->exists())->toBeTrue();
});

test('a retained-internal decision resolves only after its queued typed upsert succeeds', function (): void {
    $fixture = notionConflictFixture();
    app(RetainInternalNotionConflict::class)->handle(
        conflict: $fixture['conflict'],
        actor: $fixture['owner'],
        expectedFingerprint: $fixture['fingerprint'],
        reason: 'Republish the approved internal roadmap content.',
        correlationId: (string) Str::ulid(),
    );

    $properties = app(NotionTicketMapper::class)->map($fixture['task'], $fixture['mapping']->fresh())['properties'];
    $source = new NotionDataSource(
        id: ProjectIntegration::query()->where('project_id', $fixture['project']->id)->sole()->data_source_id,
        name: 'Tickets',
        properties: [
            'Ticket ID' => ['type' => 'rich_text'],
            'Name' => ['type' => 'title'],
            'Status' => ['type' => 'status'],
            'Type' => ['type' => 'select'],
            'Priority' => ['type' => 'select'],
            'Risk' => ['type' => 'select'],
            'Complexity' => ['type' => 'number'],
            'Requires Approval' => ['type' => 'checkbox'],
            'Dependencies' => ['type' => 'rich_text'],
            'Evidence Requirements' => ['type' => 'rich_text'],
            'Source References' => ['type' => 'rich_text'],
        ],
        providerRequestId: 'request-schema',
    );
    $page = new NotionPage(
        id: (string) $fixture['mapping']->page_id,
        dataSourceId: $source->id,
        url: (string) $fixture['mapping']->page_url,
        properties: $properties,
        providerRequestId: 'request-republish',
    );
    $cipher = Mockery::mock(IntegrationCredentialCipher::class);
    $cipher->shouldReceive('decrypt')->andReturn(IntegrationCredentialSecret::from('secret_notion_republish_token'));
    app()->instance(IntegrationCredentialCipher::class, $cipher);
    $client = Mockery::mock(NotionPublicationClient::class);
    $client->shouldReceive('retrieveDataSource')->once()->andReturn($source);
    $client->shouldReceive('retrievePage')->once()->andReturn($page);
    $client->shouldReceive('updatePage')->once()->andReturn($page);
    app()->instance(NotionPublicationClient::class, $client);

    (new RepublishNotionConflictJob(
        conflictId: $fixture['conflict']->id,
        actorUserId: $fixture['owner']->id,
        organizationId: $fixture['organization']->id,
        correlationId: (string) Str::ulid(),
    ))->handle(app(UpsertNotionTicket::class), app(RecordAuditEvent::class));

    expect($fixture['conflict']->refresh()->state)->toBe('resolved')
        ->and($fixture['mapping']->refresh()->state)->toBe('synchronized')
        ->and($fixture['mapping']->reconciliation_state)->toBe('in_sync')
        ->and(AuditEvent::query()->where('event_type', 'integration.notion.conflict.retain_internal_completed')->exists())->toBeTrue();
});

test('a retry request persists an idempotent summary without reissuing successful mappings', function (): void {
    $fixture = notionConflictFixture();
    $first = app(RetryFailedNotionPublication::class)->handle(
        actorUserId: $fixture['owner']->id,
        organizationId: $fixture['organization']->id,
        roadmap: $fixture['roadmap']->fresh(),
        idempotencyKey: 'notion-retry-no-failed-mappings',
        correlationId: (string) Str::ulid(),
    );
    $replay = app(RetryFailedNotionPublication::class)->handle(
        actorUserId: $fixture['owner']->id,
        organizationId: $fixture['organization']->id,
        roadmap: $fixture['roadmap']->fresh(),
        idempotencyKey: 'notion-retry-no-failed-mappings',
        correlationId: (string) Str::ulid(),
    );

    expect($replay->id)->toBe($first->id)
        ->and($first->outcomes)->toBe([])
        ->and($fixture['mapping']->refresh()->state)->toBe('synchronized');
});

test('a retryable Notion failure recovers once and replays its completed summary', function (): void {
    $fixture = notionConflictFixture();
    $fixture['mapping']->update([
        'state' => 'failed',
        'failure_metadata' => ['category' => 'provider_unavailable', 'retryable' => true],
        'reconciliation_state' => null,
    ]);
    $mapping = $fixture['mapping']->fresh();
    $properties = app(NotionTicketMapper::class)->map($fixture['task'], $mapping)['properties'];
    $source = notionTicketDataSource(
        ProjectIntegration::query()->where('project_id', $fixture['project']->id)->sole()->data_source_id,
    );
    $page = new NotionPage(
        id: (string) $mapping->page_id,
        dataSourceId: $source->id,
        url: (string) $mapping->page_url,
        properties: $properties,
        providerRequestId: 'request-retry-recovery',
    );
    $cipher = Mockery::mock(IntegrationCredentialCipher::class);
    $cipher->shouldReceive('decrypt')->andReturn(IntegrationCredentialSecret::from('secret_notion_retry_token'));
    app()->instance(IntegrationCredentialCipher::class, $cipher);
    $client = Mockery::mock(NotionPublicationClient::class);
    $client->shouldReceive('retrieveDataSource')->once()->andReturn($source);
    $client->shouldReceive('retrievePage')->once()->andReturn($page);
    $client->shouldReceive('updatePage')->once()->andReturn($page);
    app()->instance(NotionPublicationClient::class, $client);

    $first = app(RetryFailedNotionPublication::class)->handle(
        actorUserId: $fixture['owner']->id,
        organizationId: $fixture['organization']->id,
        roadmap: $fixture['roadmap']->fresh(),
        idempotencyKey: 'notion-retry-recovery',
        correlationId: (string) Str::ulid(),
    );
    $replay = app(RetryFailedNotionPublication::class)->handle(
        actorUserId: $fixture['owner']->id,
        organizationId: $fixture['organization']->id,
        roadmap: $fixture['roadmap']->fresh(),
        idempotencyKey: 'notion-retry-recovery',
        correlationId: (string) Str::ulid(),
    );

    expect($first->updated_count)->toBe(1)
        ->and($replay->id)->toBe($first->id)
        ->and($mapping->refresh()->state)->toBe('synchronized')
        ->and($mapping->failure_metadata)->toBeNull();
});

test('the offline Notion fixture proves retry replay creates only one page', function (): void {
    $fixture = notionConflictFixture();
    $fixture['mapping']->update([
        'page_id' => null,
        'page_url' => null,
        'state' => 'failed',
        'failure_metadata' => ['category' => 'provider_unavailable', 'retryable' => true],
        'reconciliation_state' => null,
    ]);
    $source = notionTicketDataSource(
        ProjectIntegration::query()->where('project_id', $fixture['project']->id)->sole()->data_source_id,
    );
    $cipher = Mockery::mock(IntegrationCredentialCipher::class);
    $cipher->shouldReceive('decrypt')->once()->andReturn(IntegrationCredentialSecret::from('secret_notion_fixture_token'));
    app()->instance(IntegrationCredentialCipher::class, $cipher);
    $client = new InMemoryNotionPublicationClient($source);
    app()->instance(NotionPublicationClient::class, $client);

    $first = app(RetryFailedNotionPublication::class)->handle(
        actorUserId: $fixture['owner']->id,
        organizationId: $fixture['organization']->id,
        roadmap: $fixture['roadmap']->fresh(),
        idempotencyKey: 'notion-offline-fixture-retry',
        correlationId: (string) Str::ulid(),
    );
    $replay = app(RetryFailedNotionPublication::class)->handle(
        actorUserId: $fixture['owner']->id,
        organizationId: $fixture['organization']->id,
        roadmap: $fixture['roadmap']->fresh(),
        idempotencyKey: 'notion-offline-fixture-retry',
        correlationId: (string) Str::ulid(),
    );

    expect($first->created_count)->toBe(1)
        ->and($replay->id)->toBe($first->id)
        ->and(collect($client->calls)->where('operation', 'create_page'))->toHaveCount(1)
        ->and($fixture['mapping']->refresh()->state)->toBe('synchronized');
});

test('reconciliation detects external drift without overwriting approved roadmap content', function (): void {
    $fixture = notionConflictFixture();
    $mapping = $fixture['mapping']->fresh();
    $source = notionTicketDataSource(
        ProjectIntegration::query()->where('project_id', $fixture['project']->id)->sole()->data_source_id,
    );
    $payload = app(NotionTicketMapper::class)->map($fixture['task'], $mapping);
    $externalProperties = $payload['properties'];
    $externalProperties['Name']['title'][0]['text']['content'] = 'Externally edited Notion title';
    $client = new InMemoryNotionPublicationClient($source);
    $client->seedPage(new NotionPage(
        id: (string) $mapping->page_id,
        dataSourceId: $source->id,
        url: (string) $mapping->page_url,
        properties: $externalProperties,
        providerRequestId: 'fixture-external-edit',
    ), $payload['body']);
    app()->instance(NotionPublicationClient::class, $client);
    $cipher = Mockery::mock(IntegrationCredentialCipher::class);
    $cipher->shouldReceive('decrypt')->once()->andReturn(IntegrationCredentialSecret::from('secret_notion_reconciliation_fixture_token'));
    app()->instance(IntegrationCredentialCipher::class, $cipher);
    $internalTitle = $fixture['task']->title;

    $results = app(ReconcileNotionRoadmap::class)->handle(
        actorUserId: $fixture['owner']->id,
        organizationId: $fixture['organization']->id,
        roadmap: $fixture['roadmap']->fresh(),
        correlationId: (string) Str::ulid(),
    );

    expect($results)->toContain([
        'task_id' => $fixture['task']->id,
        'mapping_id' => $mapping->id,
        'classification' => 'external_drift',
        'evidence_fingerprint' => app(NotionTicketFingerprint::class)->from($externalProperties, $payload['body']),
    ])
        ->and($fixture['task']->refresh()->title)->toBe($internalTitle)
        ->and($mapping->refresh()->reconciliation_state)->toBe('external_drift')
        ->and(collect($client->calls)->whereIn('operation', ['create_page', 'update_page']))->toBeEmpty();
});

test('reconciliation classifies duplicate external ticket keys without a write', function (): void {
    $fixture = notionConflictFixture();
    $mapping = $fixture['mapping']->fresh();
    $source = notionTicketDataSource(ProjectIntegration::query()->where('project_id', $fixture['project']->id)->sole()->data_source_id);
    $payload = app(NotionTicketMapper::class)->map($fixture['task'], $mapping);
    $client = new InMemoryNotionPublicationClient($source);
    foreach ([(string) $mapping->page_id, 'duplicate-fixture-page'] as $pageId) {
        $client->seedPage(new NotionPage($pageId, $source->id, 'https://www.notion.so/'.$pageId, $payload['properties'], 'fixture-'.$pageId), $payload['body']);
    }
    app()->instance(NotionPublicationClient::class, $client);
    $cipher = Mockery::mock(IntegrationCredentialCipher::class);
    $cipher->shouldReceive('decrypt')->once()->andReturn(IntegrationCredentialSecret::from('secret_notion_duplicate_fixture_token'));
    app()->instance(IntegrationCredentialCipher::class, $cipher);

    $results = app(ReconcileNotionRoadmap::class)->handle($fixture['owner']->id, $fixture['organization']->id, $fixture['roadmap']->fresh(), (string) Str::ulid());

    expect(collect($results)->firstWhere('task_id', $fixture['task']->id)['classification'])->toBe('duplicate_key')
        ->and($mapping->refresh()->reconciliation_state)->toBe('duplicate_key')
        ->and(collect($client->calls)->whereIn('operation', ['create_page', 'update_page']))->toBeEmpty();
});

test('reconciliation classifies a missing external page without a write', function (): void {
    $fixture = notionConflictFixture();
    $source = notionTicketDataSource(ProjectIntegration::query()->where('project_id', $fixture['project']->id)->sole()->data_source_id);
    $client = new InMemoryNotionPublicationClient($source);
    app()->instance(NotionPublicationClient::class, $client);
    $cipher = Mockery::mock(IntegrationCredentialCipher::class);
    $cipher->shouldReceive('decrypt')->once()->andReturn(IntegrationCredentialSecret::from('secret_notion_missing_fixture_token'));
    app()->instance(IntegrationCredentialCipher::class, $cipher);

    $results = app(ReconcileNotionRoadmap::class)->handle($fixture['owner']->id, $fixture['organization']->id, $fixture['roadmap']->fresh(), (string) Str::ulid());

    expect(collect($results)->firstWhere('task_id', $fixture['task']->id)['classification'])->toBe('missing_external_page')
        ->and($fixture['mapping']->refresh()->reconciliation_state)->toBe('missing_external_page')
        ->and(collect($client->calls)->whereIn('operation', ['create_page', 'update_page']))->toBeEmpty();
});

test('reconciliation classifies an inaccessible external page without a write', function (): void {
    $fixture = notionConflictFixture();
    $source = notionTicketDataSource(ProjectIntegration::query()->where('project_id', $fixture['project']->id)->sole()->data_source_id);
    $client = new InMemoryNotionPublicationClient($source);
    $client->failNext('find_by_ticket_key', new NotionPublicationException('forbidden', false, 'fixture-forbidden'));
    app()->instance(NotionPublicationClient::class, $client);
    $cipher = Mockery::mock(IntegrationCredentialCipher::class);
    $cipher->shouldReceive('decrypt')->once()->andReturn(IntegrationCredentialSecret::from('secret_notion_inaccessible_fixture_token'));
    app()->instance(IntegrationCredentialCipher::class, $cipher);

    $results = app(ReconcileNotionRoadmap::class)->handle($fixture['owner']->id, $fixture['organization']->id, $fixture['roadmap']->fresh(), (string) Str::ulid());

    expect(collect($results)->firstWhere('task_id', $fixture['task']->id)['classification'])->toBe('inaccessible')
        ->and($fixture['mapping']->refresh()->reconciliation_state)->toBe('inaccessible')
        ->and(collect($client->calls)->whereIn('operation', ['create_page', 'update_page']))->toBeEmpty();
});

test('the authorized retry endpoint queues a durable retry job', function (): void {
    Queue::fake();
    $fixture = awaitingRoadmapApprovalFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('organizations.projects.roadmaps.notion.retry', [
            'organization' => $fixture['organization'],
            'project' => $fixture['project'],
            'roadmap' => $fixture['roadmap'],
        ]), [
            'idempotency_key' => 'notion-retry-controller-test',
        ])
        ->assertSessionHas('status', 'notion-publication-retry-queued');

    Queue::assertPushed(RetryFailedNotionPublicationJob::class, function (RetryFailedNotionPublicationJob $job) use ($fixture): bool {
        return $job->actorUserId === $fixture['owner']->id
            && $job->organizationId === $fixture['organization']->id
            && $job->roadmapId === $fixture['roadmap']->id
            && $job->idempotencyKey === 'notion-retry-controller-test'
            && $job->taskIds === null;
    });
});

test('the authorized retry endpoint scopes a retry to the requested task', function (): void {
    Queue::fake();
    $fixture = awaitingRoadmapApprovalFixture();
    $task = $fixture['roadmap']->tasks()->firstOrFail();

    $this->actingAs($fixture['owner'])
        ->post(route('organizations.projects.roadmaps.notion.retry', [
            'organization' => $fixture['organization'],
            'project' => $fixture['project'],
            'roadmap' => $fixture['roadmap'],
        ]), [
            'idempotency_key' => 'notion-task-retry-controller-test',
            'task_ids' => [$task->id],
        ])
        ->assertSessionHas('status', 'notion-publication-retry-queued');

    Queue::assertPushed(RetryFailedNotionPublicationJob::class, function (RetryFailedNotionPublicationJob $job) use ($task): bool {
        return $job->taskIds === [$task->id]
            && $job->idempotencyKey === 'notion-task-retry-controller-test';
    });
});

test('roadmap rejection records feedback and returns the project to documents', function (): void {
    $fixture = awaitingRoadmapApprovalFixture();
    $roadmap = $fixture['roadmap'];

    $this->actingAs($fixture['owner'])->post(route('organizations.projects.roadmaps.reject', [
        'organization' => $fixture['organization'],
        'project' => $fixture['project'],
        'roadmap' => $roadmap,
    ]), [
        'expected_content_version' => $roadmap->content_version,
        'expected_fingerprint' => $roadmap->candidate_fingerprint,
        'idempotency_key' => 'roadmap-rejection-browser-test',
        'reason' => 'Split the delivery into smaller milestones.',
    ])->assertSessionHasNoErrors();

    expect($roadmap->refresh()->status)->toBe('rejected')
        ->and($roadmap->regeneration_feedback)->toBe('Split the delivery into smaller milestones.')
        ->and($fixture['project']->refresh()->status)->toBe(ProjectStatus::DocumentsPending);
});

test('roadmap edits are append only and supersede the prior approval', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    $fixture = awaitingRoadmapApprovalFixture();
    $roadmap = $fixture['roadmap']->load('approval');
    $priorApprovalId = $roadmap->approval_id;

    $payload = [
        'expected_content_version' => $roadmap->content_version,
        'expected_fingerprint' => $roadmap->candidate_fingerprint,
        'idempotency_key' => 'roadmap-edit-browser-test',
        'patch' => ['roadmap' => ['goal' => 'Deliver a smaller traceable roadmap.']],
    ];
    $route = route('organizations.projects.roadmaps.edits.store', [
        'organization' => $fixture['organization'],
        'project' => $fixture['project'],
        'roadmap' => $roadmap,
    ]);

    $this->actingAs($fixture['owner'])->post($route, $payload)->assertSessionHasNoErrors();
    $this->post($route, $payload)->assertSessionHasNoErrors();

    expect($roadmap->refresh()->content_version)->toBe(2)
        ->and($roadmap->edits()->count())->toBe(1)
        ->and($roadmap->approval_id)->not->toBe($priorApprovalId)
        ->and(Approval::query()->findOrFail($priorApprovalId)->status->value)->toBe('expired')
        ->and(app(MaterializeRoadmap::class)->handle($roadmap)['goal'])->toBe('Deliver a smaller traceable roadmap.');

    expect(fn () => DB::table('roadmap_edits')->where('roadmap_id', $roadmap->id)->update(['content_version' => 3]))
        ->toThrow(QueryException::class, 'roadmap_edits is append-only');
});

test('an immutable policy waiver records an approval gate before development readiness', function (): void {
    $fixture = startProjectCommandFixture();
    $fixture['configuration']->forceFill([
        'approval_policy' => [
            'roadmap_required' => false,
            'ticket_execution_required' => true,
            'merge_required' => true,
        ],
        'revision' => 2,
    ])->save();
    ProjectConfigurationVersion::query()->create([
        'project_id' => $fixture['project']->id,
        'schema_version' => $fixture['configuration']->schema_version,
        'revision' => 2,
        'actor_type' => AuditActorType::System,
        'actor_id' => 'policy-waiver-test',
        'change_reason' => 'policy_waiver_test',
        'snapshot' => $fixture['configuration']->toVersionedArray(),
        'created_at' => now(),
    ]);

    $command = app(BuildStartProjectCommand::class)->handle(
        organizationId: $fixture['organization']->id,
        projectId: $fixture['project']->id,
        requestedByUserId: $fixture['owner']->id,
        idempotencyKey: 'policy-waived-roadmap',
        correlationId: (string) Str::ulid(),
    );
    app(CommandBus::class)->dispatch($command);
    $execution = Execution::query()->where('project_id', $fixture['project']->id)->sole();
    (new ProcessPlanningExecutionJob($execution->id))->handle(app(ProcessPlanningExecution::class));

    $roadmap = Roadmap::query()->with('approval')->sole();
    expect($roadmap->status)->toBe('approved')
        ->and($roadmap->approved_fingerprint)->toBe($roadmap->candidate_fingerprint)
        ->and($roadmap->approval->status->value)->toBe('approved')
        ->and($roadmap->approval->decided_by_user_id)->toBeNull()
        ->and($roadmap->approval->request_payload['approval_authority'])->toBe('immutable_policy')
        ->and($fixture['project']->refresh()->status)->toBe(ProjectStatus::ReadyForDevelopment);
});

test('roadmap regeneration creates one durable execution and preserves the prior revision', function (): void {
    Queue::fake();
    $fixture = awaitingRoadmapApprovalFixture();
    $roadmap = $fixture['roadmap'];

    $this->actingAs($fixture['owner'])->post(route('organizations.projects.roadmaps.regenerate', [
        'organization' => $fixture['organization'],
        'project' => $fixture['project'],
        'roadmap' => $roadmap,
    ]), [
        'expected_content_version' => $roadmap->content_version,
        'expected_fingerprint' => $roadmap->candidate_fingerprint,
        'idempotency_key' => 'roadmap-regeneration-browser-test',
        'feedback' => 'Prioritize the security work.',
    ])->assertSessionHasNoErrors();

    expect($roadmap->refresh()->status)->toBe('superseded')
        ->and($roadmap->feedback_fingerprint)->toBe(hash('sha256', 'Prioritize the security work.'))
        ->and($fixture['project']->refresh()->status)->toBe(ProjectStatus::Planning)
        ->and(Execution::query()->where('project_id', $fixture['project']->id)->count())->toBe(2)
        ->and(Roadmap::query()->whereKey($roadmap->id)->exists())->toBeTrue();

    $event = OutboxMessage::query()->where('event_name', 'roadmap.regeneration_requested')->sole();
    (new ConsumeOutboxMessage($event->event_id))->handle(
        app(DomainEventConsumerRegistry::class),
        app(DeduplicatedDomainEventConsumer::class),
    );
    Queue::assertPushed(ProcessPlanningExecutionJob::class, 1);
});

test('roadmap phase task edit and decision routes enforce explicit project ownership', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    $fixture = awaitingRoadmapApprovalFixture();
    $roadmap = $fixture['roadmap']->load(['phases', 'tasks']);
    $otherProject = Project::factory()->for($fixture['organization'])->create();
    $routeParameters = [
        'organization' => $fixture['organization'],
        'project' => $otherProject,
        'roadmap' => $roadmap,
    ];

    $this->actingAs($fixture['owner'])
        ->get(route('organizations.projects.roadmaps.phases.show', [
            ...$routeParameters,
            'phase' => $roadmap->phases->firstOrFail(),
        ]))->assertNotFound();
    $this->get(route('organizations.projects.roadmaps.tasks.show', [
        ...$routeParameters,
        'task' => $roadmap->tasks->firstOrFail(),
    ]))->assertNotFound();
    $this->post(route('organizations.projects.roadmaps.edits.store', $routeParameters), [
        'expected_content_version' => $roadmap->content_version,
        'expected_fingerprint' => $roadmap->candidate_fingerprint,
        'idempotency_key' => 'cross-project-edit',
        'patch' => ['roadmap' => ['goal' => 'Forbidden']],
    ])->assertNotFound();
    $this->post(route('organizations.projects.roadmaps.approve', $routeParameters), [
        'expected_content_version' => $roadmap->content_version,
        'expected_fingerprint' => $roadmap->candidate_fingerprint,
        'idempotency_key' => 'cross-project-decision',
    ])->assertNotFound();
});

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

/**
 * @return array{owner: User, organization: Organization, project: Project, configuration: ProjectConfiguration, roadmap: Roadmap}
 */
function awaitingRoadmapApprovalFixture(): array
{
    Queue::fake();
    $fixture = startProjectCommandFixture();
    $command = app(BuildStartProjectCommand::class)->handle(
        organizationId: $fixture['organization']->id,
        projectId: $fixture['project']->id,
        requestedByUserId: $fixture['owner']->id,
        idempotencyKey: 'awaiting-roadmap-'.Str::lower((string) Str::ulid()),
        correlationId: (string) Str::ulid(),
    );
    app(CommandBus::class)->dispatch($command);
    $execution = Execution::query()->where('project_id', $fixture['project']->id)->sole();
    (new ProcessPlanningExecutionJob($execution->id))->handle(app(ProcessPlanningExecution::class));

    return [...$fixture, 'roadmap' => Roadmap::query()->where('project_id', $fixture['project']->id)->sole()];
}

/**
 * @return array{owner: User, organization: Organization, project: Project, roadmap: Roadmap, task: RoadmapTask, mapping: ExternalTicketMapping, conflict: NotionReconciliationConflict, fingerprint: string}
 */
function notionConflictFixture(): array
{
    $fixture = awaitingRoadmapApprovalFixture();
    $roadmap = $fixture['roadmap'];
    test()->actingAs($fixture['owner'])->post(route('organizations.projects.roadmaps.approve', [
        'organization' => $fixture['organization'],
        'project' => $fixture['project'],
        'roadmap' => $roadmap,
    ]), [
        'expected_content_version' => $roadmap->content_version,
        'expected_fingerprint' => $roadmap->candidate_fingerprint,
        'idempotency_key' => 'approve-before-notion-decision-'.Str::lower((string) Str::ulid()),
    ])->assertSessionHasNoErrors();

    $task = $roadmap->tasks()->firstOrFail();
    $fingerprint = hash('sha256', 'notion-conflict-'.$task->id);
    $mapping = ExternalTicketMapping::query()->create([
        'roadmap_task_id' => $task->id,
        'provider' => IntegrationProvider::Notion->value,
        'external_key' => 'notion-conflict-'.$task->id,
        'page_id' => 'notion-page-'.$task->id,
        'page_url' => 'https://www.notion.so/notion-page-'.$task->id,
        'state' => 'synchronized',
        'last_synchronized_fingerprint' => hash('sha256', 'published-'.$task->id),
        'reconciliation_state' => 'external_drift',
        'reconciliation_fingerprint' => $fingerprint,
    ]);
    $conflict = NotionReconciliationConflict::query()->create([
        'organization_id' => $fixture['organization']->id,
        'project_id' => $fixture['project']->id,
        'external_ticket_mapping_id' => $mapping->id,
        'current_fingerprint' => $fingerprint,
        'published_fingerprint' => $mapping->last_synchronized_fingerprint,
        'external_fingerprint' => $fingerprint,
    ]);

    return [...$fixture, 'task' => $task, 'mapping' => $mapping, 'conflict' => $conflict, 'fingerprint' => $fingerprint];
}

function notionTicketDataSource(string $id): NotionDataSource
{
    return new NotionDataSource(
        id: $id,
        name: 'Tickets',
        properties: [
            'Ticket ID' => ['type' => 'rich_text'],
            'Name' => ['type' => 'title'],
            'Status' => ['type' => 'status'],
            'Type' => ['type' => 'select'],
            'Priority' => ['type' => 'select'],
            'Risk' => ['type' => 'select'],
            'Complexity' => ['type' => 'number'],
            'Requires Approval' => ['type' => 'checkbox'],
            'Dependencies' => ['type' => 'rich_text'],
            'Evidence Requirements' => ['type' => 'rich_text'],
            'Source References' => ['type' => 'rich_text'],
        ],
        providerRequestId: 'request-schema',
    );
}
