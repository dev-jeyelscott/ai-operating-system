<?php

declare(strict_types=1);

use App\Application\Development\ProcessDevelopmentExecution;
use App\Application\Events\Data\StoredDomainEvent;
use App\Application\QualityAssurance\Consumers\DispatchQualityAssuranceExecution;
use App\Application\QualityAssurance\Contracts\QualityAssuranceExecutionProvider;
use App\Application\QualityAssurance\Data\QaAssessmentResult;
use App\Application\QualityAssurance\Data\QualityAssuranceExecutionRequest;
use App\Application\QualityAssurance\ProcessQualityAssuranceExecution;
use App\Application\QualityAssurance\QualityAssuranceProviderRegistry;
use App\Application\QualityAssurance\StartQualityAssuranceExecution;
use App\Application\Tickets\Data\TicketSelectionRequest;
use App\Application\Tickets\SelectNextTicketAndAcquireLease;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\QualityAssurance\QaDecision;
use App\Domain\Tickets\TicketStatus;
use App\Infrastructure\QualityAssurance\SimulationQualityAssuranceProvider;
use App\Jobs\ProcessQualityAssuranceExecutionJob;
use App\Models\Artifact;
use App\Models\Evidence;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\QaAssessment;
use App\Models\RoadmapTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TicketTestFixture;

/*
 * Create a complete Layer 2 result and queued Layer 3 assessment.
 *
 * @return array<string, mixed>
 */

function aios106Fixture(): array
{
    $fixture = TicketTestFixture::create(
        stableId: 'AIOS-106',
        ticketAttributes: [
            'title' => 'Implement Layer 3 simulation orchestration',
            'objective' => 'Produce an independent simulated QA assessment.',
            'status' => TicketStatus::Ready,
            'desired_state' => TicketStatus::Ready,
            'status_changed_at' => now()->subMinute(),
            'ready_at' => now()->subMinute(),
            'acceptance_criteria' => [
                'Layer 3 creates a separate execution.',
                'The result references Layer 2 evidence.',
            ],
            'evidence_requirements' => [
                'Automated orchestration tests.',
            ],
            'logical_agent' => 'backend_engineer',
            'reasoning_level' => 'high',
        ],
        configurationSnapshot: [
            'policy' => [
                'validation' => [
                    'commands' => [
                        'php artisan test',
                    ],
                ],
            ],
        ],
    );

    $fixture['roadmap']->forceFill([
        'status' => 'approved',
        'approved_fingerprint' => $fixture['roadmap']->candidate_fingerprint,
        'approved_snapshot' => [
            'schema_version' => 1,
        ],
        'approved_at' => now(),
    ])->save();

    $implementationExecution = Execution::factory()
        ->for($fixture['project'])
        ->create([
            'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
            'capability' => 'development.simulation',
            'logical_role' => 'backend_engineer',
            'retry_limit' => 2,
        ]);

    app(SelectNextTicketAndAcquireLease::class)->handle(
        new TicketSelectionRequest(
            organizationId: $fixture['project']->organization_id,
            projectId: $fixture['project']->id,
            executionId: $implementationExecution->id,
            owner: 'aios-106-layer-2-worker',
        ),
    );

    app(ProcessDevelopmentExecution::class)->handle(
        execution: $implementationExecution,
        seed: 106,
    );

    $implementationExecution->refresh();

    $implementationAttempt = ExecutionAttempt::query()
        ->where(
            'execution_id',
            $implementationExecution->id,
        )
        ->latest('attempt_number')
        ->firstOrFail();

    $assessment = app(
        StartQualityAssuranceExecution::class,
    )->handle(
        organizationId: $fixture['project']->organization_id,
        projectId: $fixture['project']->id,
        roadmapTaskId: $fixture['ticket']->id,
        implementationExecutionId: $implementationExecution->id,
        implementationAttemptId: $implementationAttempt->id,
        seed: 106,
    );

    return [
        ...$fixture,
        'implementationExecution' => $implementationExecution,
        'implementationAttempt' => $implementationAttempt,
        'assessment' => $assessment,
        'reviewExecution' => $assessment->reviewExecution,
    ];
}

test(
    'layer 3 simulation completes as a separate unverified execution',
    function (): void {
        $fixture = aios106Fixture();

        app(ProcessQualityAssuranceExecution::class)->handle(
            $fixture['assessment'],
        );

        $assessment =
            $fixture['assessment']->refresh();

        $reviewExecution =
            $fixture['reviewExecution']->refresh();

        expect($reviewExecution->id)
            ->not->toBe(
                $fixture['implementationExecution']->id,
            )
            ->and($reviewExecution->status)
            ->toBe(ExecutionStatus::Completed)
            ->and($reviewExecution->capability)
            ->toBe('quality_assurance.simulation')
            ->and($reviewExecution->logical_role)
            ->toBe('qa_engineer')
            ->and($assessment->status)
            ->toBe(QaAssessment::STATUS_COMPLETED)
            ->and($assessment->decision)
            ->toBe(QaDecision::HumanReviewRequired)
            ->and($assessment->target_branch)
            ->toBe('develop')
            ->and($assessment->evidence_ids)
            ->not->toBeEmpty()
            ->and(
                RoadmapTask::query()
                    ->findOrFail($fixture['ticket']->id)
                    ->status,
            )
            ->toBe(TicketStatus::ForQa);

        $qaArtifact = Artifact::query()
            ->forExecution($reviewExecution->id)
            ->where('artifact_type', 'qa_assessment')
            ->firstOrFail();

        expect($qaArtifact->execution_provider)
            ->toBe('simulation')
            ->and($qaArtifact->actual_state)
            ->toBe('unverified')
            ->and($qaArtifact->evidence_still_required)
            ->toBeTrue()
            ->and($qaArtifact->external_reference)
            ->toStartWith('simulation://');

        $qaEvidence = Evidence::query()
            ->where('artifact_id', $qaArtifact->id)
            ->firstOrFail();

        expect($qaEvidence->classification->value)
            ->toBe('simulated_output');

        $events = DB::table('outbox_messages')
            ->where('execution_id', $reviewExecution->id)
            ->whereIn('event_name', [
                'qa.started',
                'merge_assessment.completed',
            ])
            ->orderBy('sequence')
            ->pluck('event_name')
            ->all();

        expect($events)->toBe([
            'qa.started',
            'merge_assessment.completed',
        ]);
    },
);

