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
use App\Domain\QualityAssurance\MergeDecisionAction;
use App\Domain\QualityAssurance\QaDecision;
use App\Domain\Tickets\TicketStatus;
use App\Infrastructure\QualityAssurance\QaScenarioCatalog;
use App\Models\AuditEvent;
use App\Models\Evidence;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\MergeDecision;
use App\Models\OutboxMessage;
use App\Models\QaAssessment;
use Illuminate\Support\Str;
use Tests\Support\CompletedQualityAssuranceFixture;

/**
 * @param  array<string, mixed>  $fixture
 */
function phaseEightCommand(
    array $fixture,
    int $actorUserId,
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
        actorUserId: $actorUserId,
        action: $action,
        expectedAssessmentFingerprint: $assessment
            ->canonical_assessment_fingerprint,
        requestIdempotencyKey: $idempotencyKey,
        correlationId: (string) Str::ulid(),
        reason: $reason,
    );
}

test('Layer 3 remains independent and reviews the selected Layer 2 attempt evidence', function (): void {
    $fixture = CompletedQualityAssuranceFixture::create();
    /** @var QaAssessment $assessment */
    $assessment = $fixture['assessment'];
    $assessment->load('reviewExecution', 'reviewAttempt');

    expect($assessment->implementation_execution_id)
        ->not->toBe($assessment->review_execution_id)
        ->and($assessment->implementation_attempt_id)
        ->not->toBe($assessment->review_attempt_id)
        ->and($assessment->reviewExecution->logical_role)
        ->not->toBe($fixture['implementationExecution']->logical_role);

    $reviewedAttemptIds = Evidence::query()
        ->whereIn('id', $assessment->evidence_ids)
        ->with('artifact:id,execution_attempt_id')
        ->get()
        ->pluck('artifact.execution_attempt_id')
        ->unique()
        ->values()
        ->all();

    expect($reviewedAttemptIds)->toBe([
        $fixture['implementationAttempt']->id,
    ]);
});

test('blocking policy rejects approval without creating terminal state', function (): void {
    $fixture = CompletedQualityAssuranceFixture::create(
        QaScenarioCatalog::MERGE_READY_LOW_RISK,
    );
    $owner = CompletedQualityAssuranceFixture::user(
        $fixture['project']->organization_id,
    );
    QaAssessment::query()
        ->whereKey($fixture['assessment']->id)
        ->update([
            'unresolved_findings' => [[
                'code' => 'QA-BLOCK-PERSISTED-001',
                'dimension' => 'acceptance_criteria',
                'severity' => 'critical',
                'blocking' => true,
                'summary' => 'Persisted acceptance evidence blocks approval.',
                'impact' => 'The simulated merge cannot be approved safely.',
                'mitigation' => 'Resolve the finding and run a distinct QA assessment.',
                'evidence_ids' => $fixture['assessment']->evidence_ids,
            ]],
        ]);
    $fixture['assessment']->refresh();

    expect($fixture['assessment']->decision)->toBe(QaDecision::MergeReady);

    $result = app(CommandBus::class)->dispatch(phaseEightCommand(
        fixture: $fixture,
        actorUserId: $owner->id,
        action: MergeDecisionAction::Approve,
        idempotencyKey: sprintf('phase-eight:block:%s', Str::ulid()),
    ));

    expect($result->status)->toBe(CommandResultStatus::Conflict)
        ->and($fixture['ticket']->refresh()->status)->toBe(TicketStatus::ForQa);

    $this->assertDatabaseCount('merge_decisions', 0);
});

test('high-risk escalation is nonterminal and duplicate-safe', function (): void {
    $fixture = CompletedQualityAssuranceFixture::create(
        QaScenarioCatalog::MERGE_READY_HIGH_RISK,
    );
    $owner = CompletedQualityAssuranceFixture::user(
        $fixture['project']->organization_id,
    );
    $idempotencyKey = sprintf('phase-eight:escalate:%s', Str::ulid());
    $command = phaseEightCommand(
        fixture: $fixture,
        actorUserId: $owner->id,
        action: MergeDecisionAction::Escalate,
        idempotencyKey: $idempotencyKey,
        reason: 'High rollback complexity requires release-owner review.',
    );
    $bus = app(CommandBus::class);

    $first = $bus->dispatch($command);
    $replay = $bus->dispatch($command);
    $conflictingReplay = $bus->dispatch(phaseEightCommand(
        fixture: $fixture,
        actorUserId: $owner->id,
        action: MergeDecisionAction::Escalate,
        idempotencyKey: $idempotencyKey,
        reason: 'A changed request must not reuse an idempotency key.',
    ));

    expect($first->status)->toBe(CommandResultStatus::Succeeded)
        ->and($replay->toArray())->toBe($first->toArray())
        ->and($conflictingReplay->status)->toBe(CommandResultStatus::Conflict)
        ->and($fixture['ticket']->refresh()->status)->toBe(TicketStatus::ForQa)
        ->and(MergeDecision::query()->sole()->terminal_marker)->toBeNull();

    $this->assertDatabaseCount('merge_decisions', 1);
    expect(AuditEvent::query()
        ->where('project_id', $fixture['project']->id)
        ->where('event_type', 'simulated_merge.escalated')
        ->count())->toBe(1)
        ->and(OutboxMessage::query()
            ->where('project_id', $fixture['project']->id)
            ->where('event_name', 'simulated_merge.escalated')
            ->count())->toBe(1);

    $deferred = $bus->dispatch(phaseEightCommand(
        fixture: $fixture,
        actorUserId: $owner->id,
        action: MergeDecisionAction::Defer,
        idempotencyKey: sprintf('phase-eight:defer:%s', Str::ulid()),
    ));

    expect($deferred->status)->toBe(CommandResultStatus::Succeeded)
        ->and($fixture['ticket']->refresh()->status)->toBe(TicketStatus::ForQa)
        ->and(MergeDecision::query()->count())->toBe(2);
});

