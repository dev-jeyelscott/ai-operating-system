<?php

declare(strict_types=1);

use App\Application\Development\ProcessDevelopmentExecution;
use App\Application\QualityAssurance\Commands\DecideSimulatedMergeCommand;
use App\Application\QualityAssurance\ProcessQualityAssuranceExecution;
use App\Application\QualityAssurance\StartQualityAssuranceExecution;
use App\Application\Shared\Commands\CommandBus;
use App\Application\Shared\Commands\CommandResultStatus;
use App\Application\Tickets\Data\TicketSelectionRequest;
use App\Application\Tickets\SelectNextTicketAndAcquireLease;
use App\Domain\Identity\OrganizationRole;
use App\Domain\QualityAssurance\MergeDecisionAction;
use App\Domain\Tickets\TicketStatus;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\MergeDecision;
use App\Models\OrganizationMembership;
use App\Models\QaAssessment;
use App\Models\RoadmapTask;
use App\Models\TicketExecutionLease;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\TicketTestFixture;

/**
 * Create an organization owner authorized to make simulated merge decisions.
 */
function aios110Owner(
    int $organizationId,
): User {
    $owner = User::factory()->create();

    OrganizationMembership::factory()->create([
        'organization_id' => $organizationId,
        'user_id' => $owner->id,
        'role' => OrganizationRole::Owner,
    ]);

    return $owner;
}

/**
 * Mark the fixture roadmap as the current approved execution roadmap.
 *
 * @param  array<string, mixed>  $fixture
 */
function aios110ApproveRoadmap(
    array $fixture,
): void {
    $fixture['roadmap']->forceFill([
        'status' => 'approved',
        'approved_fingerprint' => $fixture['roadmap']->candidate_fingerprint,
        'approved_snapshot' => [
            'schema_version' => 1,
        ],
        'approved_at' => now(),
    ])->save();
}

/**
 * Build one complete Layer 2 and Layer 3 simulation ready for a human
 * request-changes decision.
 *
 * @return array<string, mixed>
 */
