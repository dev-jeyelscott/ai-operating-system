<?php

declare(strict_types=1);

use App\Application\Events\Contracts\DomainEventConsumer;
use App\Application\Events\Contracts\OutboxTransport;
use App\Application\Events\Data\StoredDomainEvent;
use App\Application\Events\DeduplicatedDomainEventConsumer;
use App\Application\Events\DispatchOutboxMessages;
use App\Application\Executions\Data\ExecutionAttemptContext;
use App\Application\Executions\ExecutionResilienceManager;
use App\Application\Projects\BuildStartProjectCommand;
use App\Application\Shared\Commands\CommandBus;
use App\Application\Shared\Commands\CommandResultStatus;
use App\Application\Workflows\CreateWorkflowInstance;
use App\Application\Workflows\RegisterWorkflowDefinition;
use App\Application\Workflows\TransitionWorkflowInstance;
use App\Domain\Audit\AuditActorType;
use App\Domain\Executions\ExecutionAttemptStatus;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Identity\OrganizationRole;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Integrations\NotionConnectionStatus;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Domain\Projects\ProjectSetupStep;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Workflows\Exceptions\InvalidWorkflowTransition;
use App\Domain\Workflows\WorkflowDefinitionManifest;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Execution;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OutboxMessage;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectConfigurationVersion;
use App\Models\ProjectIntegration;
use App\Models\ProjectSetupProgress;
use App\Models\ProviderCredential;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ProjectDeliveryWorkflowSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Http::preventStrayRequests();

    Storage::fake(
        (string) config('filesystems.artifact'),
    );

    $this->seed(ProjectDeliveryWorkflowSeeder::class);

    Schema::create(
        'workflow_chaos_side_effects',
        function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('event_id', 26);
            $table->string('consumer_name', 191);
            $table->timestampTz('created_at');
        },
    );
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();

    Schema::dropIfExists('workflow_chaos_side_effects');
});

it('replays duplicate StartProject commands without duplicating authoritative state', function (): void {
    $fixture = aios065StartProjectFixture();

    $command = app(BuildStartProjectCommand::class)->handle(
        organizationId: $fixture['organization']->id,
        projectId: $fixture['project']->id,
        requestedByUserId: $fixture['owner']->id,
        idempotencyKey: 'aios-065-duplicate-start',
        correlationId: (string) Str::ulid(),
    );

    $bus = app(CommandBus::class);

    $first = $bus->dispatch($command);
    $replayed = $bus->dispatch($command);

    expect($first->status)
        ->toBe(CommandResultStatus::Succeeded)
        ->and($replayed->toArray())
        ->toBe($first->toArray())
        ->and($fixture['project']->refresh()->status)
        ->toBe(ProjectStatus::Planning);

    $this->assertDatabaseCount('idempotency_keys', 1);
    $this->assertDatabaseCount('project_context_snapshots', 1);
    $this->assertDatabaseCount('workflow_instances', 1);
    $this->assertDatabaseCount('executions', 1);
    $this->assertDatabaseCount('outbox_messages', 2);
    $this->assertDatabaseCount('notification_events', 1);
    $this->assertDatabaseCount('notification_recipients', 1);

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
})->group('workflow-invariants', 'chaos');

it('applies a replayed consumer side effect exactly once', function (): void {
    $organization = Organization::factory()->create();
    $eventId = (string) Str::ulid();

    $event = new StoredDomainEvent(
        eventId: $eventId,
        eventName: 'test.workflow.changed',
        organizationId: $organization->id,
        projectId: null,
        schemaVersion: 1,
        envelope: [
            'event_id' => $eventId,
            'event_name' => 'test.workflow.changed',
            'schema_version' => 1,
            'payload' => [
                'scenario' => 'consumer-replay',
            ],
        ],
    );

    $consumer = new class implements DomainEventConsumer
    {
        /**
         * Return the stable consumer identity used for durable deduplication.
         */
        public function consumerName(): string
        {
            return 'tests.aios-065-projector';
        }

        /**
         * Subscribe the test projector to the synthetic workflow event.
         *
         * @return list<string>
         */
        public function subscribedEventNames(): array
        {
            return ['test.workflow.changed'];
        }

        /**
         * Persist the side effect that must occur at most once.
         */
        public function handle(StoredDomainEvent $event): void
        {
            DB::table('workflow_chaos_side_effects')->insert([
                'event_id' => $event->eventId,
                'consumer_name' => $this->consumerName(),
                'created_at' => CarbonImmutable::now(),
            ]);
        }
    };

    $runner = app(DeduplicatedDomainEventConsumer::class);

    $firstRun = $runner->handle(
        event: $event,
        consumer: $consumer,
    );

    $replayedRun = $runner->handle(
        event: $event,
        consumer: $consumer,
    );

    expect($firstRun)
        ->toBeTrue()
        ->and($replayedRun)
        ->toBeFalse()
        ->and(
            DB::table('workflow_chaos_side_effects')->count(),
        )
        ->toBe(1)
        ->and(
            DB::table('domain_event_consumptions')
                ->where('event_id', $eventId)
                ->where(
                    'consumer_name',
                    'tests.aios-065-projector',
                )
                ->count(),
        )
        ->toBe(1);
})->group('workflow-invariants', 'chaos');

