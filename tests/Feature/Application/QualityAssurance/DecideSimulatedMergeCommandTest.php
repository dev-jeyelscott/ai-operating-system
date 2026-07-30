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
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Tests\Support\TicketTestFixture;

/**
 * Create one user with an explicit role in the project's organization.
 */
function aios109User(
    int $organizationId,
    OrganizationRole $role,
): User {
    $user = User::factory()->create();

    OrganizationMembership::factory()->create([
        'organization_id' => $organizationId,
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

/**
 * Build a completed Layer 2 and Layer 3 simulation for command tests.
 *
 * @return array<string, mixed>
 */
function aios109CompletedAssessment(
    string $scenario = 'merge_ready_low_risk',
): array {
    $fixture = TicketTestFixture::create(
        stableId: sprintf(
            'AIOS-109-%s',
            Str::lower(Str::random(8)),
        ),
        ticketAttributes: [
            'title' => 'Implement simulated merge decision command',
            'objective' => 'Record an authorized simulated merge disposition.',
            'status' => TicketStatus::Ready,
            'desired_state' => TicketStatus::Ready,
            'status_changed_at' => now()->subMinute(),
            'ready_at' => now()->subMinute(),
            'acceptance_criteria' => [
                'Authorized users may decide the assessment.',
                'Duplicate commands return the first result.',
            ],
            'evidence_requirements' => [
                'Automated merge-decision tests.',
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
            owner: 'aios-109-layer-2-worker',
        ),
    );

    app(ProcessDevelopmentExecution::class)->handle(
        execution: $implementationExecution,
        seed: 109,
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
        scenario: $scenario,
        seed: 109,
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
 * Build a command using the fixture's current canonical assessment.
 *
 * @param  array<string, mixed>  $fixture
 */
function aios109Command(
    array $fixture,
    User $actor,
    MergeDecisionAction $action,
    string $idempotencyKey,
    ?string $reason = null,
): DecideSimulatedMergeCommand {
    /** @var QaAssessment $assessment */
    $assessment = $fixture['assessment'];

    return new DecideSimulatedMergeCommand(
        organizationId: $fixture['project']->organization_id,
        projectId: $fixture['project']->id,
        qaAssessmentId: $assessment->id,
        actorUserId: $actor->id,
        action: $action,
        expectedAssessmentFingerprint: $assessment
            ->canonical_assessment_fingerprint,
        requestIdempotencyKey: $idempotencyKey,
        correlationId: (string) Str::ulid(),
        reason: $reason,
    );
}

test(
    'an owner approves one low-risk simulated merge idempotently',
    function (): void {
        $fixture = aios109CompletedAssessment();

        $owner = aios109User(
            organizationId: $fixture['project']->organization_id,
            role: OrganizationRole::Owner,
        );

        $command = aios109Command(
            fixture: $fixture,
            actor: $owner,
            action: MergeDecisionAction::Approve,
            idempotencyKey: sprintf(
                'aios-109-approve:%s',
                Str::ulid(),
            ),
            reason: 'The simulated residual risk is accepted.',
        );

        $bus = app(CommandBus::class);

        $first = $bus->dispatch($command);
        $replay = $bus->dispatch($command);

        expect($first->status)
            ->toBe(CommandResultStatus::Succeeded)
            ->and($first->data['replayed'])
            ->toBeFalse()
            ->and($first->data['simulated'])
            ->toBeTrue()
            ->and($first->data['actual_state'])
            ->toBe('unverified')
            ->and($first->data['real_merge_performed'])
            ->toBeFalse()
            ->and($replay->status)
            ->toBe(CommandResultStatus::Succeeded)
            ->and($replay->data['merge_decision_id'])
            ->toBe($first->data['merge_decision_id'])
            ->and($replay->data['replayed'])
            ->toBeTrue();

        expect(
            RoadmapTask::query()
                ->findOrFail($fixture['ticket']->id)
                ->status,
        )->toBe(TicketStatus::ApprovedForMerge);

        $this->assertDatabaseCount(
            'merge_decisions',
            1,
        );

        $this->assertDatabaseHas(
            'merge_decisions',
            [
                'id' => $first->data['merge_decision_id'],
                'project_id' => $fixture['project']->id,
                'qa_assessment_id' => $fixture['assessment']->id,
                'action' => 'approve',
                'terminal_marker' => 'T',
                'simulated' => true,
                'actual_state' => 'unverified',
                'ticket_status_after' => TicketStatus::ApprovedForMerge->value,
            ],
        );

        $this->assertDatabaseHas(
            'outbox_messages',
            [
                'event_name' => 'simulated_merge.approved',
                'aggregate_type' => 'roadmap_task',
                'aggregate_id' => (string) $fixture['ticket']->id,
            ],
        );

        $this->assertDatabaseHas(
            'audit_events',
            [
                'event_type' => 'simulated_merge.approved',
                'actor_type' => 'user',
                'actor_id' => (string) $owner->id,
                'subject_type' => 'roadmap_task',
                'subject_id' => (string) $fixture['ticket']->id,
            ],
        );
    },
);

test(
    'request changes creates one terminal decision and changes ticket status',
    function (): void {
        $fixture = aios109CompletedAssessment();

        $owner = aios109User(
            organizationId: $fixture['project']->organization_id,
            role: OrganizationRole::Owner,
        );

        $bus = app(CommandBus::class);

        $first = $bus->dispatch(aios109Command(
            fixture: $fixture,
            actor: $owner,
            action: MergeDecisionAction::RequestChanges,
            idempotencyKey: sprintf(
                'aios-109-request-changes:%s',
                Str::ulid(),
            ),
            reason: 'Add the missing negative-path coverage.',
        ));

        $conflict = $bus->dispatch(aios109Command(
            fixture: $fixture,
            actor: $owner,
            action: MergeDecisionAction::Approve,
            idempotencyKey: sprintf(
                'aios-109-late-approve:%s',
                Str::ulid(),
            ),
            reason: 'Attempt to replace the terminal decision.',
        ));

        expect($first->status)
            ->toBe(CommandResultStatus::Succeeded)
            ->and($conflict->status)
            ->toBe(CommandResultStatus::Conflict);

        expect(
            RoadmapTask::query()
                ->findOrFail($fixture['ticket']->id)
                ->status,
        )->toBe(TicketStatus::ChangesRequested);

        $this->assertDatabaseCount(
            'merge_decisions',
            1,
        );
    },
);

test(
    'escalate and defer remain non-terminal',
    function (
        MergeDecisionAction $action,
        ?string $reason,
    ): void {
        $fixture = aios109CompletedAssessment(
            scenario: 'human_review_required',
        );

        $owner = aios109User(
            organizationId: $fixture['project']->organization_id,
            role: OrganizationRole::Owner,
        );

        $result = app(CommandBus::class)->dispatch(
            aios109Command(
                fixture: $fixture,
                actor: $owner,
                action: $action,
                idempotencyKey: sprintf(
                    'aios-109-non-terminal:%s',
                    Str::ulid(),
                ),
                reason: $reason,
            ),
        );

        expect($result->status)
            ->toBe(CommandResultStatus::Succeeded);

        expect(
            RoadmapTask::query()
                ->findOrFail($fixture['ticket']->id)
                ->status,
        )->toBe(TicketStatus::ForQa);

        $decision = MergeDecision::query()
            ->findOrFail(
                $result->data['merge_decision_id'],
            );

        expect($decision->terminal_marker)
            ->toBeNull()
            ->and($decision->actual_state)
            ->toBe('unverified');
    },
)->with([
    'escalate' => [
        MergeDecisionAction::Escalate,
        'A senior reviewer must evaluate the residual risk.',
    ],
    'defer' => [
        MergeDecisionAction::Defer,
        null,
    ],
]);

test(
    'idempotency key payload drift is rejected',
    function (): void {
        $fixture = aios109CompletedAssessment(
            scenario: 'human_review_required',
        );

        $owner = aios109User(
            organizationId: $fixture['project']->organization_id,
            role: OrganizationRole::Owner,
        );

        $key = sprintf(
            'aios-109-drift:%s',
            Str::ulid(),
        );

        $bus = app(CommandBus::class);

        $first = $bus->dispatch(aios109Command(
            fixture: $fixture,
            actor: $owner,
            action: MergeDecisionAction::Defer,
            idempotencyKey: $key,
            reason: 'Review next week.',
        ));

        $drift = $bus->dispatch(aios109Command(
            fixture: $fixture,
            actor: $owner,
            action: MergeDecisionAction::Defer,
            idempotencyKey: $key,
            reason: 'Review next month.',
        ));

        expect($first->status)
            ->toBe(CommandResultStatus::Succeeded)
            ->and($drift->status)
            ->toBe(CommandResultStatus::Conflict);

        $this->assertDatabaseCount(
            'merge_decisions',
            1,
        );
    },
);

test(
    'an organization member cannot decide a simulated merge',
    function (): void {
        $fixture = aios109CompletedAssessment();

        $member = aios109User(
            organizationId: $fixture['project']->organization_id,
            role: OrganizationRole::Member,
        );

        expect(
            fn () => app(CommandBus::class)->dispatch(
                aios109Command(
                    fixture: $fixture,
                    actor: $member,
                    action: MergeDecisionAction::Defer,
                    idempotencyKey: sprintf(
                        'aios-109-unauthorized:%s',
                        Str::ulid(),
                    ),
                ),
            ),
        )->toThrow(
            AuthorizationException::class,
        );

        $this->assertDatabaseCount(
            'merge_decisions',
            0,
        );
    },
);

test(
    'a non-merge-ready QA recommendation cannot be approved',
    function (): void {
        $fixture = aios109CompletedAssessment(
            scenario: 'human_review_required',
        );

        $owner = aios109User(
            organizationId: $fixture['project']->organization_id,
            role: OrganizationRole::Owner,
        );

        $result = app(CommandBus::class)->dispatch(
            aios109Command(
                fixture: $fixture,
                actor: $owner,
                action: MergeDecisionAction::Approve,
                idempotencyKey: sprintf(
                    'aios-109-invalid-approve:%s',
                    Str::ulid(),
                ),
            ),
        );

        expect($result->status)
            ->toBe(CommandResultStatus::Conflict);

        expect(
            RoadmapTask::query()
                ->findOrFail($fixture['ticket']->id)
                ->status,
        )->toBe(TicketStatus::ForQa);

        $this->assertDatabaseCount(
            'merge_decisions',
            0,
        );
    },
);