test(
    'completed QA job replay creates no duplicate attempt or artifact',
    function (): void {
        $fixture = aios106Fixture();

        $job = new ProcessQualityAssuranceExecutionJob(
            $fixture['assessment']->id,
        );

        $job->handle(
            app(ProcessQualityAssuranceExecution::class),
        );

        $job->handle(
            app(ProcessQualityAssuranceExecution::class),
        );

        expect(
            ExecutionAttempt::query()
                ->where(
                    'execution_id',
                    $fixture['reviewExecution']->id,
                )
                ->count(),
        )->toBe(1);

        expect(
            Artifact::query()
                ->forExecution(
                    $fixture['reviewExecution']->id,
                )
                ->where(
                    'artifact_type',
                    'qa_assessment',
                )
                ->count(),
        )->toBe(1);

        expect(
            QaAssessment::query()
                ->whereKey($fixture['assessment']->id)
                ->count(),
        )->toBe(1);
    },
);

test(
    'QA provider executes outside every database transaction',
    function (): void {
        $fixture = aios106Fixture();
        $startingTransactionLevel =
            DB::transactionLevel();

        $delegate = app(
            SimulationQualityAssuranceProvider::class,
        );

        $provider = new class($delegate) implements QualityAssuranceExecutionProvider
        {
            public ?int $transactionLevel = null;

            public function __construct(
                private SimulationQualityAssuranceProvider $delegate,
            ) {}

            /**
             * Return the simulation provider identifier.
             */
            public function id(): string
            {
                return 'simulation';
            }

            /**
             * Delegate capability support.
             */
            public function supports(string $capability): bool
            {
                return $this->delegate->supports($capability);
            }

            /**
             * Capture the transaction level before delegating.
             */
            public function execute(
                QualityAssuranceExecutionRequest $request,
            ): QaAssessmentResult {
                $this->transactionLevel =
                    DB::transactionLevel();

                return $this->delegate->execute($request);
            }
        };

        app()->instance(
            QualityAssuranceProviderRegistry::class,
            new QualityAssuranceProviderRegistry([$provider]),
        );

        app(ProcessQualityAssuranceExecution::class)->handle(
            $fixture['assessment'],
        );

        expect($provider->transactionLevel)
            ->toBe($startingTransactionLevel);
    },
);

test(
    'provider failure schedules domain retry without persisting a QA report',
    function (): void {
        $fixture = aios106Fixture();

        $provider = new class implements QualityAssuranceExecutionProvider
        {
            /**
             * Return the simulation provider identifier.
             */
            public function id(): string
            {
                return 'simulation';
            }

            /**
             * Support the requested test capability.
             */
            public function supports(string $capability): bool
            {
                return true;
            }

            /**
             * Simulate a transient provider outage.
             */
            public function execute(
                QualityAssuranceExecutionRequest $request,
            ): QaAssessmentResult {
                throw new RuntimeException(
                    'QA provider temporarily failed.',
                );
            }
        };

        app()->instance(
            QualityAssuranceProviderRegistry::class,
            new QualityAssuranceProviderRegistry([$provider]),
        );

        app(ProcessQualityAssuranceExecution::class)->handle(
            $fixture['assessment'],
        );

        expect(
            $fixture['reviewExecution']->refresh()->status,
        )->toBe(ExecutionStatus::RetryScheduled);

        expect(
            $fixture['assessment']->refresh()->status,
        )->toBe(QaAssessment::STATUS_RETRY_SCHEDULED);

        expect(
            Artifact::query()
                ->forExecution(
                    $fixture['reviewExecution']->id,
                )
                ->count(),
        )->toBe(0);
    },
);

test(
    'implementation completion consumer dispatches one unique QA job',
    function (): void {
        $fixture = aios106Fixture();

        Queue::fake([
            ProcessQualityAssuranceExecutionJob::class,
        ]);

        $event = new StoredDomainEvent(
            eventId: '01KYPAB5S2ETWGGMB4TFTVWX1Q',
            eventName: 'implementation.completed',
            organizationId: $fixture['project']->organization_id,
            projectId: $fixture['project']->id,
            schemaVersion: 1,
            envelope: [
                'payload' => [
                    'execution_id' => $fixture['implementationExecution']->id,
                    'attempt_id' => $fixture['implementationAttempt']->id,
                    'roadmap_task_id' => $fixture['ticket']->id,
                ],
            ],
        );

        $consumer = app(
            DispatchQualityAssuranceExecution::class,
        );

        /*
         * Replay the same completion event to prove that the queued job's
         * ShouldBeUnique lock prevents duplicate queue delivery.
         */
        $consumer->handle($event);
        $consumer->handle($event);

        Queue::assertPushed(
            ProcessQualityAssuranceExecutionJob::class,
            static fn (
                ProcessQualityAssuranceExecutionJob $job,
            ): bool => $job->assessmentId
                === $fixture['assessment']->id,
        );

        Queue::assertPushedOnce(
            ProcessQualityAssuranceExecutionJob::class,
        );
    },
);
