<?php

declare(strict_types=1);

use App\Application\Development\Consumers\DispatchDevelopmentExecution;
use App\Application\Development\Consumers\RedispatchDevelopmentRetry;
use App\Application\Development\Contracts\DevelopmentExecutionProvider;
use App\Application\Development\Data\DevelopmentExecutionRequest;
use App\Application\Development\Data\DevelopmentExecutionResult;
use App\Application\Development\DevelopmentArtifactRecorder;
use App\Application\Development\DevelopmentProviderRegistry;
use App\Application\Development\DevelopmentResultValidator;
use App\Application\Development\ProcessDevelopmentExecution;
use App\Application\Events\Data\StoredDomainEvent;
use App\Application\Events\DeduplicatedDomainEventConsumer;
use App\Application\Executions\ExecutionResilienceManager;
use App\Application\Tickets\Data\TicketSelectionRequest;
use App\Application\Tickets\SelectNextTicketAndAcquireLease;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Tickets\TicketLeaseReleaseReason;
use App\Domain\Tickets\TicketStatus;
use App\Infrastructure\Development\SimulationDevelopmentProvider;
use App\Jobs\ProcessDevelopmentExecutionJob;
use App\Models\Artifact;
use App\Models\Evidence;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\RoadmapTask;
use App\Models\TicketExecutionLease;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\TicketTestFixture;

/** @return array<string, mixed> */
function aios096Fixture(int $retryLimit = 2): array
{
    $fixture = TicketTestFixture::create(
        stableId: 'AIOS-096',
        ticketAttributes: [
            'title' => 'Implement Layer 2 simulation orchestration',
            'objective' => 'Orchestrate deterministic development simulation.',
            'status' => TicketStatus::Ready, 'desired_state' => TicketStatus::Ready,
            'status_changed_at' => now()->subMinute(), 'ready_at' => now()->subMinute(),
            'acceptance_criteria' => ['Simulation completes.'], 'evidence_requirements' => ['Automated tests.'],
        ],
        configurationSnapshot: ['policy' => ['validation' => ['commands' => ['php artisan test']]]],
    );
    $fixture['roadmap']->forceFill([
        'status' => 'approved', 'approved_fingerprint' => $fixture['roadmap']->candidate_fingerprint,
        'approved_snapshot' => ['schema_version' => 1], 'approved_at' => now(),
    ])->save();
    $execution = Execution::factory()->for($fixture['project'])->create([
        'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
        'capability' => 'development.simulation', 'retry_limit' => $retryLimit,
    ]);
    app(SelectNextTicketAndAcquireLease::class)->handle(new TicketSelectionRequest(
        organizationId: $fixture['project']->organization_id, projectId: $fixture['project']->id,
        executionId: $execution->id, owner: 'aios-096-worker',
    ));
    $fixture['execution'] = $execution;
    $fixture['lease'] = TicketExecutionLease::query()->where('execution_id', $execution->id)->firstOrFail();

    return $fixture;
}

test('development simulation completes with ordered events and unverified artifacts', function (): void {
    $fixture = aios096Fixture();

    app(ProcessDevelopmentExecution::class)->handle($fixture['execution'], seed: 96);

    expect($fixture['execution']->refresh()->status)->toBe(ExecutionStatus::Completed)
        ->and(RoadmapTask::query()->findOrFail($fixture['ticket']->id)->status)->toBe(TicketStatus::ForQa)
        ->and($fixture['lease']->refresh()->release_reason)->toBe(TicketLeaseReleaseReason::Completion)
        ->and(Artifact::query()->count())->toBe(7)
        ->and(Evidence::query()->count())->toBe(7)
        ->and(Artifact::query()->where('execution_provider', 'simulation')->where('actual_state', 'unverified')->where('evidence_still_required', true)->count())->toBe(7)
        ->and(Evidence::query()->where('classification', 'simulated_output')->count())->toBe(7);

    $events = DB::table('outbox_messages')
        ->whereIn('event_name', [
            'implementation.started', 'validation.started', 'validation.completed',
            'synthetic.commit_created', 'synthetic.push_recorded', 'pull_request.created', 'implementation.completed',
        ])->orderBy('sequence')->pluck('event_name')->all();
    expect($events)->toBe([
        'implementation.started', 'validation.started', 'validation.completed',
        'synthetic.commit_created', 'synthetic.push_recorded', 'pull_request.created', 'implementation.completed',
    ]);
    expect(Artifact::query()->pluck('external_reference')->every(
        static fn (?string $reference): bool => is_string($reference) && str_starts_with($reference, 'simulation://'),
    ))->toBeTrue();
});