test('request changes permits distinct rework while preserving prior history', function (): void {
    $fixture = CompletedQualityAssuranceFixture::create();
    $owner = CompletedQualityAssuranceFixture::user(
        $fixture['project']->organization_id,
    );
    $priorEvidence = Evidence::query()
        ->whereIn('id', $fixture['assessment']->evidence_ids)
        ->get();
    $result = app(CommandBus::class)->dispatch(phaseEightCommand(
        fixture: $fixture,
        actorUserId: $owner->id,
        action: MergeDecisionAction::RequestChanges,
        idempotencyKey: sprintf('phase-eight:changes:%s', Str::ulid()),
        reason: 'Add negative-path evidence and repeat QA.',
    ));

    expect($result->status)->toBe(CommandResultStatus::Succeeded)
        ->and($fixture['ticket']->refresh()->status)
        ->toBe(TicketStatus::ChangesRequested);

    $priorDecision = MergeDecision::query()->sole();
    $priorAuditEvent = AuditEvent::query()
        ->where('project_id', $fixture['project']->id)
        ->where('event_type', 'simulated_merge.changes_requested')
        ->sole();
    $priorOutboxMessage = OutboxMessage::query()
        ->where('project_id', $fixture['project']->id)
        ->where('event_name', 'simulated_merge.changes_requested')
        ->sole();

    $reworkExecution = Execution::factory()
        ->for($fixture['project'])
        ->create([
            'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
            'capability' => 'development.simulation',
            'logical_role' => 'backend_engineer',
        ]);
    $selection = app(SelectNextTicketAndAcquireLease::class)->handle(
        new TicketSelectionRequest(
            organizationId: $fixture['project']->organization_id,
            projectId: $fixture['project']->id,
            executionId: $reworkExecution->id,
            owner: 'phase-eight-rework-worker',
        ),
    );

    expect($selection->roadmapTaskId)->toBe($fixture['ticket']->id);

    app(ProcessDevelopmentExecution::class)->handle(
        execution: $reworkExecution,
        seed: 1112,
    );

    $reworkAttempt = ExecutionAttempt::query()
        ->where('execution_id', $reworkExecution->id)
        ->latest('attempt_number')
        ->firstOrFail();

    expect($fixture['ticket']->refresh()->status)->toBe(TicketStatus::ForQa)
        ->and($reworkExecution->id)
        ->not->toBe($fixture['implementationExecution']->id)
        ->and($reworkAttempt->id)
        ->not->toBe($fixture['implementationAttempt']->id);

    $reworkAssessment = app(StartQualityAssuranceExecution::class)->handle(
        organizationId: $fixture['project']->organization_id,
        projectId: $fixture['project']->id,
        roadmapTaskId: $fixture['ticket']->id,
        implementationExecutionId: $reworkExecution->id,
        implementationAttemptId: $reworkAttempt->id,
        scenario: QaScenarioCatalog::MERGE_READY_LOW_RISK,
        seed: 1113,
    );

    app(ProcessQualityAssuranceExecution::class)->handle($reworkAssessment);

    expect($reworkAssessment->refresh()->status)
        ->toBe(QaAssessment::STATUS_COMPLETED)
        ->and($reworkAssessment->id)
        ->not->toBe($fixture['assessment']->id);

    $this->assertModelExists($fixture['implementationExecution']);
    $this->assertModelExists($fixture['implementationAttempt']);
    $this->assertModelExists($fixture['assessment']);
    $this->assertModelExists($priorDecision);
    $this->assertModelExists($priorAuditEvent);
    $this->assertModelExists($priorOutboxMessage);

    $priorEvidence->each(
        fn (Evidence $evidence) => $this->assertModelExists($evidence),
    );

    $this->assertModelExists($reworkAssessment);
});