it('recovers an outbox batch after one transport publication fails', function (): void {
    $organization = Organization::factory()->create();
    $now = CarbonImmutable::parse('2026-07-27 09:00:00 UTC');

    CarbonImmutable::setTestNow($now);

    $first = aios065OutboxMessage(
        organization: $organization,
        publishedAt: null,
        availableAt: $now->subSeconds(2),
    );

    $second = aios065OutboxMessage(
        organization: $organization,
        publishedAt: null,
        availableAt: $now->subSecond(),
    );

    $transport = new class($second->event_id) implements OutboxTransport
    {
        /** @var list<string> */
        public array $publishedEventIds = [];

        /**
         * Create a transport that initially fails one selected event.
         */
        public function __construct(
            public ?string $failingEventId,
        ) {}

        /**
         * Record each publication attempt and fail the configured event.
         */
        public function publish(string $eventId): void
        {
            $this->publishedEventIds[] = $eventId;

            if ($eventId === $this->failingEventId) {
                throw new RuntimeException(
                    'AIOS-065 simulated Redis publication failure.',
                );
            }
        }
    };

    $this->app->instance(OutboxTransport::class, $transport);

    $dispatcher = app(DispatchOutboxMessages::class);

    $firstPass = $dispatcher->handle(
        limit: 10,
        leaseSeconds: 30,
        maximumAttempts: 3,
        baseBackoffSeconds: 1,
        maximumBackoffSeconds: 5,
    );

    $first->refresh();
    $second->refresh();

    expect($firstPass)
        ->toBe([
            'expired_dead_lettered' => 0,
            'claimed' => 2,
            'published' => 1,
            'failed' => 1,
            'dead_lettered' => 0,
            'reservation_conflicts' => 0,
        ])
        ->and($first->published_at)
        ->not->toBeNull()
        ->and($first->dispatch_attempts)
        ->toBe(1)
        ->and($second->published_at)
        ->toBeNull()
        ->and($second->dispatch_attempts)
        ->toBe(1)
        ->and($second->last_error)
        ->toContain(
            'AIOS-065 simulated Redis publication failure.',
        );

    $transport->failingEventId = null;

    $second->forceFill([
        'available_at' => CarbonImmutable::now()->subSecond(),
    ])->save();

    $secondPass = $dispatcher->handle(
        limit: 10,
        leaseSeconds: 30,
        maximumAttempts: 3,
        baseBackoffSeconds: 1,
        maximumBackoffSeconds: 5,
    );

    $first->refresh();
    $second->refresh();

    expect($secondPass)
        ->toBe([
            'expired_dead_lettered' => 0,
            'claimed' => 1,
            'published' => 1,
            'failed' => 0,
            'dead_lettered' => 0,
            'reservation_conflicts' => 0,
        ])
        ->and($first->dispatch_attempts)
        ->toBe(1)
        ->and($second->published_at)
        ->not->toBeNull()
        ->and($second->dispatch_attempts)
        ->toBe(2)
        ->and(
            array_count_values(
                $transport->publishedEventIds,
            )[$first->event_id] ?? 0,
        )
        ->toBe(1)
        ->and(
            array_count_values(
                $transport->publishedEventIds,
            )[$second->event_id] ?? 0,
        )
        ->toBe(2);
})->group('workflow-invariants', 'chaos');