test('completed development job replay creates no second attempt or artifacts', function (): void {
    $fixture = aios096Fixture();
    $job = new ProcessDevelopmentExecutionJob($fixture['execution']->id, seed: 96);

    $job->handle(app(ProcessDevelopmentExecution::class));
    $job->handle(app(ProcessDevelopmentExecution::class));

    $this->assertDatabaseCount('execution_attempts', 1);
    $this->assertDatabaseCount('artifacts', 7);
});

test('development job owns no competing queue retry loop', function (): void {
    $job = new ProcessDevelopmentExecutionJob('01KYPAB5S2ETWGGMB4TFTVWX1E');

    expect($job->tries)->toBe(1)
        ->and($job->uniqueId())->toBe('development-execution:01KYPAB5S2ETWGGMB4TFTVWX1E');
});

test('provider execution occurs outside every database transaction', function (): void {
    $fixture = aios096Fixture();
    $testTransactionLevel = DB::transactionLevel();
    $delegate = app(SimulationDevelopmentProvider::class);
    $provider = new class($delegate) implements DevelopmentExecutionProvider
    {
        public ?int $transactionLevel = null;

        public function __construct(private SimulationDevelopmentProvider $delegate) {}

        public function id(): string
        {
            return 'simulation';
        }

        public function supports(string $capability): bool
        {
            return $this->delegate->supports($capability);
        }

        public function execute(DevelopmentExecutionRequest $request): DevelopmentExecutionResult
        {
            $this->transactionLevel = DB::transactionLevel();

            return $this->delegate->execute($request);
        }
    };
    app()->instance(DevelopmentProviderRegistry::class, new DevelopmentProviderRegistry([$provider]));

    app(ProcessDevelopmentExecution::class)->handle($fixture['execution'], seed: 96);

    expect($provider->transactionLevel)->toBe($testTransactionLevel);
});

test('provider failure schedules domain retry without releasing lease or reaching for qa', function (): void {
    $fixture = aios096Fixture();
    $provider = new class implements DevelopmentExecutionProvider
    {
        public function id(): string
        {
            return 'simulation';
        }

        public function supports(string $capability): bool
        {
            return true;
        }

        public function execute(DevelopmentExecutionRequest $request): DevelopmentExecutionResult
        {
            throw new RuntimeException('provider failed');
        }
    };
    app()->instance(DevelopmentProviderRegistry::class, new DevelopmentProviderRegistry([$provider]));

    app(ProcessDevelopmentExecution::class)->handle($fixture['execution']);

    expect($fixture['execution']->refresh()->status)->toBe(ExecutionStatus::RetryScheduled)
        ->and($fixture['ticket']->refresh()->status)->toBe(TicketStatus::InProgress)
        ->and($fixture['lease']->refresh()->isActive())->toBeTrue();
    $this->assertDatabaseCount('artifacts', 0);
});

test('wrong pull request target creates no artifact and cannot reach for qa', function (): void {
    $fixture = aios096Fixture();
    $delegate = app(SimulationDevelopmentProvider::class);
    $provider = new class($delegate) implements DevelopmentExecutionProvider
    {
        public function __construct(private SimulationDevelopmentProvider $delegate) {}

        public function id(): string
        {
            return 'simulation';
        }

        public function supports(string $capability): bool
        {
            return true;
        }

        public function execute(DevelopmentExecutionRequest $request): DevelopmentExecutionResult
        {
            $result = $this->delegate->execute($request);
            $data = $result->toArray();
            $data['target_branch'] = 'main';
            $data['synthetic_pull_request_result']['target_branch'] = 'main';
            $data['canonical_result_fingerprint'] = '';
            $temporary = DevelopmentExecutionResult::fromArray($data);
            $data['canonical_result_fingerprint'] = (new DevelopmentResultValidator)->fingerprint($temporary);

            return DevelopmentExecutionResult::fromArray($data);
        }
    };
    app()->instance(DevelopmentProviderRegistry::class, new DevelopmentProviderRegistry([$provider]));

    app(ProcessDevelopmentExecution::class)->handle($fixture['execution']);

    expect($fixture['execution']->refresh()->status)->toBe(ExecutionStatus::Failed)
        ->and($fixture['ticket']->refresh()->status)->toBe(TicketStatus::InProgress);
    $this->assertDatabaseCount('artifacts', 0);
    $this->assertDatabaseCount('evidence', 0);
});

