<?php

declare(strict_types=1);

use App\Application\Executions\Data\ExecutionAttemptContext;
use App\Application\Executions\DeterministicRetryDelay;
use App\Application\Executions\ExecutionResilienceManager;
use App\Domain\Executions\Exceptions\ExecutionLifecycleConflict;
use App\Domain\Executions\ExecutionAttemptStatus;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Models\Execution;
use App\Models\OutboxMessage;
use Carbon\CarbonImmutable;

/**
 * Build the standard deterministic simulation context for AIOS-055 tests.
 */
function aios055AttemptContext(): ExecutionAttemptContext
{
    return new ExecutionAttemptContext(
        executionProvider: 'simulation',
        modelIdentifier: 'simulation-v1',
        requestedReasoningLevel: ReasoningLevel::Medium,
        effectiveReasoningLevel: ReasoningLevel::Medium,
        reasoningResolutionSource: 'project_default',
        simulationMode: 'deterministic',
        simulationSeed: 'aios-055',
    );
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('atomically allocates one running attempt', function (): void {
    $at = CarbonImmutable::parse(
        '2026-07-26 08:00:00 UTC',
    );

    $execution = Execution::factory()->create([
        'retry_limit' => 2,
        'timeout_seconds' => 120,
    ]);

    $attempt = app(
        ExecutionResilienceManager::class,
    )->startAttempt(
        execution: $execution,
        context: aios055AttemptContext(),
        at: $at,
    );

    $execution->refresh();
    $attempt->refresh();

    expect($execution->status)
        ->toBe(ExecutionStatus::Running)
        ->and($execution->attempt_count)
        ->toBe(1)
        ->and($attempt->status)
        ->toBe(ExecutionAttemptStatus::Running)
        ->and($attempt->attempt_number)
        ->toBe(1)
        ->and($attempt->started_at?->equalTo($at))
        ->toBeTrue()
        ->and(
            $attempt->deadline_at?->equalTo(
                $at->addSeconds(120),
            ),
        )
        ->toBeTrue()
        ->and(
            OutboxMessage::query()
                ->where(
                    'event_name',
                    'execution.attempt.started',
                )
                ->count(),
        )
        ->toBe(1);
});

it('rejects a second start from stale execution state', function (): void {
    $execution = Execution::factory()->create();

    $firstReference = Execution::query()
        ->findOrFail($execution->id);

    $staleReference = Execution::query()
        ->findOrFail($execution->id);

    $manager = app(ExecutionResilienceManager::class);

    $manager->startAttempt(
        execution: $firstReference,
        context: aios055AttemptContext(),
    );

    expect(
        fn () => $manager->startAttempt(
            execution: $staleReference,
            context: aios055AttemptContext(),
        ),
    )->toThrow(
        ExecutionLifecycleConflict::class,
        'must be queued',
    );

    expect(
        $execution->attempts()->count(),
    )->toBe(1);
});

it('schedules bounded exponential retries', function (): void {
    $manager = app(ExecutionResilienceManager::class);

    $startedAt = CarbonImmutable::parse(
        '2026-07-26 08:00:00 UTC',
    );

    $execution = Execution::factory()->create([
        'retry_limit' => 2,
        'timeout_seconds' => 120,
        'retry_base_delay_seconds' => 30,
        'retry_max_delay_seconds' => 300,
        'retry_jitter_percent' => 0,
    ]);

    $firstAttempt = $manager->startAttempt(
        execution: $execution,
        context: aios055AttemptContext(),
        at: $startedAt,
    );

    $firstFailureAt = $startedAt->addSeconds(5);

    $firstDecision = $manager->failAttempt(
        attempt: $firstAttempt,
        errorCode: 'provider.unavailable',
        errorMessage: 'The simulated provider is unavailable.',
        retryable: true,
        at: $firstFailureAt,
    );

    expect($firstDecision->executionStatus)
        ->toBe(ExecutionStatus::RetryScheduled)
        ->and($firstDecision->delaySeconds)
        ->toBe(30)
        ->and(
            $firstDecision->nextAttemptAt?->equalTo(
                $firstFailureAt->addSeconds(30),
            ),
        )
        ->toBeTrue();

    $manager->releaseDueRetries(
        at: $firstFailureAt->addSeconds(30),
    );

    $execution->refresh();

    expect($execution->status)
        ->toBe(ExecutionStatus::Queued);

    $secondAttempt = $manager->startAttempt(
        execution: $execution,
        context: aios055AttemptContext(),
        at: $firstFailureAt->addSeconds(30),
    );

    $secondFailureAt =
        $firstFailureAt->addSeconds(35);

    $secondDecision = $manager->failAttempt(
        attempt: $secondAttempt,
        errorCode: 'provider.unavailable',
        errorMessage: 'The simulated provider is unavailable.',
        retryable: true,
        at: $secondFailureAt,
    );

    expect($secondAttempt->fresh()?->attempt_number)
        ->toBe(2)
        ->and($secondDecision->delaySeconds)
        ->toBe(60);
});

it('fails after the automatic retry limit is exhausted', function (): void {
    $manager = app(ExecutionResilienceManager::class);

    $execution = Execution::factory()->create([
        'retry_limit' => 0,
        'retry_jitter_percent' => 0,
    ]);

    $attempt = $manager->startAttempt(
        execution: $execution,
        context: aios055AttemptContext(),
    );

    $decision = $manager->failAttempt(
        attempt: $attempt,
        errorCode: 'provider.failed',
        errorMessage: 'The provider failed.',
        retryable: true,
    );

    $execution->refresh();

    expect($decision->executionStatus)
        ->toBe(ExecutionStatus::Failed)
        ->and($decision->nextAttemptAt)
        ->toBeNull()
        ->and($execution->status)
        ->toBe(ExecutionStatus::Failed)
        ->and($execution->finished_at)
        ->not->toBeNull();
});

it('times out expired attempts and schedules retry', function (): void {
    $manager = app(ExecutionResilienceManager::class);

    $startedAt = CarbonImmutable::parse(
        '2026-07-26 08:00:00 UTC',
    );

    $execution = Execution::factory()->create([
        'retry_limit' => 1,
        'timeout_seconds' => 60,
        'retry_base_delay_seconds' => 30,
        'retry_max_delay_seconds' => 300,
        'retry_jitter_percent' => 0,
    ]);

    $attempt = $manager->startAttempt(
        execution: $execution,
        context: aios055AttemptContext(),
        at: $startedAt,
    );

    $processed = $manager->processExpiredAttempts(
        at: $startedAt->addSeconds(61),
    );

    $execution->refresh();
    $attempt->refresh();

    expect($processed)
        ->toBe(1)
        ->and($attempt->status)
        ->toBe(ExecutionAttemptStatus::TimedOut)
        ->and($attempt->error_code)
        ->toBe('execution.timeout')
        ->and($execution->status)
        ->toBe(ExecutionStatus::RetryScheduled);
});

it('cancels queued executions immediately', function (): void {
    $execution = Execution::factory()->create();

    $changed = app(
        ExecutionResilienceManager::class,
    )->requestCancellation(
        execution: $execution,
        reason: 'The user stopped the workflow.',
    );

    $execution->refresh();

    expect($changed)
        ->toBeTrue()
        ->and($execution->status)
        ->toBe(ExecutionStatus::Cancelled)
        ->and($execution->cancel_requested_at)
        ->not->toBeNull()
        ->and($execution->cancelled_at)
        ->not->toBeNull()
        ->and($execution->cancellation_reason)
        ->toBe('The user stopped the workflow.');
});

it('cooperatively cancels a running attempt', function (): void {
    $manager = app(ExecutionResilienceManager::class);

    $execution = Execution::factory()->create();

    $attempt = $manager->startAttempt(
        execution: $execution,
        context: aios055AttemptContext(),
    );

    $requested = $manager->requestCancellation(
        execution: $execution,
        reason: 'Stop provider execution.',
    );

    expect($requested)->toBeTrue();

    $confirmed = $manager->confirmCancellation(
        execution: $execution,
    );

    $execution->refresh();
    $attempt->refresh();

    expect($confirmed)
        ->toBeTrue()
        ->and($execution->status)
        ->toBe(ExecutionStatus::Cancelled)
        ->and($attempt->status)
        ->toBe(ExecutionAttemptStatus::Cancelled)
        ->and($attempt->retryable)
        ->toBeNull()
        ->and($attempt->retry_delay_seconds)
        ->toBeNull();

    $confirmedAgain = $manager->confirmCancellation(
        execution: $execution,
    );

    $execution->refresh();
    $attempt->refresh();

    expect($confirmedAgain)
        ->toBeTrue()
        ->and($execution->status)
        ->toBe(ExecutionStatus::Cancelled)
        ->and($attempt->status)
        ->toBe(ExecutionAttemptStatus::Cancelled)
        ->and($attempt->retryable)
        ->toBeNull()
        ->and($attempt->retry_delay_seconds)
        ->toBeNull();
});

it('lets cancellation win over timeout retry', function (): void {
    $manager = app(ExecutionResilienceManager::class);

    $startedAt = CarbonImmutable::parse(
        '2026-07-26 08:00:00 UTC',
    );

    $execution = Execution::factory()->create([
        'retry_limit' => 3,
        'timeout_seconds' => 60,
    ]);

    $attempt = $manager->startAttempt(
        execution: $execution,
        context: aios055AttemptContext(),
        at: $startedAt,
    );

    $manager->requestCancellation(
        execution: $execution,
        reason: 'Cancel before timeout recovery.',
        at: $startedAt->addSeconds(30),
    );

    $manager->processExpiredAttempts(
        at: $startedAt->addSeconds(61),
    );

    $execution->refresh();
    $attempt->refresh();

    expect($execution->status)
        ->toBe(ExecutionStatus::Cancelled)
        ->and($execution->next_attempt_at)
        ->toBeNull()
        ->and($attempt->status)
        ->toBe(ExecutionAttemptStatus::Cancelled)
        ->and($attempt->retryable)
        ->toBeNull()
        ->and($attempt->retry_delay_seconds)
        ->toBeNull();
});

it('calculates deterministic bounded jitter', function (): void {
    $calculator = app(
        DeterministicRetryDelay::class,
    );

    $first = $calculator->seconds(
        executionId: '01K10000000000000000000000',
        nextAttemptNumber: 3,
        baseDelaySeconds: 30,
        maxDelaySeconds: 300,
        jitterPercent: 20,
    );

    $second = $calculator->seconds(
        executionId: '01K10000000000000000000000',
        nextAttemptNumber: 3,
        baseDelaySeconds: 30,
        maxDelaySeconds: 300,
        jitterPercent: 20,
    );

    expect($first)
        ->toBe($second)
        ->toBeGreaterThanOrEqual(48)
        ->toBeLessThanOrEqual(72);
});
