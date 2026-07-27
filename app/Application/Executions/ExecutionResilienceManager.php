<?php

declare(strict_types=1);

namespace App\Application\Executions;

use App\Application\Executions\Data\ExecutionAttemptContext;
use App\Application\Executions\Data\RetryDecision;
use App\Application\Security\RedactSensitiveData;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditEventType;
use App\Domain\Executions\Exceptions\ExecutionLifecycleConflict;
use App\Domain\Executions\ExecutionAttemptStatus;
use App\Domain\Executions\ExecutionStatus;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Owns atomic attempt creation, retry, timeout, and cancellation transitions.
 */
final readonly class ExecutionResilienceManager
{
    /**
     * Inject transaction, retry-delay, and lifecycle-event services.
     */
    public function __construct(
        private TransactionManager $transactions,
        private DeterministicRetryDelay $retryDelay,
        private RecordExecutionLifecycleEvent $events,
        private RedactSensitiveData $redactor,
    ) {}

    /**
     * Create and start the next execution attempt under an execution row lock.
     */
    public function startAttempt(
        Execution $execution,
        ExecutionAttemptContext $context,
        ?CarbonImmutable $at = null,
        ?string $causationId = null,
    ): ExecutionAttempt {
        $startedAt = $at ?? CarbonImmutable::now();

        $attempt = $this->transactions->run(
            function () use (
                $execution,
                $context,
                $startedAt,
                $causationId,
            ): ExecutionAttempt {
                $lockedExecution = $this->lockExecution($execution);

                if (
                    $lockedExecution->status
                    !== ExecutionStatus::Queued
                ) {
                    throw new ExecutionLifecycleConflict(sprintf(
                        'Execution %s must be queued before starting an attempt.',
                        $lockedExecution->id,
                    ));
                }

                if ($lockedExecution->cancel_requested_at !== null) {
                    throw new ExecutionLifecycleConflict(sprintf(
                        'Execution %s has a pending cancellation request.',
                        $lockedExecution->id,
                    ));
                }

                if (
                    $context->requestedReasoningLevel
                    !== $lockedExecution->requested_reasoning_level
                ) {
                    throw new ExecutionLifecycleConflict(
                        'Attempt reasoning does not match the execution request.',
                    );
                }

                $attemptNumber =
                    $lockedExecution->attempt_count + 1;

                $maximumAttempts =
                    $lockedExecution->retry_limit + 1;

                if ($attemptNumber > $maximumAttempts) {
                    throw new ExecutionLifecycleConflict(sprintf(
                        'Execution %s has exhausted its allowed attempts.',
                        $lockedExecution->id,
                    ));
                }

                $attempt = new ExecutionAttempt;

                $attempt->forceFill(array_merge(
                    [
                        'execution_id' => $lockedExecution->id,
                        'attempt_number' => $attemptNumber,
                        'status' => ExecutionAttemptStatus::Running,
                        'started_at' => $startedAt,
                        'heartbeat_at' => $startedAt,
                        'deadline_at' => $startedAt->addSeconds(
                            $lockedExecution->timeout_seconds,
                        ),
                    ],
                    $context->toPersistenceAttributes(),
                ));

                $attempt->save();

                $lockedExecution->forceFill([
                    'status' => ExecutionStatus::Running,
                    'attempt_count' => $attemptNumber,
                    'started_at' => $lockedExecution->started_at ?? $startedAt,
                    'finished_at' => null,
                    'next_attempt_at' => null,
                ])->save();

                $this->events->record(
                    execution: $lockedExecution,
                    eventName: 'execution.attempt.started',
                    auditEventType: AuditEventType::ExecutionAttemptStarted,
                    occurredAt: $startedAt,
                    payload: [
                        'deadline_at' => $attempt->deadline_at?->toISOString(),
                    ],
                    attempt: $attempt,
                    causationId: $causationId,
                );

                return $attempt;
            },
        );

        $execution->refresh();

        return $attempt;
    }

    /**
     * Record liveness for a running attempt.
     */
    public function heartbeatAttempt(
        ExecutionAttempt $attempt,
        ?CarbonImmutable $at = null,
    ): bool {
        $heartbeatAt = $at ?? CarbonImmutable::now();
        $attempt->loadMissing('execution');

        $changed = $this->transactions->run(
            function () use (
                $attempt,
                $heartbeatAt,
            ): bool {
                $lockedExecution = $this->lockExecution(
                    $attempt->execution,
                );

                $lockedAttempt = $this->lockAttempt(
                    execution: $lockedExecution,
                    attemptId: $attempt->id,
                );

                if (
                    $lockedExecution->status
                    !== ExecutionStatus::Running
                    || $lockedExecution->cancel_requested_at !== null
                    || $lockedAttempt->status
                    !== ExecutionAttemptStatus::Running
                ) {
                    return false;
                }

                $lockedAttempt->forceFill([
                    'heartbeat_at' => $heartbeatAt,
                ])->save();

                return true;
            },
        );

        if ($changed) {
            $attempt->refresh();
        }

        return $changed;
    }

    /**
     * Complete one active attempt and its logical execution.
     */
    public function completeAttempt(
        ExecutionAttempt $attempt,
        ?CarbonImmutable $at = null,
        ?string $causationId = null,
    ): bool {
        $completedAt = $at ?? CarbonImmutable::now();
        $attempt->loadMissing('execution');

        $changed = $this->transactions->run(
            function () use (
                $attempt,
                $completedAt,
                $causationId,
            ): bool {
                $lockedExecution = $this->lockExecution(
                    $attempt->execution,
                );

                $lockedAttempt = $this->lockAttempt(
                    execution: $lockedExecution,
                    attemptId: $attempt->id,
                );

                if (
                    $lockedExecution->status
                    === ExecutionStatus::Completed
                    && $lockedAttempt->status
                    === ExecutionAttemptStatus::Completed
                ) {
                    return false;
                }

                $this->assertRunningPair(
                    execution: $lockedExecution,
                    attempt: $lockedAttempt,
                );

                /*
                 * Cancellation wins over a racing completion callback. A
                 * provider result received after cancellation is not permitted
                 * to resurrect the execution.
                 */
                if ($lockedExecution->cancel_requested_at !== null) {
                    $this->cancelLocked(
                        execution: $lockedExecution,
                        attempt: $lockedAttempt,
                        cancelledAt: $completedAt,
                        actor: null,
                        causationId: $causationId,
                    );

                    return false;
                }

                $lockedAttempt->forceFill([
                    'status' => ExecutionAttemptStatus::Completed,
                    'finished_at' => $completedAt,
                    'heartbeat_at' => $completedAt,
                ])->save();

                $lockedExecution->forceFill([
                    'status' => ExecutionStatus::Completed,
                    'finished_at' => $completedAt,
                    'next_attempt_at' => null,
                ])->save();

                $this->events->record(
                    execution: $lockedExecution,
                    eventName: 'execution.attempt.completed',
                    auditEventType: AuditEventType::ExecutionAttemptCompleted,
                    occurredAt: $completedAt,
                    attempt: $lockedAttempt,
                    causationId: $causationId,
                );

                return true;
            },
        );

        $attempt->refresh();
        $attempt->execution->refresh();

        return $changed;
    }

    /**
     * Record a provider failure and determine whether another attempt is due.
     */
    public function failAttempt(
        ExecutionAttempt $attempt,
        string $errorCode,
        string $errorMessage,
        bool $retryable,
        ?CarbonImmutable $at = null,
        ?string $causationId = null,
    ): RetryDecision {
        return $this->finishFailedAttempt(
            attempt: $attempt,
            attemptStatus: ExecutionAttemptStatus::Failed,
            errorCode: $this->normalizeErrorCode($errorCode),
            errorMessage: $this->normalizeMessage(
                $this->redactor->message($errorMessage),
            ),
            retryable: $retryable,
            finishedAt: $at ?? CarbonImmutable::now(),
            causationId: $causationId,
        );
    }

    /**
     * Persist a deterministic planning blocker without scheduling a retry.
     */
    public function blockAttempt(
        ExecutionAttempt $attempt,
        string $errorCode,
        string $errorMessage,
        ?CarbonImmutable $at = null,
        ?string $causationId = null,
    ): bool {
        $blockedAt = $at ?? CarbonImmutable::now();
        $attempt->loadMissing('execution');

        $changed = $this->transactions->run(function () use ($attempt, $errorCode, $errorMessage, $blockedAt, $causationId): bool {
            $lockedExecution = $this->lockExecution($attempt->execution);
            $lockedAttempt = $this->lockAttempt($lockedExecution, $attempt->id);

            if ($lockedExecution->status === ExecutionStatus::Blocked && $lockedAttempt->status->isTerminal()) {
                return false;
            }

            $this->assertRunningPair($lockedExecution, $lockedAttempt);
            $normalizedCode = $this->normalizeErrorCode($errorCode);

            $lockedAttempt->forceFill([
                'status' => ExecutionAttemptStatus::Failed,
                'retryable' => false,
                'error_code' => $normalizedCode,
                'error_message' => $this->normalizeMessage($this->redactor->message($errorMessage)),
                'finished_at' => $blockedAt,
                'heartbeat_at' => $blockedAt,
            ])->save();

            $lockedExecution->forceFill([
                'status' => ExecutionStatus::Blocked,
                'finished_at' => $blockedAt,
                'next_attempt_at' => null,
            ])->save();

            $this->events->record(
                execution: $lockedExecution,
                eventName: 'execution.blocked',
                auditEventType: AuditEventType::ExecutionBlocked,
                occurredAt: $blockedAt,
                payload: ['error_code' => $normalizedCode, 'retryable' => false],
                attempt: $lockedAttempt,
                causationId: $causationId,
            );

            return true;
        });

        $attempt->refresh();
        $attempt->execution->refresh();

        return $changed;
    }

    /**
     * Mark one running attempt as timed out.
     */
    public function timeOutAttempt(
        ExecutionAttempt $attempt,
        ?CarbonImmutable $at = null,
        ?string $causationId = null,
    ): RetryDecision {
        return $this->finishFailedAttempt(
            attempt: $attempt,
            attemptStatus: ExecutionAttemptStatus::TimedOut,
            errorCode: 'execution.timeout',
            errorMessage: 'The execution attempt exceeded its configured deadline.',
            retryable: true,
            finishedAt: $at ?? CarbonImmutable::now(),
            causationId: $causationId,
        );
    }

    /**
     * Request cancellation, cancelling immediately when no attempt is active.
     */
    public function requestCancellation(
        Execution $execution,
        ?string $reason = null,
        ?User $actor = null,
        ?CarbonImmutable $at = null,
        ?string $causationId = null,
    ): bool {
        $requestedAt = $at ?? CarbonImmutable::now();
        $normalizedReason =
            $this->normalizeOptionalMessage($reason);

        $changed = $this->transactions->run(
            function () use (
                $execution,
                $normalizedReason,
                $actor,
                $requestedAt,
                $causationId,
            ): bool {
                $lockedExecution = $this->lockExecution($execution);

                if ($lockedExecution->status->isTerminal()) {
                    return false;
                }

                if (
                    $lockedExecution->cancel_requested_at !== null
                ) {
                    return false;
                }

                $lockedExecution->forceFill([
                    'cancel_requested_at' => $requestedAt,
                    'cancellation_reason' => $normalizedReason,
                ])->save();

                $this->events->record(
                    execution: $lockedExecution,
                    eventName: 'execution.cancellation_requested',
                    auditEventType: AuditEventType::ExecutionCancellationRequested,
                    occurredAt: $requestedAt,
                    payload: [
                        'reason_present' => $normalizedReason !== null,
                    ],
                    actor: $actor,
                    causationId: $causationId,
                );

                if (
                    $lockedExecution->status
                    !== ExecutionStatus::Running
                ) {
                    $this->cancelLocked(
                        execution: $lockedExecution,
                        attempt: null,
                        cancelledAt: $requestedAt,
                        actor: $actor,
                        causationId: $causationId,
                    );
                }

                return true;
            },
        );

        $execution->refresh();

        return $changed;
    }

    /**
     * Confirm that an active provider attempt stopped after cancellation.
     */
    public function confirmCancellation(
        Execution $execution,
        ?User $actor = null,
        ?CarbonImmutable $at = null,
        ?string $causationId = null,
    ): bool {
        $cancelledAt = $at ?? CarbonImmutable::now();

        $changed = $this->transactions->run(
            function () use (
                $execution,
                $actor,
                $cancelledAt,
                $causationId,
            ): bool {
                $lockedExecution = $this->lockExecution($execution);

                if (
                    $lockedExecution->status
                    === ExecutionStatus::Cancelled
                ) {
                    return true;
                }

                if ($lockedExecution->status->isTerminal()) {
                    return false;
                }

                if (
                    $lockedExecution->cancel_requested_at === null
                ) {
                    throw new ExecutionLifecycleConflict(sprintf(
                        'Execution %s has no cancellation request.',
                        $lockedExecution->id,
                    ));
                }

                /** @var ExecutionAttempt|null $runningAttempt */
                $runningAttempt = ExecutionAttempt::query()
                    ->where(
                        'execution_id',
                        $lockedExecution->id,
                    )
                    ->where(
                        'status',
                        ExecutionAttemptStatus::Running,
                    )
                    ->orderByDesc('attempt_number')
                    ->lockForUpdate()
                    ->first();

                $this->cancelLocked(
                    execution: $lockedExecution,
                    attempt: $runningAttempt,
                    cancelledAt: $cancelledAt,
                    actor: $actor,
                    causationId: $causationId,
                );

                return true;
            },
        );

        $execution->refresh();

        return $changed;
    }

    /**
     * Time out one bounded batch of attempts whose deadlines elapsed.
     */
    public function processExpiredAttempts(
        ?CarbonImmutable $at = null,
        int $limit = 200,
    ): int {
        $this->assertBatchLimit($limit);

        $timeoutAt = $at ?? CarbonImmutable::now();

        /** @var list<int> $attemptIds */
        $attemptIds = ExecutionAttempt::query()
            ->expiredRunning($timeoutAt)
            ->orderBy('deadline_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $changedCount = 0;

        foreach ($attemptIds as $attemptId) {
            /** @var ExecutionAttempt|null $attempt */
            $attempt = ExecutionAttempt::query()
                ->with('execution')
                ->find($attemptId);

            if ($attempt === null) {
                continue;
            }

            $decision = $this->timeOutAttempt(
                attempt: $attempt,
                at: $timeoutAt,
            );

            if ($decision->stateChanged) {
                $changedCount++;
            }
        }

        return $changedCount;
    }

    /**
     * Promote one bounded batch of due retries back to queued state.
     */
    public function releaseDueRetries(
        ?CarbonImmutable $at = null,
        int $limit = 200,
    ): int {
        $this->assertBatchLimit($limit);

        $releaseAt = $at ?? CarbonImmutable::now();

        /** @var list<string> $executionIds */
        $executionIds = Execution::query()
            ->dueRetry($releaseAt)
            ->orderBy('next_attempt_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $releasedCount = 0;

        foreach ($executionIds as $executionId) {
            $released = $this->transactions->run(
                function () use (
                    $executionId,
                    $releaseAt,
                ): bool {
                    /** @var Execution|null $execution */
                    $execution = Execution::query()
                        ->whereKey($executionId)
                        ->lockForUpdate()
                        ->first();

                    if (
                        $execution === null
                        || $execution->status
                        !== ExecutionStatus::RetryScheduled
                        || $execution->next_attempt_at === null
                        || $execution->next_attempt_at->isAfter(
                            $releaseAt,
                        )
                    ) {
                        return false;
                    }

                    if (
                        $execution->cancel_requested_at !== null
                    ) {
                        $this->cancelLocked(
                            execution: $execution,
                            attempt: null,
                            cancelledAt: $releaseAt,
                            actor: null,
                            causationId: null,
                        );

                        return true;
                    }

                    $execution->forceFill([
                        'status' => ExecutionStatus::Queued,
                        'next_attempt_at' => null,
                        'finished_at' => null,
                    ])->save();

                    $this->events->record(
                        execution: $execution,
                        eventName: 'execution.retry_released',
                        auditEventType: AuditEventType::ExecutionRetryReleased,
                        occurredAt: $releaseAt,
                    );

                    return true;
                },
            );

            if ($released) {
                $releasedCount++;
            }
        }

        return $releasedCount;
    }

    /**
     * Finish a failed or timed-out attempt and resolve its next state.
     */
    private function finishFailedAttempt(
        ExecutionAttempt $attempt,
        ExecutionAttemptStatus $attemptStatus,
        string $errorCode,
        string $errorMessage,
        bool $retryable,
        CarbonImmutable $finishedAt,
        ?string $causationId,
    ): RetryDecision {
        $attempt->loadMissing('execution');

        $decision = $this->transactions->run(
            function () use (
                $attempt,
                $attemptStatus,
                $errorCode,
                $errorMessage,
                $retryable,
                $finishedAt,
                $causationId,
            ): RetryDecision {
                $lockedExecution = $this->lockExecution(
                    $attempt->execution,
                );

                $lockedAttempt = $this->lockAttempt(
                    execution: $lockedExecution,
                    attemptId: $attempt->id,
                );

                if ($lockedAttempt->status->isTerminal()) {
                    return $this->decisionFromPersistedState(
                        execution: $lockedExecution,
                        attempt: $lockedAttempt,
                    );
                }

                $this->assertRunningPair(
                    execution: $lockedExecution,
                    attempt: $lockedAttempt,
                );

                /*
                 * A timeout discovered after cancellation was requested ends in
                 * cancellation rather than scheduling fresh work.
                 */
                if ($lockedExecution->cancel_requested_at !== null) {
                    $this->cancelLocked(
                        execution: $lockedExecution,
                        attempt: $lockedAttempt,
                        cancelledAt: $finishedAt,
                        actor: null,
                        causationId: $causationId,
                    );

                    return RetryDecision::terminal(
                        stateChanged: true,
                        status: ExecutionStatus::Cancelled,
                    );
                }

                $lockedAttempt->forceFill([
                    'status' => $attemptStatus,
                    'retryable' => $retryable,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage,
                    'finished_at' => $finishedAt,
                    'heartbeat_at' => $finishedAt,
                ])->save();

                $attemptAuditEvent = match ($attemptStatus) {
                    ExecutionAttemptStatus::TimedOut => AuditEventType::ExecutionAttemptTimedOut,
                    default => AuditEventType::ExecutionAttemptFailed,
                };

                $attemptEventName = match ($attemptStatus) {
                    ExecutionAttemptStatus::TimedOut => 'execution.attempt.timed_out',
                    default => 'execution.attempt.failed',
                };

                $this->events->record(
                    execution: $lockedExecution,
                    eventName: $attemptEventName,
                    auditEventType: $attemptAuditEvent,
                    occurredAt: $finishedAt,
                    payload: [
                        'error_code' => $errorCode,
                        'retryable' => $retryable,
                    ],
                    attempt: $lockedAttempt,
                    causationId: $causationId,
                );

                /*
                 * retry_limit represents retries after the initial attempt.
                 * attempt_count 1 with retry_limit 3 may therefore schedule
                 * attempts 2, 3, and 4.
                 */
                $canRetry = $retryable
                    && $lockedExecution->attempt_count
                    <= $lockedExecution->retry_limit;

                if (! $canRetry) {
                    $lockedExecution->forceFill([
                        'status' => ExecutionStatus::Failed,
                        'finished_at' => $finishedAt,
                        'next_attempt_at' => null,
                    ])->save();

                    $this->events->record(
                        execution: $lockedExecution,
                        eventName: 'execution.failed',
                        auditEventType: AuditEventType::ExecutionFailed,
                        occurredAt: $finishedAt,
                        payload: [
                            'error_code' => $errorCode,
                            'retry_exhausted' => $retryable,
                        ],
                        attempt: $lockedAttempt,
                        causationId: $causationId,
                    );

                    return RetryDecision::terminal(
                        stateChanged: true,
                        status: ExecutionStatus::Failed,
                    );
                }

                $nextAttemptNumber =
                    $lockedExecution->attempt_count + 1;

                $delaySeconds = $this->retryDelay->seconds(
                    executionId: $lockedExecution->id,
                    nextAttemptNumber: $nextAttemptNumber,
                    baseDelaySeconds: $lockedExecution
                        ->retry_base_delay_seconds,
                    maxDelaySeconds: $lockedExecution
                        ->retry_max_delay_seconds,
                    jitterPercent: $lockedExecution
                        ->retry_jitter_percent,
                );

                $nextAttemptAt = $finishedAt->addSeconds(
                    $delaySeconds,
                );

                $lockedAttempt->forceFill([
                    'retry_delay_seconds' => $delaySeconds,
                ])->save();

                $lockedExecution->forceFill([
                    'status' => ExecutionStatus::RetryScheduled,
                    'next_attempt_at' => $nextAttemptAt,
                    'finished_at' => null,
                ])->save();

                $this->events->record(
                    execution: $lockedExecution,
                    eventName: 'execution.retry_scheduled',
                    auditEventType: AuditEventType::ExecutionRetryScheduled,
                    occurredAt: $finishedAt,
                    payload: [
                        'next_attempt_number' => $nextAttemptNumber,
                        'delay_seconds' => $delaySeconds,
                        'next_attempt_at' => $nextAttemptAt->toISOString(),
                    ],
                    attempt: $lockedAttempt,
                    causationId: $causationId,
                );

                return RetryDecision::scheduled(
                    stateChanged: true,
                    delaySeconds: $delaySeconds,
                    nextAttemptAt: $nextAttemptAt,
                );
            },
        );

        $attempt->refresh();
        $attempt->execution->refresh();

        return $decision;
    }

    /**
     * Cancel an execution and its active attempt inside an existing transaction.
     */
    private function cancelLocked(
        Execution $execution,
        ?ExecutionAttempt $attempt,
        CarbonImmutable $cancelledAt,
        ?User $actor,
        ?string $causationId,
    ): void {
        if (
            $attempt !== null
            && ! $attempt->status->isTerminal()
        ) {
            $attempt->forceFill([
                'status' => ExecutionAttemptStatus::Cancelled,
                'retryable' => null,
                'retry_delay_seconds' => null,
                'finished_at' => $cancelledAt,
                'heartbeat_at' => $cancelledAt,
            ])->save();
        }

        $execution->forceFill([
            'status' => ExecutionStatus::Cancelled,
            'cancelled_at' => $cancelledAt,
            'finished_at' => $cancelledAt,
            'next_attempt_at' => null,
        ])->save();

        $this->events->record(
            execution: $execution,
            eventName: 'execution.cancelled',
            auditEventType: AuditEventType::ExecutionCancelled,
            occurredAt: $cancelledAt,
            attempt: $attempt,
            actor: $actor,
            causationId: $causationId,
        );
    }

    /**
     * Lock the authoritative execution row with explicit project ownership.
     */
    private function lockExecution(
        Execution $execution,
    ): Execution {
        /** @var Execution $lockedExecution */
        $lockedExecution = Execution::query()
            ->forProject($execution->project_id)
            ->whereKey($execution->id)
            ->lockForUpdate()
            ->firstOrFail();

        return $lockedExecution;
    }

    /**
     * Lock one attempt that belongs to the already locked execution.
     */
    private function lockAttempt(
        Execution $execution,
        int $attemptId,
    ): ExecutionAttempt {
        /** @var ExecutionAttempt $attempt */
        $attempt = ExecutionAttempt::query()
            ->where('execution_id', $execution->id)
            ->whereKey($attemptId)
            ->lockForUpdate()
            ->firstOrFail();

        return $attempt;
    }

    /**
     * Ensure an execution and attempt are both currently running.
     */
    private function assertRunningPair(
        Execution $execution,
        ExecutionAttempt $attempt,
    ): void {
        if (
            $execution->status !== ExecutionStatus::Running
            || $attempt->status
            !== ExecutionAttemptStatus::Running
        ) {
            throw new ExecutionLifecycleConflict(sprintf(
                'Execution %s and attempt %d are not both running.',
                $execution->id,
                $attempt->attempt_number,
            ));
        }
    }

    /**
     * Return the current decision for an idempotently repeated callback.
     */
    private function decisionFromPersistedState(
        Execution $execution,
        ExecutionAttempt $attempt,
    ): RetryDecision {
        if (
            $execution->status
            === ExecutionStatus::RetryScheduled
            && $execution->next_attempt_at !== null
            && $attempt->retry_delay_seconds !== null
        ) {
            return RetryDecision::scheduled(
                stateChanged: false,
                delaySeconds: $attempt->retry_delay_seconds,
                nextAttemptAt: $execution->next_attempt_at,
            );
        }

        if ($execution->status->isTerminal()) {
            return RetryDecision::terminal(
                stateChanged: false,
                status: $execution->status,
            );
        }

        throw new ExecutionLifecycleConflict(
            'The persisted attempt outcome does not match the execution state.',
        );
    }

    /**
     * Normalize a machine-readable error code.
     */
    private function normalizeErrorCode(
        string $errorCode,
    ): string {
        $normalized = strtolower(trim($errorCode));

        if (
            preg_match(
                '/\A[a-z][a-z0-9_.-]{1,119}\z/D',
                $normalized,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'The execution error code is invalid.',
            );
        }

        return $normalized;
    }

    /**
     * Normalize required redacted text.
     */
    private function normalizeMessage(
        string $message,
    ): string {
        $normalized = trim($message);

        if ($normalized === '') {
            throw new InvalidArgumentException(
                'The execution error message cannot be empty.',
            );
        }

        return Str::limit(
            $normalized,
            2_000,
            '',
        );
    }

    /**
     * Normalize optional cancellation text.
     */
    private function normalizeOptionalMessage(
        ?string $message,
    ): ?string {
        if ($message === null) {
            return null;
        }

        $normalized = trim($message);

        if ($normalized === '') {
            return null;
        }

        return Str::limit(
            $normalized,
            2_000,
            '',
        );
    }

    /**
     * Keep recovery passes bounded.
     */
    private function assertBatchLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException(
                'The execution recovery limit must be between 1 and 1,000.',
            );
        }
    }
}