test('cancellation racing successful provider completion wins before artifacts persist', function (): void {
    $fixture = aios096Fixture();
    $delegate = app(SimulationDevelopmentProvider::class);
    $provider = new class($delegate, $fixture['execution']) implements DevelopmentExecutionProvider
    {
        public function __construct(private SimulationDevelopmentProvider $delegate, private Execution $execution) {}

        public function id(): string
        {
            return 'simulation';
        }

        public function supports(string $capability): bool
        {
            return true;
        }

        public function execute(DevelopmentExecutionRequest $request): DevelopmentExecutionResult
        {
            app(ExecutionResilienceManager::class)->requestCancellation($this->execution, reason: 'test race');

            return $this->delegate->execute($request);
        }
    };
    app()->instance(DevelopmentProviderRegistry::class, new DevelopmentProviderRegistry([$provider]));

    app(ProcessDevelopmentExecution::class)->handle($fixture['execution']);

    expect($fixture['execution']->refresh()->status)->toBe(ExecutionStatus::Cancelled)
        ->and($fixture['ticket']->refresh()->status)->toBe(TicketStatus::InProgress)
        ->and($fixture['lease']->refresh()->release_reason)->toBe(TicketLeaseReleaseReason::Cancellation);
    $this->assertDatabaseCount('artifacts', 0);
});

test('DispatchDevelopmentExecution validates lineage and dispatches one unique job', function (): void {
    Bus::fake();
    $fixture = aios096Fixture();
    $event = new StoredDomainEvent(
        eventId: '01KYPAB5S2ETWGGMB4TFTVWX1H', eventName: 'ticket.lease_acquired',
        organizationId: $fixture['project']->organization_id, projectId: $fixture['project']->id, schemaVersion: 1,
        envelope: ['payload' => [
            'execution_id' => $fixture['execution']->id, 'lease_id' => $fixture['lease']->id,
            'roadmap_task_id' => $fixture['ticket']->id,
        ]],
    );

    app(DispatchDevelopmentExecution::class)->handle($event);

    Bus::assertDispatched(ProcessDevelopmentExecutionJob::class, 1);
});

test('artifact persistence failure rolls back the terminal stage and schedules domain retry', function (): void {
    $fixture = aios096Fixture();
    app()->instance(DevelopmentArtifactRecorder::class, new class extends DevelopmentArtifactRecorder
    {
        public function __construct() {}

        public function record(
            Execution $execution,
            ExecutionAttempt $attempt,
            string $type,
            string $name,
            string $reference,
            array $metadata,
            array $claims,
        ): Artifact {
            throw new RuntimeException('artifact store unavailable');
        }
    });

    app(ProcessDevelopmentExecution::class)->handle($fixture['execution']);

    expect($fixture['execution']->refresh()->status)->toBe(ExecutionStatus::RetryScheduled)
        ->and($fixture['ticket']->refresh()->status)->toBe(TicketStatus::InProgress)
        ->and($fixture['lease']->refresh()->isActive())->toBeTrue();
    $this->assertDatabaseCount('artifacts', 0);
});

test('DevelopmentValidationFailure persists only failed simulated evidence and schedules retry', function (): void {
    $fixture = aios096Fixture();

    app(ProcessDevelopmentExecution::class)->handle($fixture['execution'], 'development_validation_failure', 98);

    expect($fixture['execution']->refresh()->status)->toBe(ExecutionStatus::RetryScheduled)
        ->and($fixture['execution']->next_attempt_at)->not->toBeNull()
        ->and($fixture['ticket']->refresh()->status)->toBe(TicketStatus::InProgress)
        ->and($fixture['lease']->refresh()->isActive())->toBeTrue();
    $this->assertDatabaseCount('artifacts', 1);
    $this->assertDatabaseHas('artifacts', ['artifact_type' => 'validation_failure', 'actual_state' => 'unverified']);
    $this->assertDatabaseHas('evidence', ['classification' => 'simulated_output', 'evidence_type' => 'validation_failure']);
    $this->assertDatabaseMissing('artifacts', ['artifact_type' => 'synthetic_commit']);
    $this->assertDatabaseMissing('artifacts', ['artifact_type' => 'synthetic_push']);
    $this->assertDatabaseMissing('artifacts', ['artifact_type' => 'synthetic_pull_request']);
});