it('processes an expired execution attempt only once across repeated recovery sweeps', function (): void {
    $startedAt = CarbonImmutable::parse(
        '2026-07-27 10:00:00 UTC',
    );

    $execution = Execution::factory()->create([
        'requested_reasoning_level' => ReasoningLevel::High,
        'retry_limit' => 1,
        'timeout_seconds' => 60,
        'retry_base_delay_seconds' => 30,
        'retry_max_delay_seconds' => 300,
        'retry_jitter_percent' => 0,
    ]);

    $manager = app(ExecutionResilienceManager::class);

    $attempt = $manager->startAttempt(
        execution: $execution,
        context: aios065ExecutionAttemptContext(),
        at: $startedAt,
    );

    $firstSweep = $manager->processExpiredAttempts(
        at: $startedAt->addSeconds(61),
    );

    $secondSweep = $manager->processExpiredAttempts(
        at: $startedAt->addSeconds(61),
    );

    $execution->refresh();
    $attempt->refresh();

    expect($firstSweep)
        ->toBe(1)
        ->and($secondSweep)
        ->toBe(0)
        ->and($execution->status)
        ->toBe(ExecutionStatus::RetryScheduled)
        ->and($execution->attempt_count)
        ->toBe(1)
        ->and($execution->attempts()->count())
        ->toBe(1)
        ->and($attempt->status)
        ->toBe(ExecutionAttemptStatus::TimedOut)
        ->and(
            OutboxMessage::query()
                ->where(
                    'event_name',
                    'execution.attempt.timed_out',
                )
                ->count(),
        )
        ->toBe(1)
        ->and(
            OutboxMessage::query()
                ->where(
                    'event_name',
                    'execution.retry_scheduled',
                )
                ->count(),
        )
        ->toBe(1);
})->group('workflow-invariants', 'chaos');

it('lets cancellation win over timeout and prevents retry creation', function (): void {
    $startedAt = CarbonImmutable::parse(
        '2026-07-27 11:00:00 UTC',
    );

    $execution = Execution::factory()->create([
        'requested_reasoning_level' => ReasoningLevel::High,
        'retry_limit' => 3,
        'timeout_seconds' => 60,
        'retry_jitter_percent' => 0,
    ]);

    $manager = app(ExecutionResilienceManager::class);

    $attempt = $manager->startAttempt(
        execution: $execution,
        context: aios065ExecutionAttemptContext(),
        at: $startedAt,
    );

    expect(
        $manager->requestCancellation(
            execution: $execution,
            reason: 'AIOS-065 cancellation race.',
            at: $startedAt->addSeconds(30),
        ),
    )->toBeTrue();

    $firstSweep = $manager->processExpiredAttempts(
        at: $startedAt->addSeconds(61),
    );

    $secondSweep = $manager->processExpiredAttempts(
        at: $startedAt->addSeconds(61),
    );

    $execution->refresh();
    $attempt->refresh();

    expect($firstSweep)
        ->toBe(1)
        ->and($secondSweep)
        ->toBe(0)
        ->and($execution->status)
        ->toBe(ExecutionStatus::Cancelled)
        ->and($execution->next_attempt_at)
        ->toBeNull()
        ->and($attempt->status)
        ->toBe(ExecutionAttemptStatus::Cancelled)
        ->and($attempt->retryable)
        ->toBeNull()
        ->and($attempt->retry_delay_seconds)
        ->toBeNull()
        ->and(
            OutboxMessage::query()
                ->where(
                    'event_name',
                    'execution.retry_scheduled',
                )
                ->count(),
        )
        ->toBe(0)
        ->and(
            OutboxMessage::query()
                ->where(
                    'event_name',
                    'execution.cancelled',
                )
                ->count(),
        )
        ->toBe(1);
})->group('workflow-invariants', 'chaos');

it('rejects an illegal workflow transition without mutating state or history', function (): void {
    $project = Project::factory()->create();

    app(RegisterWorkflowDefinition::class)->handle(
        aios065WorkflowManifest(),
    );

    $instance = app(CreateWorkflowInstance::class)->handle(
        project: $project,
        definitionKey: 'aios_065_project_delivery',
        version: 1,
    );

    expect(
        fn () => app(TransitionWorkflowInstance::class)->handle(
            instance: $instance,
            transitionName: 'complete',
        ),
    )->toThrow(
        InvalidWorkflowTransition::class,
        'Workflow transition "complete" cannot run from state "queued"; expected "running".',
    );

    $instance->refresh();

    expect($instance->current_state)
        ->toBe('queued')
        ->and($instance->transition_sequence)
        ->toBe(0)
        ->and($instance->completed_at)
        ->toBeNull();

    expect(
        $instance->transitions()->count(),
    )->toBe(0);
})->group('workflow-invariants', 'chaos');

