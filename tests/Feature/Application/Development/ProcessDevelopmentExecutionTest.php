<?php

declare(strict_types=1);

use App\Application\Development\Consumers\DispatchDevelopmentExecution;
use App\Application\Development\Contracts\DevelopmentExecutionProvider;
use App\Application\Development\Data\DevelopmentExecutionRequest;
use App\Application\Development\Data\DevelopmentExecutionResult;
use App\Application\Development\DevelopmentArtifactRecorder;
use App\Application\Development\DevelopmentProviderRegistry;
use App\Application\Development\ProcessDevelopmentExecution;
use App\Application\Events\Data\StoredDomainEvent;
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
function aios096Fixture(): array
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
        'capability' => 'development.simulation', 'retry_limit' => 2,
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