test('DevelopmentRetry provider timeout uses domain timing without queue retry or artifacts', function (): void {
    $fixture = aios096Fixture();

    app(ProcessDevelopmentExecution::class)->handle($fixture['execution'], 'provider_timeout_retry', 98);

    expect($fixture['execution']->refresh()->status)->toBe(ExecutionStatus::RetryScheduled)
        ->and($fixture['execution']->next_attempt_at)->not->toBeNull()
        ->and($fixture['lease']->refresh()->isActive())->toBeTrue();
    $this->assertDatabaseHas('execution_attempts', ['execution_id' => $fixture['execution']->id, 'error_code' => 'development.provider_timeout', 'retryable' => true]);
    $this->assertDatabaseCount('artifacts', 0);
});

test('DevelopmentRetry release permits later success while preserving failure history', function (): void {
    $fixture = aios096Fixture();
    app(ProcessDevelopmentExecution::class)->handle($fixture['execution'], 'development_validation_failure', 98);
    $fixture['execution']->refresh();
    $nextAttemptAt = $fixture['execution']->next_attempt_at;
    expect($nextAttemptAt)->not->toBeNull();

    expect(app(ExecutionResilienceManager::class)->releaseDueRetries($nextAttemptAt?->subSecond()))->toBe(0)
        ->and(app(ExecutionResilienceManager::class)->releaseDueRetries($nextAttemptAt?->addSecond()))->toBe(1);
    app(ProcessDevelopmentExecution::class)->handle($fixture['execution']->refresh(), 'happy_path', 98);

    expect($fixture['execution']->refresh()->status)->toBe(ExecutionStatus::Completed)
        ->and($fixture['execution']->attempt_count)->toBe(2)
        ->and($fixture['ticket']->refresh()->status)->toBe(TicketStatus::ForQa)
        ->and(Artifact::query()->where('artifact_type', 'validation_failure')->count())->toBe(1);
    $this->assertDatabaseHas('execution_attempts', ['execution_id' => $fixture['execution']->id, 'attempt_number' => 1, 'error_code' => 'development.validation_failed']);
    $this->assertDatabaseHas('execution_attempts', ['execution_id' => $fixture['execution']->id, 'attempt_number' => 2, 'status' => 'completed']);
});

test('DevelopmentRetry exhaustion fails execution and releases lease exactly once', function (): void {
    $fixture = aios096Fixture(retryLimit: 0);

    app(ProcessDevelopmentExecution::class)->handle($fixture['execution'], 'development_validation_failure', 98);

    expect($fixture['execution']->refresh()->status)->toBe(ExecutionStatus::Failed)
        ->and($fixture['ticket']->refresh()->status)->toBe(TicketStatus::InProgress)
        ->and($fixture['lease']->refresh()->release_reason)->toBe(TicketLeaseReleaseReason::TerminalFailure);
    $this->assertDatabaseCount('ticket_execution_leases', 1);
});

test('RedispatchDevelopmentRetry dispatches only after durable retry release', function (): void {
    Bus::fake();
    $fixture = aios096Fixture();
    app(ProcessDevelopmentExecution::class)->handle($fixture['execution'], 'development_validation_failure', 98);
    $execution = $fixture['execution']->refresh();
    app(ExecutionResilienceManager::class)->releaseDueRetries($execution->next_attempt_at?->addSecond());
    $event = new StoredDomainEvent(
        eventId: '01KYPAB5S2ETWGGMB4TFTVWX1J', eventName: 'execution.retry_released',
        organizationId: $fixture['project']->organization_id, projectId: $fixture['project']->id,
        schemaVersion: 1, envelope: ['payload' => ['execution_id' => $execution->id]],
    );

    $deduplicated = app(DeduplicatedDomainEventConsumer::class);
    $consumer = app(RedispatchDevelopmentRetry::class);
    $deduplicated->handle($event, $consumer);
    $deduplicated->handle($event, $consumer);

    Bus::assertDispatched(ProcessDevelopmentExecutionJob::class, 1);
});