/**
 * Create one complete tenant-scoped StartProject fixture for AIOS-065.
 *
 * @return array{
 *     owner: User,
 *     organization: Organization,
 *     project: Project,
 *     configuration: ProjectConfiguration
 * }
 */
function aios065StartProjectFixture(): array
{
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
            'status' => ProjectStatus::ReadyForPlanning,
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
        'actor_id' => 'aios-065-test-suite',
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
                'title' => Str::headline($documentClass),
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
        'secret_ciphertext' => 'encrypted-aios-065-fixture',
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
        'last_provider_request_id' => 'request-aios-065',
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
 * Persist one synthetic outbox message for dispatcher and replay tests.
 */
function aios065OutboxMessage(
    Organization $organization,
    ?CarbonImmutable $publishedAt,
    ?CarbonImmutable $availableAt = null,
): OutboxMessage {
    $eventId = (string) Str::ulid();
    $occurredAt = $availableAt ?? CarbonImmutable::now();

    return OutboxMessage::query()->create([
        'event_id' => $eventId,
        'event_name' => 'test.workflow.changed',
        'aggregate_type' => 'test.workflow',
        'aggregate_id' => 'workflow-aios-065',
        'organization_id' => $organization->id,
        'project_id' => null,
        'occurred_at' => $occurredAt,
        'correlation_id' => $eventId,
        'causation_id' => null,
        'execution_id' => null,
        'schema_version' => 1,
        'envelope' => [
            'event_id' => $eventId,
            'event_name' => 'test.workflow.changed',
            'aggregate_type' => 'test.workflow',
            'aggregate_id' => 'workflow-aios-065',
            'organization_id' => $organization->id,
            'project_id' => null,
            'actor' => [
                'type' => 'system',
                'id' => 'aios-065-test-suite',
            ],
            'provider' => [
                'type' => 'application',
                'id' => 'aios-065-test-suite',
            ],
            'occurred_at' => $occurredAt->toIso8601String(),
            'correlation_id' => $eventId,
            'causation_id' => null,
            'execution_id' => null,
            'schema_version' => 1,
            'payload' => [
                'scenario' => 'workflow-chaos',
            ],
        ],
        'published_at' => $publishedAt,
        'available_at' => $availableAt ?? $occurredAt,
        'reserved_until' => null,
        'reservation_token' => null,
        'dispatch_attempts' => $publishedAt === null ? 0 : 1,
        'last_error' => null,
        'created_at' => $occurredAt,
    ]);
}

/**
 * Build the deterministic execution-attempt context used by chaos tests.
 */
function aios065ExecutionAttemptContext(): ExecutionAttemptContext
{
    return new ExecutionAttemptContext(
        executionProvider: 'simulation',
        modelIdentifier: 'simulation-v1',
        requestedReasoningLevel: ReasoningLevel::High,
        effectiveReasoningLevel: ReasoningLevel::High,
        reasoningResolutionSource: 'ticket_override',
        simulationMode: 'chaos',
        simulationSeed: 'aios-065',
    );
}

/**
 * Build a minimal immutable workflow definition for transition invariants.
 */
function aios065WorkflowManifest(): WorkflowDefinitionManifest
{
    return new WorkflowDefinitionManifest(
        definitionKey: 'aios_065_project_delivery',
        version: 1,
        schemaVersion: 1,
        name: 'AIOS-065 project delivery workflow',
        description: 'Exercises deterministic transition invariants.',
        initialState: 'queued',
        states: [
            'queued',
            'running',
            'completed',
        ],
        terminalStates: [
            'completed',
        ],
        transitions: [
            [
                'name' => 'start',
                'from' => 'queued',
                'to' => 'running',
                'guard' => null,
            ],
            [
                'name' => 'complete',
                'from' => 'running',
                'to' => 'completed',
                'guard' => null,
            ],
        ],
    );
}