function aios110CompletedAssessment(): array
{
    $fixture = TicketTestFixture::create(
        stableId: sprintf(
            'AIOS-110-%s',
            Str::lower(Str::random(8)),
        ),
        ticketAttributes: [
            'title' => 'Implement changes-requested loop',
            'objective' => 'Return requested QA changes to another Layer 2 cycle.',
            'status' => TicketStatus::Ready,
            'desired_state' => TicketStatus::Ready,
            'status_changed_at' => now()->subMinute(),
            'ready_at' => now()->subMinute(),
            'acceptance_criteria' => [
                'The ticket can be re-leased after changes are requested.',
                'Previous execution and QA history remains available.',
            ],
            'evidence_requirements' => [
                'Automated changes-requested loop tests.',
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

    aios110ApproveRoadmap($fixture);

    $implementationExecution = Execution::factory()
        ->for($fixture['project'])
        ->create([
            'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
            'capability' => 'development.simulation',
            'logical_role' => 'backend_engineer',
            'retry_limit' => 2,
        ]);

    $selection = app(
        SelectNextTicketAndAcquireLease::class,
    )->handle(
        new TicketSelectionRequest(
            organizationId: $fixture['project']->organization_id,
            projectId: $fixture['project']->id,
            executionId: $implementationExecution->id,
            owner: 'aios-110-initial-layer-2-worker',
        ),
    );

    expect($selection->isSelected())
        ->toBeTrue()
        ->and($selection->roadmapTaskId)
        ->toBe($fixture['ticket']->id);

    app(ProcessDevelopmentExecution::class)->handle(
        execution: $implementationExecution,
        seed: 110,
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
        scenario: 'merge_ready_low_risk',
        seed: 110,
    );

    app(ProcessQualityAssuranceExecution::class)->handle(
        $assessment,
    );

    return [
        ...$fixture,
        'implementationExecution' => $implementationExecution->refresh(),
        'implementationAttempt' => $implementationAttempt->refresh(),
        'assessment' => $assessment->refresh(),
    ];
}

/**
 * Build the terminal request-changes command for the completed assessment.
 *
 * @param  array<string, mixed>  $fixture
 */
function aios110RequestChangesCommand(
    array $fixture,
    User $owner,
): DecideSimulatedMergeCommand {
    /** @var QaAssessment $assessment */
    $assessment = $fixture['assessment'];

    return new DecideSimulatedMergeCommand(
        organizationId: $fixture['project']->organization_id,
        projectId: $fixture['project']->id,
        qaAssessmentId: $assessment->id,
        actorUserId: $owner->id,
        action: MergeDecisionAction::RequestChanges,
        expectedAssessmentFingerprint: $assessment->canonical_assessment_fingerprint,
        requestIdempotencyKey: sprintf(
            'aios-110-request-changes:%s',
            Str::ulid(),
        ),
        correlationId: (string) Str::ulid(),
        reason: 'Add the missing negative-path coverage and repeat QA.',
    );
}

test(
    'request changes authorizes a new lease and preserves previous attempts',
    function (): void {
        $fixture = aios110CompletedAssessment();

        $owner = aios110Owner(
            $fixture['project']->organization_id,
        );

        $decisionResult = app(CommandBus::class)->dispatch(
            aios110RequestChangesCommand(
                fixture: $fixture,
                owner: $owner,
            ),
        );

        expect($decisionResult->status)
            ->toBe(CommandResultStatus::Succeeded)
            ->and($decisionResult->data['ticket_status'])
            ->toBe(TicketStatus::ChangesRequested->value)
            ->and($decisionResult->data['simulated'])
            ->toBeTrue()
            ->and($decisionResult->data['actual_state'])
            ->toBe('unverified')
            ->and($decisionResult->data['real_merge_performed'])
            ->toBeFalse();

        $ticket = RoadmapTask::query()
            ->findOrFail($fixture['ticket']->id);

        expect($ticket->status)
            ->toBe(TicketStatus::ChangesRequested);

        $initialLease = TicketExecutionLease::query()
            ->where(
                'execution_id',
                $fixture['implementationExecution']->id,
            )
            ->firstOrFail();

        expect($initialLease->released_at)
            ->not->toBeNull();

        $mergeDecision = MergeDecision::query()
            ->forProject($fixture['project']->id)
            ->authorizesChangesRequestedRework()
            ->where(
                'roadmap_task_id',
                $ticket->id,
            )
            ->firstOrFail();

        /*
         * A new logical execution owns the rework. The previous execution is
         * never reopened or mutated.
         */
        $reworkExecution = Execution::factory()
            ->for($fixture['project'])
            ->create([
                'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
                'capability' => 'development.simulation',
                'logical_role' => 'backend_engineer',
                'retry_limit' => 2,
            ]);

        $reworkSelectionRequest =
            new TicketSelectionRequest(
                organizationId: $fixture['project']->organization_id,
                projectId: $fixture['project']->id,
                executionId: $reworkExecution->id,
                owner: 'aios-110-rework-layer-2-worker',
            );

        $reworkSelection = app(
            SelectNextTicketAndAcquireLease::class,
        )->handle(
            $reworkSelectionRequest,
        );

        $replayedSelection = app(
            SelectNextTicketAndAcquireLease::class,
        )->handle(
            $reworkSelectionRequest,
        );

        expect($reworkSelection->isSelected())
            ->toBeTrue()
            ->and($reworkSelection->roadmapTaskId)
            ->toBe($ticket->id)
            ->and($reworkSelection->leaseId)
            ->not->toBe($initialLease->id)
            ->and($replayedSelection->leaseId)
            ->toBe($reworkSelection->leaseId);

        app(ProcessDevelopmentExecution::class)->handle(
            execution: $reworkExecution,
            seed: 1110,
        );

        $reworkExecution->refresh();

        $reworkAttempt = ExecutionAttempt::query()
            ->where(
                'execution_id',
                $reworkExecution->id,
            )
            ->latest('attempt_number')
            ->firstOrFail();

        $ticket->refresh();

        expect($ticket->status)
            ->toBe(TicketStatus::ForQa)
            ->and($reworkExecution->id)
            ->not->toBe(
                $fixture['implementationExecution']->id,
            )
            ->and($reworkAttempt->id)
            ->not->toBe(
                $fixture['implementationAttempt']->id,
            );

        /*
         * Start a second independent QA assessment to prove that the complete
         * changes-requested loop returns to Layer 3.
         */
        $reworkAssessment = app(
            StartQualityAssuranceExecution::class,
        )->handle(
            organizationId: $fixture['project']->organization_id,
            projectId: $fixture['project']->id,
            roadmapTaskId: $ticket->id,
            implementationExecutionId: $reworkExecution->id,
            implementationAttemptId: $reworkAttempt->id,
            scenario: 'merge_ready_low_risk',
            seed: 1110,
        );

        app(ProcessQualityAssuranceExecution::class)->handle(
            $reworkAssessment,
        );

        $reworkAssessment->refresh();

        expect($reworkAssessment->status)
            ->toBe(QaAssessment::STATUS_COMPLETED)
            ->and($reworkAssessment->id)
            ->not->toBe($fixture['assessment']->id);

        /*
         * Every prior execution, attempt, lease, QA result, and human decision
         * remains durable and independently addressable.
         */
        $this->assertModelExists(
            $fixture['implementationExecution'],
        );

        $this->assertModelExists(
            $fixture['implementationAttempt'],
        );

        $this->assertModelExists(
            $fixture['assessment'],
        );

        $this->assertModelExists(
            $mergeDecision,
        );

        $this->assertModelExists(
            $reworkExecution,
        );

        $this->assertModelExists(
            $reworkAttempt,
        );

        $this->assertModelExists(
            $reworkAssessment,
        );

        expect(
            ExecutionAttempt::query()
                ->whereIn(
                    'execution_id',
                    [
                        $fixture['implementationExecution']->id,
                        $reworkExecution->id,
                    ],
                )
                ->count(),
        )->toBe(2);

        expect(
            QaAssessment::query()
                ->where(
                    'roadmap_task_id',
                    $ticket->id,
                )
                ->count(),
        )->toBe(2);

        expect(
            TicketExecutionLease::query()
                ->where(
                    'roadmap_task_id',
                    $ticket->id,
                )
                ->count(),
        )->toBe(2);

        expect(
            MergeDecision::query()
                ->where(
                    'roadmap_task_id',
                    $ticket->id,
                )
                ->count(),
        )->toBe(1);

        $this->assertDatabaseHas(
            'merge_decisions',
            [
                'id' => $mergeDecision->id,
                'project_id' => $fixture['project']->id,
                'roadmap_task_id' => $ticket->id,
                'qa_assessment_id' => $fixture['assessment']->id,
                'action' => MergeDecisionAction::RequestChanges->value,
                'ticket_status_before' => TicketStatus::ForQa->value,
                'ticket_status_after' => TicketStatus::ChangesRequested->value,
                'terminal_marker' => 'T',
                'simulated' => true,
                'actual_state' => 'unverified',
            ],
        );
    },
);

test(
    'changes requested without an authorized decision remains ineligible',
    function (): void {
        $fixture = TicketTestFixture::create(
            stableId: sprintf(
                'AIOS-110-NO-DECISION-%s',
                Str::lower(Str::random(8)),
            ),
            ticketAttributes: [
                'title' => 'Unapproved changes-requested ticket',
                'objective' => 'Remain unavailable until rework is authorized.',
                'status' => TicketStatus::ChangesRequested,
                'desired_state' => TicketStatus::ChangesRequested,
                'status_changed_at' => now()->subMinute(),
                'ready_at' => now()->subMinute(),
                'logical_agent' => 'backend_engineer',
                'reasoning_level' => 'high',
            ],
        );

        aios110ApproveRoadmap($fixture);

        $execution = Execution::factory()
            ->for($fixture['project'])
            ->create([
                'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
                'capability' => 'development.simulation',
                'logical_role' => 'backend_engineer',
                'retry_limit' => 2,
            ]);

        $selection = app(
            SelectNextTicketAndAcquireLease::class,
        )->handle(
            new TicketSelectionRequest(
                organizationId: $fixture['project']->organization_id,
                projectId: $fixture['project']->id,
                executionId: $execution->id,
                owner: 'aios-110-unapproved-worker',
            ),
        );

        expect($selection->isSelected())
            ->toBeFalse()
            ->and($selection->roadmapTaskId)
            ->toBeNull()
            ->and($selection->leaseId)
            ->toBeNull();

        $this->assertDatabaseMissing(
            'ticket_execution_leases',
            [
                'execution_id' => $execution->id,
                'roadmap_task_id' => $fixture['ticket']->id,
            ],
        );
    },
);
